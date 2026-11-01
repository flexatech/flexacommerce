<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\GiftCards;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\GiftCards\AppliedGiftCard;
use Flexa\Commerce\GiftCards\GiftCard;
use Flexa\Commerce\GiftCards\GiftCardActivity;
use Flexa\Commerce\GiftCards\GiftCardService;
use Flexa\Commerce\GiftCards\GiftCardUnusable;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Privacy\Privacy;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;
use Flexa\Commerce\Tests\Integration\Payments\FakeGateway;

/**
 * Gift cards (ADR-069): cards pay at checkout in full or in part, a refund
 * or a cancel puts the money back on the card, a bought card is issued
 * once with the value paid and emailed on its day, and the admin routes
 * and privacy tools work on the cards. Balances commit, so the rows are
 * deleted after the rollback.
 */
final class GiftCardsTest extends OrderTestCase {
	private const BILLING = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Infinite Loop',
		'city'       => 'Cupertino',
		'state'      => 'CA',
		'postcode'   => '95014',
		'country'    => 'US',
		'email'      => 'ada@example.com',
	];

	private string $rate_id = '';

	public function set_up(): void {
		parent::set_up();
		$gateways = Plugin::instance()->payments()->service()->gateways();
		if ( ! $gateways->has( FakeGateway::ID ) ) {
			$gateways->register( FakeGateway::ID, static fn (): PaymentGateway => new FakeGateway() );
		}
		foreach ( [ 'cod', FakeGateway::ID ] as $id ) {
			$gateway = $gateways->get( $id );
			self::assertNotNull( $gateway );
			$gateways->settings()->save( $gateway, [ 'enabled' => true ] );
		}
		FakeGateway::$payment_mode = 'redirect';
		$zones                     = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		$this->rate_id             = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
		reset_phpmailer_instance();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( [ 'cod', FakeGateway::ID ] as $id ) {
			delete_option( GatewaySettings::PREFIX . $id );
		}
		Settings::update( [ 'gift_card_expiry_months' => 0 ] );
		wp_set_current_user( 0 );
		parent::tear_down();
		delete_option( ZoneRepository::OPTION );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'gift_card_activity' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'gift_cards' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_a_card_pays_the_whole_order_and_keeps_the_rest(): void {
		$card     = $this->card( 5000 );
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000, 5 ), 2 );
		$browser->cart()->apply_coupon( 0, strtolower( GiftCard::format( $card->code ) ) );

		$form = $checkout->form_state( 0 );
		self::assertSame( 2500, $form['totals']['total']['amount'] );
		self::assertSame( 2500, $form['totals']['gift_cards']['amount'] );
		self::assertSame( 0, $form['totals']['due']['amount'] );
		self::assertSame( [], $form['payment_methods'], 'Nothing is left to pay, so no method is asked for.' );
		self::assertSame( GiftCard::mask( $card->code ), $form['gift_cards'][0]['label'] );

		$result = $checkout->place_order(
			$this->request(
				$form['checkout_token'],
				[
					'payment_method' => '',
					'expected_total' => 0,
				]
			)
		);
		$order  = $result->order;
		self::assertSame( OrderStatus::PROCESSING, $order->status );
		self::assertSame( GiftCardService::GATEWAY, $order->payment_method );
		self::assertSame( 0, $order->balance_due() );

		$after = $this->cards()->repository()->find( $card->id );
		self::assertSame( 2500, $after?->balance );
		$activity = $this->cards()->repository()->activity( $card->id );
		self::assertSame( [ GiftCardActivity::REDEEMED, GiftCardActivity::ISSUED ], array_map( static fn ( GiftCardActivity $a ): string => $a->type, $activity ) );
		self::assertSame( -2500, $activity[0]->amount );
		self::assertSame( $order->id, $activity[0]->order_id );

		$payments = Plugin::instance()->payments()->service()->for_order( $order->id );
		self::assertCount( 1, $payments );
		self::assertSame( [ GiftCardService::GATEWAY, Transaction::SUCCEEDED, 2500 ], [ $payments[0]->gateway, $payments[0]->status, $payments[0]->amount ] );
	}

	public function test_a_small_card_pays_part_and_the_gateway_takes_the_rest(): void {
		$card     = $this->card( 1000 );
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000, 5 ), 2 );
		$browser->cart()->apply_coupon( 0, $card->code );

		$form = $checkout->form_state( 0 );
		self::assertSame( 1500, $form['totals']['due']['amount'] );
		self::assertContains( 'cod', array_column( $form['payment_methods'], 'id' ) );

		$this->assert_checkout_error( 'expected_total', $checkout, $this->request( $form['checkout_token'], [ 'expected_total' => 2500 ] ) );

		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 1500 ] ) )->order;
		self::assertSame( 'cod', $order->payment_method );
		self::assertSame( 1500, $order->balance_due() );
		self::assertSame( 0, $this->cards()->repository()->find( $card->id )?->balance );
	}

	public function test_one_card_pays_two_orders_and_refunds_each_back(): void {
		$card   = $this->card( 5000 );
		$first  = $this->paid_by_card( $card, 1000 );
		$second = $this->paid_by_card( $card, 1000 );
		self::assertSame( 2000, $this->cards()->repository()->find( $card->id )?->balance );
		self::assertSame( OrderStatus::PROCESSING, $second->status );

		Plugin::instance()->payments()->service()->refund( $second->id, 300, [], '', true, TransitionContext::admin() );
		Plugin::instance()->payments()->service()->refund( $first->id, 200, [], '', true, TransitionContext::admin() );
		self::assertSame( 2500, $this->cards()->repository()->find( $card->id )?->balance );
	}

	public function test_a_card_spent_since_the_quote_is_not_charged_twice(): void {
		$card     = $this->card( 5000 );
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$product  = $this->simple( 1000, 5 );
		$this->add( $browser, $product, 1 );
		$browser->cart()->apply_coupon( 0, $card->code );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$this->cards()->adjust( $card->id, -4900, 'Spent in the shop', 0 );
		$this->assert_checkout_refused(
			$checkout,
			$this->request(
				$token,
				[
					'payment_method' => '',
					'expected_total' => 0,
				]
			)
		);
		self::assertSame( 5, $this->stock_of( $product ), 'No stock is taken for the refused order.' );
		self::assertSame( 100, $this->cards()->repository()->find( $card->id )?->balance );

		// The race inside the transaction: the card is short when the order is written.
		$order = $this->place( [ $product => 1 ] );
		try {
			$this->cards()->redeem( $order, [ new AppliedGiftCard( $card->id, $card->code, 1500, 5000 ) ], TransitionContext::system() );
			self::fail( 'A short card was charged.' );
		} catch ( GiftCardUnusable $e ) {
			self::assertStringContainsString( GiftCard::mask( $card->code ), $e->getMessage() );
		}
		self::assertSame( 100, $this->cards()->repository()->find( $card->id )?->balance );
	}

	public function test_codes_that_cannot_pay_are_refused_in_the_cart(): void {
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$disabled = $this->card( 1000 );
		$this->cards()->update( $disabled->id, [ 'status' => GiftCard::DISABLED ] );
		$expired = $this->card( 1000, [ 'expires_at' => '2020-01-01 00:00:00' ] );

		self::assertSame( 'This gift card is disabled.', $this->cart_error( $browser, $disabled->code ) );
		self::assertSame( 'This gift card has expired.', $this->cart_error( $browser, $expired->code ) );

		$card = $this->card( 1000 );
		$browser->cart()->apply_coupon( 0, $card->code );
		self::assertSame( 'This gift card is already in your cart.', $this->cart_error( $browser, $card->code ) );
		$state = $browser->cart()->remove_coupon( 0, GiftCard::format( $card->code ) );
		self::assertSame( [], $state->cart->gift_cards );
	}

	public function test_a_refund_goes_back_to_the_card_once(): void {
		$card  = $this->card( 5000 );
		$order = $this->paid_by_card( $card, 1000 );

		$refund = Plugin::instance()->payments()->service()->refund( $order->id, 600, [], 'Too big', true, TransitionContext::admin() );
		self::assertSame( Transaction::SUCCEEDED, $refund->status );
		self::assertSame( 3500 + 600, $this->cards()->repository()->find( $card->id )?->balance );

		$this->cards()->credit( $card->id, 600, $refund->id );
		self::assertSame( 4100, $this->cards()->repository()->find( $card->id )?->balance, 'A retried credit for the same refund adds nothing.' );
	}

	public function test_a_cancelled_order_gives_the_card_payment_back(): void {
		$card     = $this->card( 1000 );
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000, 5 ), 2 );
		$browser->cart()->apply_coupon( 0, $card->code );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$order = $checkout->place_order( $this->request( $token, [ 'payment_method' => FakeGateway::ID ] ) )->order;
		self::assertSame( OrderStatus::PENDING, $order->status, 'The rest waits on the gateway.' );
		self::assertSame( 0, $this->cards()->repository()->find( $card->id )?->balance );

		$this->orders()->transition( $order->id, OrderStatus::CANCELLED, TransitionContext::system() );
		self::assertSame( 1000, $this->cards()->repository()->find( $card->id )?->balance );
		self::assertSame( GiftCardActivity::CREDITED, $this->cards()->repository()->activity( $card->id )[0]->type );
	}

	public function test_a_bought_card_is_issued_once_at_the_value_paid_and_emailed_on_its_day(): void {
		Settings::update( [ 'gift_card_expiry_months' => 12 ] );
		$product  = $this->gift_product( 2500 );
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$tomorrow = ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
		$browser->cart()->add(
			0,
			$product,
			$this->service()->get( $product )->base->id,
			2,
			[],
			[],
			[
				'to'      => 'Grace@Example.com',
				'name'    => 'Grace',
				'from'    => 'Ada',
				'message' => 'Happy birthday',
				'date'    => $tomorrow,
			]
		);
		$form = $checkout->form_state( 0 );
		self::assertFalse( $form['needs_shipping'], 'A gift card is virtual.' );

		$order = $checkout->place_order(
			$this->request(
				$form['checkout_token'],
				[
					'shipping_rate'  => '',
					'payment_method' => FakeGateway::ID,
				]
			)
		)->order;
		self::assertSame( [], $this->cards()->repository()->for_order( $order->id ), 'Nothing is issued before payment.' );

		$this->orders()->record_payment( $order->id, $order->total, new TransitionContext( 'gateway' ) );
		$this->orders()->transition( $order->id, OrderStatus::COMPLETED, TransitionContext::admin() );
		$cards = $this->cards()->repository()->for_order( $order->id );
		self::assertCount( 2, $cards, 'One card per unit, and a second status change makes no more.' );
		self::assertSame( [ 2500, 2500 ], [ $cards[0]->balance, $cards[1]->initial_amount ] );
		self::assertSame( [ 'grace@example.com', 'Grace', 'Ada', 'Happy birthday' ], [ $cards[0]->recipient_email, $cards[0]->recipient_name, $cards[0]->sender_name, $cards[0]->message ] );
		self::assertNotNull( $cards[0]->deliver_at );
		self::assertNotNull( $cards[0]->expires_at );
		self::assertSame( 0, $this->gift_mails(), 'The email waits for its day.' );

		self::assertSame( 0, $this->cards()->deliver_due() );
		foreach ( $cards as $card ) {
			$this->cards()->repository()->update( $card->id, [ 'deliver_at' => '2020-01-01 00:00:00' ], gmdate( 'Y-m-d H:i:s' ) );
		}
		self::assertSame( 2, $this->cards()->deliver_due() );
		self::assertSame( 2, $this->gift_mails() );
		self::assertSame( 0, $this->cards()->deliver_due(), 'A delivered card is not sent again.' );

		$this->orders()->transition( $order->id, OrderStatus::REFUNDED, TransitionContext::admin() );
		self::assertSame( GiftCard::DISABLED, $this->cards()->repository()->find( $cards[0]->id )?->status, 'A refunded order turns off the cards it sold.' );
	}

	public function test_a_card_without_a_recipient_goes_to_the_buyer_now(): void {
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->gift_product( 1000 ), 1 );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$order = $checkout->place_order( $this->request( $token, [ 'shipping_rate' => '' ] ) )->order;
		self::assertSame( OrderStatus::PROCESSING, $order->status );
		$cards = $this->cards()->repository()->for_order( $order->id );
		self::assertCount( 1, $cards );
		self::assertSame( [ 'ada@example.com', 'Ada Lovelace' ], [ $cards[0]->recipient_email, $cards[0]->sender_name ] );
		self::assertNull( $cards[0]->deliver_at );
		self::assertNotNull( $cards[0]->delivered_at );
		self::assertSame( 1, $this->gift_mails() );
	}

	public function test_every_amount_of_a_variable_gift_card_issues_cards(): void {
		[ $id ] = $this->sized();
		$this->save( $id, [ 'base' => [ 'gift_card' => true ] ] );
		foreach ( $this->service()->get( $id )->variations as $variation ) {
			self::assertTrue( $variation->gift_card );
			self::assertTrue( $variation->virtual );
		}

		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$browser->cart()->add( 0, $id, 0, 1, [ 'l:size' => 'm' ], [], [ 'to' => 'grace@example.com' ] );
		$token = $checkout->form_state( 0 )['checkout_token'];
		$order = $checkout->place_order( $this->request( $token, [ 'shipping_rate' => '' ] ) )->order;

		$cards = $this->cards()->repository()->for_order( $order->id );
		self::assertCount( 1, $cards );
		self::assertSame( [ 2000, 'grace@example.com' ], [ $cards[0]->balance, $cards[0]->recipient_email ] );

		$this->save( $id, [ 'base' => [ 'gift_card' => false ] ] );
		self::assertFalse( $this->service()->get( $id )->variations[0]->gift_card, 'Turned off, the variations follow.' );
	}

	public function test_the_admin_routes_make_edit_adjust_and_send_cards(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'GET', '/admin/gift-cards' )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/gift-cards', [ 'amount' => 100 ] )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertSame(
			400,
			$this->send(
				'POST',
				'/admin/gift-cards',
				[
					'amount'          => 100,
					'recipient_email' => 'nope',
				]
			)->get_status()
		);
		$made = $this->send(
			'POST',
			'/admin/gift-cards',
			[
				'amount'          => 2000,
				'recipient_email' => 'grace@example.com',
				'recipient_name'  => 'Grace',
				'expires_on'      => '2099-12-31',
				'note'            => 'Goodwill',
			]
		);
		self::assertSame( 201, $made->get_status() );
		$card = $made->get_data();
		self::assertSame( [ 2000, 'active', '2099-12-31' ], [ $card['balance'], $card['state'], $card['expires_on'] ] );
		self::assertNotNull( $card['delivered_at'], 'Sent at once.' );
		self::assertSame( 'Goodwill', $card['activity'][0]['note'] );
		self::assertSame( 1, $this->gift_mails() );

		$list = $this->send( 'GET', '/admin/gift-cards', [ 'search' => 'grace' ] )->get_data();
		self::assertSame( 1, $list['total'] );
		self::assertSame( 1, $this->send( 'GET', '/admin/gift-cards', [ 'search' => substr( $card['code'], -4 ) ] )->get_data()['total'] );
		self::assertSame( 0, $this->send( 'GET', '/admin/gift-cards', [ 'status' => 'used' ] )->get_data()['total'] );

		$id = (int) $card['id'];
		self::assertSame( 400, $this->send( 'POST', "/admin/gift-cards/{$id}/adjust", [ 'amount' => -5000 ] )->get_status() );
		$adjusted = $this->send(
			'POST',
			"/admin/gift-cards/{$id}/adjust",
			[
				'amount' => -500,
				'note'   => 'Part used in store',
			]
		)->get_data();
		self::assertSame( 1500, $adjusted['balance'] );
		self::assertSame( [ 'adjusted', -500 ], [ $adjusted['activity'][0]['type'], $adjusted['activity'][0]['amount'] ] );

		$off = $this->send(
			'PATCH',
			"/admin/gift-cards/{$id}",
			[
				'status'     => 'disabled',
				'expires_on' => '',
			]
		)->get_data();
		self::assertSame( [ 'disabled', null ], [ $off['state'], $off['expires_at'] ] );
		self::assertSame( 400, $this->send( 'POST', "/admin/gift-cards/{$id}/send" )->get_status(), 'A disabled card is not sent.' );
		self::assertSame( 1, $this->send( 'GET', '/admin/gift-cards', [ 'status' => 'disabled' ] )->get_data()['total'] );

		$this->send( 'PATCH', "/admin/gift-cards/{$id}", [ 'status' => 'active' ] );
		self::assertSame( 200, $this->send( 'POST', "/admin/gift-cards/{$id}/send" )->get_status() );
		self::assertSame( 2, $this->gift_mails() );
		self::assertSame( 404, $this->send( 'GET', '/admin/gift-cards/999999' )->get_status() );
	}

	public function test_privacy_exports_masked_and_erases_the_recipient_but_keeps_the_balance(): void {
		$card   = $this->card(
			1500,
			[
				'recipient_email' => 'grace@example.com',
				'recipient_name'  => 'Grace',
				'message'         => 'Hi',
			]
		);
		$export = Privacy::instance()->export_gift_cards( 'Grace@example.com' );
		self::assertCount( 1, $export['data'] );
		$values = array_column( $export['data'][0]['data'], 'value' );
		self::assertContains( GiftCard::mask( $card->code ), $values );
		self::assertNotContains( GiftCard::format( $card->code ), $values, 'The full code is not exported.' );

		$erased = Privacy::instance()->erase_gift_cards( 'grace@example.com' );
		self::assertTrue( $erased['items_removed'] );
		self::assertTrue( $erased['items_retained'] );
		$after = $this->cards()->repository()->find( $card->id );
		self::assertSame( [ '', '', '', 1500 ], [ $after?->recipient_email, $after?->recipient_name, $after?->message, $after?->balance ] );
	}

	private function cards(): GiftCardService {
		return Plugin::instance()->gift_cards();
	}

	/** @param array<string,mixed> $extra */
	private function card( int $amount, array $extra = [] ): GiftCard {
		$card = $this->cards()->create( [ 'amount' => $amount ], 0 );
		if ( $extra !== [] ) {
			$this->cards()->repository()->update( $card->id, $extra, gmdate( 'Y-m-d H:i:s' ) );
		}
		return $this->cards()->repository()->find( $card->id ) ?? $card;
	}

	private function gift_product( int $price ): int {
		$id = $this->product();
		$this->save(
			$id,
			[
				'base' => [
					'regular_price' => $price,
					'gift_card'     => true,
				],
			]
		);
		return $id;
	}

	/** A cart that knows gift card codes, as the plugin's own does. */
	private function shopper(): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			new MemoryTokenStore(),
			gift_cards: static function ( string $code ): ?string {
				$code = GiftCard::normalize( $code );
				if ( ! GiftCard::looks_like( $code ) ) {
					return null;
				}
				$card = Plugin::instance()->gift_cards()->usable( $code );
				return $card?->code;
			}
		);
	}

	private function checkout( CartServices $browser ): CheckoutService {
		$plugin = Plugin::instance();
		return ( new CheckoutServices(
			$plugin->services(),
			$browser,
			new ShippingServices( $plugin->services() ),
			$plugin->orders(),
			$plugin->payments(),
			gift_cards: static fn (): GiftCardService => Plugin::instance()->gift_cards()
		) )->service();
	}

	private function add( CartServices $browser, int $product_id, int $qty ): void {
		$browser->cart()->add( 0, $product_id, $this->service()->get( $product_id )->base->id, $qty );
	}

	/** @return array<int,int> product id => quantity */
	private function lines( CartServices $browser ): array {
		$out = [];
		foreach ( $browser->cart()->state( 0 )->cart->lines as $line ) {
			$out[ $line->product_id ] = $line->quantity;
		}
		return $out;
	}

	private function paid_by_card( GiftCard $card, int $price ): \Flexa\Commerce\Orders\Order {
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( $price, 5 ), 1 );
		$browser->cart()->apply_coupon( 0, $card->code );
		$token = $checkout->form_state( 0 )['checkout_token'];
		return $checkout->place_order( $this->request( $token, [ 'payment_method' => '' ] ) )->order;
	}

	/** @param array<string,mixed> $overrides */
	private function request( string $token, array $overrides = [] ): CheckoutRequest {
		return CheckoutRequest::from_array(
			$overrides + [
				'checkout_token' => $token,
				'billing'        => self::BILLING,
				'shipping_rate'  => $this->rate_id,
				'payment_method' => 'cod',
			],
			0,
			'203.0.113.9'
		);
	}

	/** @return array<string,string> */
	private function assert_checkout_error( string $field, CheckoutService $checkout, CheckoutRequest $request ): array {
		try {
			$checkout->place_order( $request );
		} catch ( InvalidCheckout $e ) {
			self::assertArrayHasKey( $field, $e->errors );
			return $e->errors;
		} catch ( \Flexa\Commerce\Contracts\Exceptions\TotalsChanged $e ) {
			self::assertSame( 'expected_total', $field );
			return [ 'expected_total' => $e->getMessage() ];
		}
		self::fail( 'The checkout was accepted.' );
	}

	private function assert_checkout_refused( CheckoutService $checkout, CheckoutRequest $request ): void {
		try {
			$checkout->place_order( $request );
		} catch ( InvalidCheckout | \Flexa\Commerce\Contracts\Exceptions\TotalsChanged $e ) {
			unset( $e );
			return;
		}
		self::fail( 'The checkout was accepted.' );
	}

	private function cart_error( CartServices $browser, string $code ): string {
		try {
			$browser->cart()->apply_coupon( 0, $code );
		} catch ( InvalidCartRequest $e ) {
			return $e->getMessage();
		} catch ( GiftCardUnusable $e ) {
			return $e->getMessage();
		}
		self::fail( 'The code was accepted.' );
	}

	private function gift_mails(): int {
		$count = 0;
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			if ( str_contains( (string) $mail['subject'], 'gift card' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		if ( $body !== null && $method === 'GET' ) {
			$request->set_query_params( $body );
		} elseif ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
