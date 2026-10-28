<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Cart\SessionNonce;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalClient;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalGateway;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\ExpressButtons;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;
use Flexa\Commerce\Tests\Integration\Payments\FakePayPal;
use Flexa\Commerce\Tests\Integration\Payments\FakeStripe;

/**
 * The PayPal buttons (ADR-054): `store/express/session` opens the PayPal
 * order and follows the quote, `store/express/order` places the store
 * order with what PayPal knows of the payer and captures it.
 */
final class PayPalExpressTest extends OrderTestCase {
	private \WP_REST_Server $server;

	private MemoryTokenStore $cookie;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		$this->cookie = new MemoryTokenStore();
		FakePayPal::install();
		FakePayPal::connectable();
		$gateways = Plugin::instance()->payments()->gateways();
		$paypal   = $gateways->get( PayPalGateway::ID );
		self::assertNotNull( $paypal );
		$gateways->settings()->save(
			$paypal,
			[
				'enabled'            => true,
				'mode'               => 'test',
				'test_client_id'     => 'AbcClientId',
				'test_client_secret' => 'EfgSecret',
			]
		);
		$this->next_request();
	}

	public function tear_down(): void {
		global $wpdb;
		$this->swap_cart( null );
		FakePayPal::uninstall();
		parent::tear_down();
		// The orders committed the keys saved in set_up, so they go after the rollback.
		delete_option( GatewaySettings::PREFIX . PayPalGateway::ID );
		delete_option( PayPalGateway::STATUS_OPTION );
		delete_option( PayPalClient::TOKEN_OPTION );
		\Flexa\Commerce\Support\WalletPlaces::put( PayPalGateway::ID, [] );
		FakeStripe::forget( PayPalGateway::ID );
		$wpdb->query( 'COMMIT' );
	}

	public function test_the_session_opens_the_paypal_order_and_follows_the_quote(): void {
		FakePayPal::$routes['POST v2/checkout/orders']       = [
			201,
			[
				'id'     => 'EXP1',
				'status' => 'CREATED',
			],
		];
		FakePayPal::$routes['PATCH v2/checkout/orders/EXP1'] = [ 204, [] ];
		$product = $this->simple( 2000, 5 );
		$body    = [
			'payment_method' => 'paypal',
			'source'         => 'product',
			'product'        => [
				'product_id' => $product,
				'quantity'   => 2,
			],
		];

		$opened = $this->dispatch( 'POST', '/store/express/session', $body );
		self::assertSame( 200, $opened->get_status(), (string) wp_json_encode( $opened->get_data() ) );
		self::assertSame( 'EXP1', $opened->get_data()['session'] );
		self::assertSame( 4000, $opened->get_data()['total'] );
		$call = FakePayPal::calls_to( 'POST', 'v2/checkout/orders' )[0];
		self::assertSame( 'CAPTURE', $call['body']['intent'] );
		self::assertSame( '40.00', $call['body']['purchase_units'][0]['amount']['value'] );
		self::assertSame( '40.00', $call['body']['purchase_units'][0]['amount']['breakdown']['item_total']['value'] );
		self::assertArrayNotHasKey( 'custom_id', $call['body']['purchase_units'][0], 'the store order does not exist yet' );
		self::assertNotSame( '', $call['headers']['PayPal-Request-Id'] );

		$this->next_request();
		$moved = $this->dispatch(
			'POST',
			'/store/express/session',
			$body + [
				'session' => 'EXP1',
				'address' => [
					'country'  => 'US',
					'state'    => 'CA',
					'postcode' => '94105',
					'city'     => 'San Francisco',
				],
			]
		);
		self::assertSame( 200, $moved->get_status(), (string) wp_json_encode( $moved->get_data() ) );
		self::assertSame( 'EXP1', $moved->get_data()['session'] );
		self::assertCount( 1, FakePayPal::calls_to( 'POST', 'v2/checkout/orders' ), 'the same order follows the address' );
		$patch = FakePayPal::calls_to( 'PATCH', 'v2/checkout/orders/EXP1' );
		self::assertCount( 1, $patch );
		self::assertSame( 'replace', $patch[0]['body'][0]['op'] );
		self::assertSame( "/purchase_units/@reference_id=='default'/amount", $patch[0]['body'][0]['path'] );

		$this->next_request();
		$bad = $this->dispatch( 'POST', '/store/express/session', $body + [ 'session' => 'not an id!' ] );
		self::assertSame( 400, $bad->get_status() );

		$this->next_request();
		$stripe = $this->dispatch( 'POST', '/store/express/session', [ 'payment_method' => 'cod' ] + $body );
		self::assertSame( 400, $stripe->get_status(), 'only a gateway with its own sheet opens a session' );
	}

	public function test_paypal_down_while_opening_is_a_gateway_error(): void {
		FakePayPal::$routes['POST v2/checkout/orders'] = [ 503, [ 'name' => 'SERVICE_UNAVAILABLE' ] ];
		$response                                      = $this->dispatch(
			'POST',
			'/store/express/session',
			[
				'payment_method' => 'paypal',
				'source'         => 'product',
				'product'        => [ 'product_id' => $this->simple( 2000, 5 ) ],
			]
		);
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'The payment provider could not be reached. Please try again.', $response->get_data()['data']['errors']['payment_method'] ?? '' );
	}

	public function test_buy_now_takes_the_payer_from_paypal_and_captures(): void {
		$in_cart = $this->simple( 1500 );
		$this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $in_cart ] );
		$bought = $this->simple( 2000, 5 );
		FakePayPal::$routes['GET v2/checkout/orders/EXP2']          = [ 200, $this->approved( 'EXP2', '40.00' ) ];
		FakePayPal::$routes['POST v2/checkout/orders/EXP2/capture'] = [
			201,
			[
				'id'             => 'EXP2',
				'status'         => 'COMPLETED',
				'purchase_units' => [
					[
						'reference_id' => 'default',
						'payments'     => [
							'captures' => [
								[
									'id'     => 'CAPX',
									'status' => 'COMPLETED',
									'amount' => [
										'currency_code' => 'USD',
										'value'         => '40.00',
									],
								],
							],
						],
					],
				],
			],
		];
		$body = [
			'payment_method' => 'paypal',
			'source'         => 'product',
			'product'        => [
				'product_id' => $bought,
				'quantity'   => 2,
			],
			'checkout_token' => wp_generate_uuid4(),
			'expected_total' => 4000,
			'billing'        => [],
			'shipping'       => null,
			'gateway_input'  => [
				'session' => 'EXP2',
				'express' => 'paypal',
			],
		];

		$this->next_request();
		$placed = $this->dispatch( 'POST', '/store/express/order', $body );
		self::assertSame( 201, $placed->get_status(), (string) wp_json_encode( $placed->get_data() ) );
		$order = $this->orders()->get( (int) $placed->get_data()['order_id'] );
		self::assertSame( 'processing', $order->status );
		self::assertSame( 4000, $order->amount_paid );
		self::assertSame( 'grace@example.com', $order->customer_email );
		self::assertSame( 'Grace', $order->billing?->first_name );
		self::assertSame( 'Hopper', $order->billing?->last_name );
		self::assertSame( '1 Navy Yard', $order->billing?->address_1, 'the address PayPal ships to, when the payer has no own one' );
		self::assertSame( 'VA', $order->billing?->state );
		self::assertSame( 3, $this->stock_of( $bought ) );
		self::assertSame( 'fxc-capture-EXP2', FakePayPal::calls_to( 'POST', 'v2/checkout/orders/EXP2/capture' )[0]['headers']['PayPal-Request-Id'] );

		$this->next_request();
		$cart = $this->dispatch( 'GET', '/store/cart' );
		self::assertSame( 1, $cart->get_data()['item_count'], 'the cart keeps its product' );
	}

	public function test_an_order_paypal_has_not_approved_or_for_another_total_is_not_captured(): void {
		$product = $this->simple( 2000, 5 );
		$body    = [
			'payment_method' => 'paypal',
			'source'         => 'product',
			'product'        => [ 'product_id' => $product ],
			'checkout_token' => wp_generate_uuid4(),
			'expected_total' => 2000,
			'gateway_input'  => [ 'session' => 'EXP3' ],
		];
		FakePayPal::$routes['GET v2/checkout/orders/EXP3'] = [ 200, [ 'status' => 'CREATED' ] + $this->approved( 'EXP3', '20.00' ) ];
		$first = $this->dispatch( 'POST', '/store/express/order', $body );

		FakePayPal::$routes['GET v2/checkout/orders/EXP4'] = [ 200, $this->approved( 'EXP4', '15.00' ) ];
		$this->next_request();
		$second = $this->dispatch(
			'POST',
			'/store/express/order',
			[
				'checkout_token' => wp_generate_uuid4(),
				'gateway_input'  => [ 'session' => 'EXP4' ],
			] + $body
		);

		self::assertSame( [], FakePayPal::calls_to( 'POST', 'v2/checkout/orders/EXP3/capture' ) );
		self::assertSame( [], FakePayPal::calls_to( 'POST', 'v2/checkout/orders/EXP4/capture' ) );
		// The order waits on its pay page, like any checkout whose payment failed.
		self::assertSame( 'PayPal has not approved this payment. Try again.', $first->get_data()['payment']['message'] );
		self::assertSame( 'The order total changed after PayPal approved the payment. Pay again with PayPal.', $second->get_data()['payment']['message'] );
		foreach ( [ $first, $second ] as $response ) {
			self::assertSame( 'failed', $response->get_data()['payment']['result'] );
			self::assertSame( 'pending', $response->get_data()['status'] );
		}
	}

	public function test_the_buttons_carry_the_client_id_only(): void {
		$product = ExpressButtons::html( 'product', [ 'product_id' => 7 ] );
		self::assertStringContainsString( 'fxc-express__method--paypal', $product );
		self::assertStringContainsString( '&quot;gateway&quot;:&quot;paypal&quot;', $product );
		self::assertStringContainsString( 'client-id=AbcClientId', $product );
		self::assertStringNotContainsString( 'EfgSecret', $product );

		$gateways = Plugin::instance()->payments()->gateways();
		$paypal   = $gateways->get( PayPalGateway::ID );
		self::assertNotNull( $paypal );
		$gateways->settings()->save( $paypal, [ 'express_cart' => false ] );
		self::assertStringNotContainsString( 'method--paypal', ExpressButtons::html( 'cart' ) );
		self::assertStringContainsString( 'method--paypal', ExpressButtons::html( 'checkout' ) );
	}

	/** @return array<string,mixed> */
	private function approved( string $id, string $value ): array {
		return [
			'id'             => $id,
			'status'         => 'APPROVED',
			'payer'          => [
				'name'          => [
					'given_name' => 'Grace',
					'surname'    => 'Hopper',
				],
				'email_address' => 'grace@example.com',
				'address'       => [ 'country_code' => 'US' ],
			],
			'purchase_units' => [
				[
					'reference_id' => 'default',
					'amount'       => [
						'currency_code' => 'USD',
						'value'         => $value,
					],
					'shipping'     => [
						'name'    => [ 'full_name' => 'Grace Hopper' ],
						'address' => [
							'address_line_1' => '1 Navy Yard',
							'admin_area_2'   => 'Arlington',
							'admin_area_1'   => 'VA',
							'postal_code'    => '22202',
							'country_code'   => 'US',
						],
					],
				],
			],
		];
	}

	/** A new request: fresh services, the browser's cookie carried over. */
	private function next_request(): void {
		$this->swap_cart( new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), $this->cookie ) );
	}

	private function swap_cart( ?CartServices $services ): void {
		$property = new \ReflectionProperty( Plugin::class, 'cart' );
		$property->setValue( Plugin::instance(), $services );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
	}

	/** @param array<string,mixed>|null $body */
	private function dispatch( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_header( 'origin', home_url() );
		$request->set_header( SessionNonce::HEADER, Plugin::instance()->cart()->nonce()->create( get_current_user_id(), wp_get_session_token(), $this->cookie->get() ) );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return $this->server->dispatch( $request );
	}
}
