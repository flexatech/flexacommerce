<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\GiftCards;

use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidRefund;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\GiftCards\AppliedGiftCard;
use Flexa\Commerce\GiftCards\GiftCard;
use Flexa\Commerce\GiftCards\GiftCardActivity;
use Flexa\Commerce\GiftCards\GiftCardService;
use Flexa\Commerce\GiftCards\GiftCardUnusable;
use Flexa\Commerce\Orders\Order;
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
use Flexa\Commerce\Storefront\Render\Account;
use Flexa\Commerce\Storefront\Render\CheckoutForm;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * Store credit (ADR-072): a gift card row tied to an account. It pays at
 * checkout without a code, the shopper can turn it off, refunds can be
 * paid into it, the admin changes it with a reason, and the account page
 * shows it. Balances commit, so the rows are deleted after the rollback.
 */
final class StoreCreditTest extends OrderTestCase {
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

	private int $user = 0;

	private int $last_user = 0;

	public function set_up(): void {
		parent::set_up();
		$gateways = Plugin::instance()->payments()->service()->gateways();
		$cod      = $gateways->get( 'cod' );
		self::assertNotNull( $cod );
		$gateways->settings()->save( $cod, [ 'enabled' => true ] );
		$zones         = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		$this->rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
		global $wpdb;
		$this->last_user = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB
		$this->user      = self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_email'   => 'ada@example.com',
				'display_name' => 'Ada',
			]
		);
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wpdb;
		delete_option( GatewaySettings::PREFIX . 'cod' );
		wp_set_current_user( 0 );
		parent::tear_down();
		delete_option( ZoneRepository::OPTION );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'gift_card_activity' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'gift_cards' ) ); // phpcs:ignore WordPress.DB
		// A balance write commits the users made so far; take them out again.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id > %d", $this->last_user ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE ID > %d", $this->last_user ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_the_credit_pays_at_checkout_and_the_shopper_can_turn_it_off(): void {
		$this->cards()->adjust_credit( $this->user, 3000, 'Welcome', 1 );
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000, 5 ), 2 );

		$form = $checkout->form_state( $this->user );
		self::assertSame( 2500, $form['totals']['total']['amount'] );
		self::assertSame( 0, $form['totals']['due']['amount'] );
		self::assertCount( 1, $form['gift_cards'] );
		$row = $form['gift_cards'][0];
		self::assertSame( [ CartService::CREDIT_CODE, 'Store credit', true, true, 2500 ], [ $row['code'], $row['label'], $row['credit'], $row['used'], $row['amount']['amount'] ] );
		self::assertStringNotContainsString( $this->account()->code, (string) wp_json_encode( $form ), 'The credit code never reaches the browser.' );

		$state = $browser->cart()->remove_coupon( $this->user, CartService::CREDIT_CODE );
		self::assertTrue( $state->cart->skip_credit );
		$form = $checkout->form_state( $this->user );
		self::assertSame( 2500, $form['totals']['due']['amount'] );
		self::assertSame( [ true, false, 3000 ], [ $form['gift_cards'][0]['credit'], $form['gift_cards'][0]['used'], $form['gift_cards'][0]['balance']['amount'] ], 'The row stays so the shopper can turn it back on.' );

		$rows = CheckoutForm::gift_card_rows( $form['gift_cards'], true );
		self::assertStringContainsString( 'name="use_credit" value="1"', $rows );
		self::assertStringContainsString( '$30.00 available', $rows );

		$browser->cart()->apply_coupon( $this->user, 'Store-Credit' );
		$form = $checkout->form_state( $this->user );
		$rows = CheckoutForm::gift_card_rows( $form['gift_cards'], true );
		self::assertStringContainsString( 'name="remove_coupon" value="store-credit"', $rows );
		self::assertStringContainsString( '-$25.00', $rows );
		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'payment_method' => '' ] ) )->order;
		self::assertSame( OrderStatus::PROCESSING, $order->status );
		self::assertSame( 500, $this->account()->balance );
		$payments = Plugin::instance()->payments()->service()->for_order( $order->id );
		self::assertSame( [ GiftCardService::GATEWAY, 2500, [ 'store_credit' => true ] ], [ $payments[0]->gateway, $payments[0]->amount, $payments[0]->data ] );
		self::assertSame( GiftCardActivity::REDEEMED, $this->cards()->repository()->activity( $this->account()->id )[0]->type );
	}

	public function test_a_guest_and_a_customer_without_credit_see_no_row(): void {
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000, 5 ), 1 );
		self::assertNull( $browser->cart()->state( $this->user )->credit );
		self::assertSame( '', get_user_meta( $this->user, GiftCardService::CREDIT_META, true ) );

		$this->cards()->adjust_credit( $this->user, 100, 'Sorry', 1 );
		self::assertNull( $browser->cart()->state( 0 )->credit );
		try {
			$browser->cart()->apply_coupon( 0, CartService::CREDIT_CODE );
			self::fail( 'A guest turned credit on.' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'Log in to use your store credit.', $e->getMessage() );
		}
	}

	public function test_the_credit_code_cannot_be_typed_or_spent_by_someone_else(): void {
		$card    = $this->cards()->adjust_credit( $this->user, 2000, 'Sorry', 1 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000, 5 ), 1 );
		try {
			$browser->cart()->apply_coupon( 0, $card->code );
			self::fail( 'The credit code was accepted from the code box.' );
		} catch ( InvalidCartRequest | GiftCardUnusable $e ) {
			self::assertStringNotContainsString( 'Store credit', $e->getMessage() );
		}

		$order = $this->place( [ $this->simple( 1000, 5 ) => 1 ] );
		$this->expectException( GiftCardUnusable::class );
		$this->cards()->redeem( $order, [ new AppliedGiftCard( $card->id, $card->code, 1000, 2000 ) ], TransitionContext::system() );
	}

	public function test_a_refund_is_paid_into_the_store_credit(): void {
		$this->cards()->adjust_credit( $this->user, 5000, 'Start', 1 );
		$order = $this->paid_by_credit( 1000 );
		self::assertSame( 3500, $this->account()->balance );

		$refund = $this->cards()->refund_to_credit( $order->id, 600, [], 'Damaged', TransitionContext::admin() );
		self::assertSame( [ Transaction::SUCCEEDED, GiftCardService::GATEWAY, 600 ], [ $refund->status, $refund->gateway, $refund->amount ] );
		self::assertSame( 4100, $this->account()->balance );
		$last = $this->cards()->repository()->activity( $this->account()->id )[0];
		self::assertSame( [ GiftCardActivity::CREDITED, 600, $order->id, $refund->id ], [ $last->type, $last->amount, $last->order_id, $last->transaction_id ] );
		self::assertSame( 600, $this->orders()->find( $order->id )?->amount_refunded );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$detail = $this->send( 'GET', "/admin/orders/{$order->id}" )->get_data();
		self::assertTrue( $detail['credit_refunds'] );
		$made = $this->send(
			'POST',
			"/admin/orders/{$order->id}/refunds",
			[
				'amount'    => 400,
				'to_credit' => true,
			]
		);
		self::assertSame( 201, $made->get_status() );
		self::assertSame( 4500, $this->account()->balance );
		self::assertSame(
			400,
			$this->send(
				'POST',
				"/admin/orders/{$order->id}/refunds",
				[
					'amount'    => 501,
					'to_credit' => true,
				]
			)->get_status(),
			'Only 500 is left to refund.'
		);
		self::assertSame( 4500, $this->account()->balance );
	}

	public function test_a_guest_order_cannot_be_refunded_to_store_credit(): void {
		$order = $this->place( [ $this->simple( 1000, 5 ) => 1 ], 0, null, OrderStatus::PROCESSING );
		$this->expectException( InvalidRefund::class );
		$this->cards()->refund_to_credit( $order->id, 100, [], '', TransitionContext::admin() );
	}

	public function test_the_admin_sees_and_changes_the_credit_with_a_reason(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'GET', "/admin/customers/{$this->user}/credit" )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$empty = $this->send( 'GET', "/admin/customers/{$this->user}/credit" )->get_data();
		self::assertSame( [ 0, [] ], [ $empty['balance'], $empty['activity'] ] );
		self::assertSame(
			400,
			$this->send(
				'POST',
				"/admin/customers/{$this->user}/credit",
				[
					'amount' => -100,
					'note'   => 'Oops',
				]
			)->get_status(),
			'Nothing to take from.'
		);
		self::assertSame(
			400,
			$this->send(
				'POST',
				"/admin/customers/{$this->user}/credit",
				[
					'amount' => 100,
					'note'   => '',
				]
			)->get_status(),
			'A reason is needed.'
		);
		self::assertNull( $this->cards()->credit_account( $this->user ), 'No account is opened by a refused change.' );

		$given = $this->send(
			'POST',
			"/admin/customers/{$this->user}/credit",
			[
				'amount' => 1500,
				'note'   => 'Late delivery',
			]
		)->get_data();
		self::assertSame( 1500, $given['balance'] );
		self::assertCount( 1, $given['activity'], 'The zero opening row is not shown.' );
		self::assertSame( [ 'adjusted', 1500, 'Late delivery' ], [ $given['activity'][0]['type'], $given['activity'][0]['amount'], $given['activity'][0]['note'] ] );
		self::assertSame(
			400,
			$this->send(
				'POST',
				"/admin/customers/{$this->user}/credit",
				[
					'amount' => -2000,
					'note'   => 'Too much',
				]
			)->get_status()
		);
		self::assertSame( 404, $this->send( 'GET', '/admin/customers/999999/credit' )->get_status() );

		self::assertSame( 0, $this->send( 'GET', '/admin/gift-cards' )->get_data()['total'], 'Store credit is not listed with the gift cards.' );
	}

	public function test_the_account_page_and_the_privacy_export_show_the_credit(): void {
		$this->cards()->adjust_credit( $this->user, 1500, 'Late delivery', 1 );
		wp_set_current_user( $this->user );
		$view = new Account( new CustomerProfile(), Plugin::instance()->checkout()->service() );
		$html = $view->credit( $this->user );
		self::assertStringContainsString( '$15.00', $html );
		self::assertStringContainsString( 'Late delivery', $html );
		self::assertStringContainsString( 'Added by the store', $html );
		self::assertStringNotContainsString( $this->account()->code, $html );
		self::assertStringContainsString( 'in store credit', $view->dashboard( $this->user ) );
		self::assertStringContainsString( '>Store credit</a>', $view->page( '', '', '' ) );

		wp_update_user(
			[
				'ID'         => $this->user,
				'user_email' => 'ada.new@example.com',
			]
		);
		$export = Privacy::instance()->export_gift_cards( 'ada.new@example.com' );
		self::assertSame( [ 'flexacommerce-store-credit' ], array_column( $export['data'], 'group_id' ) );
		self::assertNotContains( GiftCard::format( $this->account()->code ), array_column( $export['data'][0]['data'], 'value' ) );
	}

	private function cards(): GiftCardService {
		return Plugin::instance()->gift_cards();
	}

	private function account(): GiftCard {
		$card = $this->cards()->credit_account( $this->user );
		self::assertNotNull( $card );
		return $card;
	}

	/** A cart wired like the plugin's own: typed codes and the account's credit. */
	private function shopper(): CartServices {
		$plugin = Plugin::instance();
		$call   = static function ( string $method, mixed $arg ): ?string {
			$reflection = new \ReflectionMethod( Plugin::class, $method );
			$out        = $reflection->invoke( Plugin::instance(), $arg );
			return is_string( $out ) ? $out : null;
		};
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			new MemoryTokenStore(),
			coupons: static fn (): \Flexa\Commerce\Coupons\CouponService => Plugin::instance()->coupons()->service(),
			gift_cards: static fn ( string $code ): ?string => $call( 'gift_card_code', $code ),
			credit: static fn ( int $user_id ): ?string => $call( 'credit_code', $user_id )
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
		$browser->cart()->add( $this->user, $product_id, $this->service()->get( $product_id )->base->id, $qty );
		$browser->cart()->add( 0, $product_id, $this->service()->get( $product_id )->base->id, $qty );
	}

	private function paid_by_credit( int $price ): Order {
		$browser  = $this->shopper();
		$checkout = $this->checkout( $browser );
		$browser->cart()->add( $this->user, $product = $this->simple( $price, 5 ), $this->service()->get( $product )->base->id, 1 );
		$token = $checkout->form_state( $this->user )['checkout_token'];
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
			$this->user,
			'203.0.113.9'
		);
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
