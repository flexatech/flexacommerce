<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Checkout;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Exceptions\OrderNotFound;
use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Contracts\Exceptions\TotalsChanged;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;
use Flexa\Commerce\Tests\Integration\Payments\FakeGateway;

final class CheckoutServiceTest extends OrderTestCase {
	private const BILLING = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Infinite Loop',
		'city'       => 'Cupertino',
		'state'      => 'CA',
		'postcode'   => '95014',
		'country'    => 'US',
		'email'      => 'Ada@Example.com',
	];

	private string $rate_id = '';

	public function set_up(): void {
		parent::set_up();
		$gateways = $this->payments()->gateways();
		if ( ! $gateways->has( FakeGateway::ID ) ) {
			$gateways->register( FakeGateway::ID, static fn (): PaymentGateway => new FakeGateway() );
		}
		foreach ( [
			'cod'           => true,
			'bacs'          => false,
			FakeGateway::ID => true,
		] as $id => $enabled ) {
			$gateway = $gateways->get( $id );
			self::assertNotNull( $gateway );
			$gateways->settings()->save( $gateway, [ 'enabled' => $enabled ] );
		}
		FakeGateway::$payment_mode = 'redirect';

		$this->rate_id = $this->ship_flat( 500 );
	}

	public function tear_down(): void {
		foreach ( [ 'cod', 'bacs', FakeGateway::ID ] as $id ) {
			delete_option( GatewaySettings::PREFIX . $id );
		}
		delete_option( ZoneRepository::OPTION );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	public function test_cash_on_delivery_order_takes_stock_and_empties_the_cart(): void {
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$product  = $this->simple( 1000, 5 );
		$this->add( $browser, $product, 2 );

		$form = $checkout->form_state( 0 );
		self::assertNotSame( '', $form['checkout_token'] );
		self::assertTrue( $form['needs_shipping'] );
		self::assertSame( $this->rate_id, $form['shipping_rate'] );
		self::assertSame( 2500, $form['totals']['total']['amount'] );
		self::assertContains( 'cod', array_column( $form['payment_methods'], 'id' ) );

		$result = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 2500 ] ) );
		$order  = $result->order;

		self::assertFalse( $result->duplicate );
		self::assertSame( OrderStatus::PROCESSING, $order->status );
		self::assertSame( 2500, $order->total );
		self::assertSame( 500, $order->shipping_total );
		self::assertSame( 'cod', $order->payment_method );
		self::assertSame( 'ada@example.com', $order->customer_email );
		self::assertSame( 'CA', $order->billing?->state );
		self::assertSame( 'Cupertino', $order->shipping?->city, 'ships to the billing address' );
		self::assertSame( 3, $this->stock_of( $product ) );
		self::assertSame( [ OrderItem::PRODUCT, OrderItem::SHIPPING ], array_map( static fn ( OrderItem $i ): string => $i->type, $order->items ) );
		self::assertStringContainsString( 'order-received=' . $order->id, $result->redirect );
		self::assertSame( PaymentResult::PENDING, $result->payment?->type );

		$after = $browser->cart()->state( 0 );
		self::assertTrue( $after->cart->is_empty() );
		self::assertNotSame( $form['checkout_token'], $after->cart->checkout_token );
	}

	public function test_a_customer_checkout_saves_the_addresses_for_the_next_one(): void {
		$user     = self::factory()->user->create();
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$product  = $this->simple( 1000, 5 );
		$browser->cart()->add( $user, $product, $this->service()->get( $product )->base->id, 1 );

		$form = $checkout->form_state( $user );
		self::assertSame( [], $form['saved'] );
		$shipping = [
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'address_1'  => '9 Pine St',
			'city'       => 'Reno',
			'state'      => 'NV',
			'postcode'   => '89501',
			'country'    => 'US',
		];
		$request  = CheckoutRequest::from_array(
			[
				'checkout_token'            => $form['checkout_token'],
				'billing'                   => self::BILLING,
				'ship_to_different_address' => '1',
				'shipping'                  => $shipping,
				'shipping_rate'             => $this->rate_id,
				'payment_method'            => 'cod',
			],
			$user
		);
		$order    = $checkout->place_order( $request )->order;
		self::assertSame( $user, $order->customer_id );

		$profile = new CustomerProfile();
		self::assertSame( 'Cupertino', $profile->address( $user, 'billing' )?->city );
		self::assertSame( 'ada@example.com', $profile->address( $user, 'billing' )?->email );
		self::assertSame( 'Reno', $profile->address( $user, 'shipping' )?->city );

		$browser->cart()->add( $user, $product, $this->service()->get( $product )->base->id, 1 );
		$next = $checkout->form_state( $user );
		self::assertSame( '1 Infinite Loop', $next['saved']['billing']['address_1'] );
		self::assertSame( '9 Pine St', $next['saved']['shipping']['address_1'] );
		self::assertSame( 'CA', $next['destination']['state'], 'the first quote uses the saved billing address' );
		self::assertSame( [], $checkout->form_state( 0 )['saved'], 'a guest sees nothing saved' );
	}

	public function test_a_repeated_submit_returns_the_first_order(): void {
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$product  = $this->simple( 1000, 5 );
		$this->add( $browser, $product, 1 );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$first  = $checkout->place_order( $this->request( $token ) );
		$second = $checkout->place_order( $this->request( $token ) );

		self::assertTrue( $second->duplicate );
		self::assertSame( $first->order->id, $second->order->id );
		self::assertSame( 4, $this->stock_of( $product ), 'stock is taken once' );
	}

	public function test_a_changed_total_is_refused_before_anything_is_written(): void {
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$product  = $this->simple( 1000, 5 );
		$this->add( $browser, $product, 1 );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$this->expectException( TotalsChanged::class );
		try {
			$checkout->place_order( $this->request( $token, [ 'expected_total' => 1000 ] ) );
		} finally {
			self::assertNull( $this->orders()->find_by_checkout_token( $token ) );
			self::assertSame( 5, $this->stock_of( $product ) );
		}
	}

	public function test_errors_are_keyed_by_field(): void {
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$errors = $this->errors(
			$checkout,
			$this->request(
				$token,
				[
					'billing'        => [ 'postcode' => 'ABC' ] + self::BILLING,
					'shipping_rate'  => 'flat_rate:999',
					'payment_method' => 'bacs',
				]
			)
		);
		self::assertArrayHasKey( 'billing.postcode', $errors );
		self::assertSame( 'Choose a shipping option.', $errors['shipping_rate'] );
		self::assertArrayHasKey( 'payment_method', $errors, 'bacs is disabled' );

		$errors = $this->errors( $checkout, $this->request( $token, [ 'billing' => [ 'email' => 'nope' ] + self::BILLING ] ) );
		self::assertSame( [ 'billing.email' ], array_keys( $errors ) );
	}

	public function test_a_line_that_ran_out_is_refused(): void {
		$product = $this->simple( 1000, 1 );
		$first   = $this->browser();
		$second  = $this->browser();
		$this->add( $first, $product, 1 );
		$this->add( $second, $product, 1 );
		$token_one = $this->checkout( $first )->form_state( 0 )['checkout_token'];
		$token_two = $this->checkout( $second )->form_state( 0 )['checkout_token'];

		$this->checkout( $first )->place_order( $this->request( $token_one ) );
		$errors = $this->errors( $this->checkout( $second ), $this->request( $token_two ) );

		self::assertSame( [ 'cart' ], array_keys( $errors ) );
		self::assertSame( 0, $this->stock_of( $product ) );
	}

	public function test_a_free_order_without_shipping_skips_payment(): void {
		delete_option( ZoneRepository::OPTION );
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 0 ), 1 );
		$form = $checkout->form_state( 0 );
		self::assertFalse( $form['needs_shipping'] );
		self::assertSame( [], $form['payment_methods'] );

		$result = $checkout->place_order( $this->request( $form['checkout_token'], [ 'payment_method' => '' ] ) );

		self::assertSame( OrderStatus::PROCESSING, $result->order->status );
		self::assertSame( 0, $result->order->total );
		self::assertNull( $result->order->shipping );
		self::assertNull( $result->payment );
		self::assertSame( [], $this->payments()->for_order( $result->order->id ) );
	}

	public function test_bank_transfer_waits_on_hold_with_instructions(): void {
		$bacs = $this->payments()->gateways()->get( 'bacs' );
		self::assertNotNull( $bacs );
		$this->payments()->gateways()->settings()->save(
			$bacs,
			[
				'enabled'        => true,
				'account_number' => '12345678',
			]
		);
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$result = $checkout->place_order( $this->request( $token, [ 'payment_method' => 'bacs' ] ) );

		self::assertSame( OrderStatus::ON_HOLD, $result->order->status );
		self::assertSame( PaymentResult::PENDING, $result->payment?->type );
		self::assertStringContainsString( '12345678', (string) $result->payment?->instructions );
	}

	public function test_a_redirect_gateway_leaves_the_order_pending_and_a_failure_can_be_retried(): void {
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000, 5 ), 1 );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$result = $checkout->place_order( $this->request( $token, [ 'payment_method' => FakeGateway::ID ] ) );
		self::assertSame( OrderStatus::PENDING, $result->order->status );
		self::assertStringStartsWith( 'https://pay.example.test/pi_', $result->redirect );

		FakeGateway::$payment_mode = 'failed';
		$this->add( $browser, $this->simple( 1000, 5 ), 1 );
		$token  = $checkout->form_state( 0 )['checkout_token'];
		$failed = $checkout->place_order( $this->request( $token, [ 'payment_method' => FakeGateway::ID ] ) );
		self::assertSame( OrderStatus::PENDING, $failed->order->status );
		self::assertStringContainsString( 'order-pay=' . $failed->order->id, $failed->redirect );
		self::assertTrue( $browser->cart()->state( 0 )->cart->is_empty(), 'the order exists, so the cart is emptied' );

		try {
			$checkout->retry_payment( $failed->order->id, 'wrong-key', 'cod', [], 0 );
			self::fail( 'A wrong key was accepted.' );
		} catch ( \Flexa\Commerce\Contracts\Exceptions\OrderNotFound $e ) {
			self::assertNotSame( '', $e->getMessage() );
		}

		$retried = $checkout->retry_payment( $failed->order->id, $failed->order->order_key, 'cod', [], 0 );
		self::assertSame( OrderStatus::PROCESSING, $retried->order->status );
		self::assertSame( 'cod', $retried->order->payment_method );
		$statuses = array_map( static fn ( Transaction $t ): string => $t->status, $this->payments()->for_order( $failed->order->id ) );
		self::assertContains( Transaction::FAILED, $statuses );
		self::assertCount( 2, $statuses );
	}

	public function test_repeated_failed_attempts_from_one_cart_are_slowed_down(): void {
		add_filter(
			'flexacommerce.rate_limit.checkout_session',
			static fn (): array => [
				'limit'  => 2,
				'window' => 600,
			]
		);
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$token = $checkout->form_state( 0 )['checkout_token'];
		$bad   = $this->request( $token, [ 'billing' => [ 'email' => 'nope' ] + self::BILLING ] );

		$this->errors( $checkout, $bad );
		$this->errors( $checkout, $bad );
		try {
			$checkout->place_order( $this->request( $token ) );
			self::fail( 'The third attempt was accepted.' );
		} catch ( RateLimited $e ) {
			self::assertGreaterThan( 0, $e->retry_after );
		}
	}

	public function test_a_repeated_submit_of_a_placed_order_is_not_counted(): void {
		add_filter(
			'flexacommerce.rate_limit.checkout_ip',
			static fn (): array => [
				'limit'  => 1,
				'window' => 600,
			]
		);
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$token = $checkout->form_state( 0 )['checkout_token'];

		$first = $checkout->place_order( $this->request( $token ) );
		self::assertSame( $first->order->id, $checkout->place_order( $this->request( $token ) )->order->id );
	}

	public function test_wrong_order_keys_from_one_ip_lock_out_key_access(): void {
		add_filter(
			'flexacommerce.rate_limit.order_key_ip',
			static fn (): array => [
				'limit'  => 2,
				'window' => 3600,
			]
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		$browser                = $this->browser();
		$checkout               = $this->checkout( $browser );
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$order = $checkout->place_order( $this->request( $checkout->form_state( 0 )['checkout_token'] ) )->order;

		self::assertSame( $order->id, $checkout->viewable_order( $order->id, $order->order_key, 0 )?->id );
		self::assertNull( $checkout->viewable_order( $order->id, 'wrong', 0 ) );
		self::assertNull( $checkout->viewable_order( $order->id + 999, 'wrong', 0 ), 'an unknown order counts too' );
		self::assertNull( $checkout->viewable_order( $order->id, $order->order_key, 0 ), 'the right key is refused once locked' );
		try {
			$checkout->retry_payment( $order->id, $order->order_key, 'cod', [], 0 );
			self::fail( 'A locked IP could retry a payment.' );
		} catch ( RateLimited ) {
			self::assertTrue( true );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		self::assertSame( $order->id, $checkout->viewable_order( $order->id, $order->order_key, 0 )?->id, 'other visitors are not affected' );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		self::assertSame( $order->id, $checkout->viewable_order( $order->id, '', $admin )?->id, 'staff need no key' );
		$this->expectException( OrderNotFound::class );
		$checkout->retry_payment( $order->id, '', 'cod', [], 0 );
	}

	private function payments(): \Flexa\Commerce\Payments\PaymentService {
		return Plugin::instance()->payments()->service();
	}

	private function checkout( CartServices $browser ): CheckoutService {
		$plugin = Plugin::instance();
		return ( new CheckoutServices( $plugin->services(), $browser, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();
	}

	/** One US zone with a flat rate; returns its rate id. */
	private function ship_flat( int $cost ): string {
		$zones = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => $cost ] ) ] ) ]
		);
		return 'flat_rate:' . $zones[0]->methods[0]->instance_id;
	}

	private function add( CartServices $browser, int $product_id, int $qty ): void {
		$browser->cart()->add( 0, $product_id, $this->service()->get( $product_id )->base->id, $qty );
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
	private function errors( CheckoutService $checkout, CheckoutRequest $request ): array {
		try {
			$checkout->place_order( $request );
		} catch ( InvalidCheckout $e ) {
			return $e->errors;
		}
		self::fail( 'The checkout was accepted.' );
	}
}
