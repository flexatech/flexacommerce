<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Payments;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Payments\CheckoutContext;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalAmount;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalClient;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalGateway;
use Flexa\Commerce\Payments\PaymentService;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * The PayPal gateway (ADR-054) against a table of PayPal answers (FakePayPal).
 */
final class PayPalGatewayTest extends OrderTestCase {
	private const KEYS = [
		'enabled'            => true,
		'mode'               => 'test',
		'test_client_id'     => 'AbcClientId',
		'test_client_secret' => 'EfgSecret',
	];

	public function set_up(): void {
		parent::set_up();
		FakePayPal::install();
		FakePayPal::connectable();
		$this->settings()->save( $this->gateway(), self::KEYS );
	}

	public function tear_down(): void {
		global $wpdb;
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

	public function test_amounts_follow_the_currency(): void {
		self::assertSame( '15.00', PayPalAmount::to_paypal( new Money( 1500, 'USD' ) ) );
		self::assertSame( '1999', PayPalAmount::to_paypal( new Money( 1999, 'JPY' ) ) );
		self::assertNull( PayPalAmount::to_paypal( new Money( 1500, 'VND' ) ), 'PayPal does not take dong' );
		self::assertNull( PayPalAmount::to_paypal( new Money( -1, 'USD' ) ) );
		self::assertSame( 1999, PayPalAmount::from_paypal( '19.99', 'USD' )->amount );
	}

	public function test_saving_credentials_gets_a_token_and_creates_the_webhook(): void {
		$token = FakePayPal::calls_to( 'POST', 'v1/oauth2/token' );
		self::assertCount( 1, $token );
		self::assertSame( 'Basic ' . base64_encode( 'AbcClientId:EfgSecret' ), $token[0]['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		self::assertFalse( $token[0]['live'] );

		$created = FakePayPal::calls_to( 'POST', 'v1/notifications/webhooks' );
		self::assertCount( 1, $created );
		self::assertSame( rest_url( 'flexacommerce/v1/webhooks/paypal' ), $created[0]['body']['url'] );
		self::assertContains( [ 'name' => 'PAYMENT.CAPTURE.COMPLETED' ], $created[0]['body']['event_types'] );
		self::assertSame( 'Bearer A21_token', $created[0]['headers']['Authorization'] );
		self::assertSame( 'WH-MADE-1', $this->settings()->values( $this->gateway() )['test_webhook_id'] );

		$notes = $this->notes();
		self::assertStringContainsString( 'Connected to PayPal in sandbox mode.', $notes );
		self::assertStringContainsString( 'PayPal sends payment updates to', $notes );

		FakePayPal::$calls = [];
		$this->settings()->save( $this->gateway(), [ 'title' => 'PayPal or card' ] );
		self::assertSame( [], FakePayPal::$calls, 'the same credentials are not checked again' );
		self::assertStringContainsString( 'Connected to PayPal', $this->notes() );
		self::assertFalse( wp_load_alloptions()[ PayPalClient::TOKEN_OPTION ] ?? false, 'the token is not autoloaded' );
	}

	public function test_refused_or_half_credentials_save_nothing(): void {
		try {
			$this->settings()->save( $this->gateway(), [ 'live_client_id' => 'LiveClientId' ] );
			self::fail( 'Saved a client ID without its secret.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( 'Fill in both the live client ID and the secret.', $e->getMessage() );
		}

		FakePayPal::$routes['POST v1/oauth2/token'] = [
			401,
			[
				'error'             => 'invalid_client',
				'error_description' => 'Client Authentication failed',
			],
		];
		try {
			$this->settings()->save( $this->gateway(), [ 'test_client_secret' => 'Wrong' ] );
			self::fail( 'Saved credentials PayPal refused.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( 'PayPal did not accept the sandbox client ID and secret: Client Authentication failed.', $e->getMessage() );
		}
		$stored = get_option( GatewaySettings::PREFIX . PayPalGateway::ID );
		self::assertIsArray( $stored );
		self::assertSame( 'EfgSecret', $stored['test_client_secret'] );
	}

	public function test_the_webhook_of_an_earlier_install_is_found_again(): void {
		$url = rest_url( 'flexacommerce/v1/webhooks/paypal' );
		FakePayPal::$routes['POST v1/notifications/webhooks']   = [
			400,
			[
				'name'    => 'WEBHOOK_URL_ALREADY_EXISTS',
				'message' => 'Webhook URL already exists.',
			],
		];
		FakePayPal::$routes['GET v1/notifications/webhooks']    = [
			200,
			[
				'webhooks' => [
					[
						'id'  => 'WH-OTHER',
						'url' => 'https://other.test/hook',
					],
					[
						'id'  => 'WH-OLD',
						'url' => $url,
					],
				],
			],
		];
		FakePayPal::$routes['PATCH v1/notifications/webhooks/'] = [ 200, [ 'id' => 'WH-OLD' ] ];
		$this->settings()->save( $this->gateway(), [ 'test_client_secret' => 'NewSecret' ] );

		self::assertSame( 'WH-OLD', $this->settings()->values( $this->gateway() )['test_webhook_id'] );
		self::assertCount( 1, FakePayPal::calls_to( 'PATCH', 'v1/notifications/webhooks/WH-OLD' ) );
	}

	public function test_a_webhook_that_cannot_be_made_says_how_to_add_it(): void {
		delete_option( GatewaySettings::PREFIX . PayPalGateway::ID );
		FakeStripe::forget( PayPalGateway::ID );
		FakePayPal::$routes['POST v1/notifications/webhooks'] = [
			422,
			[
				'name'    => 'UNPROCESSABLE_ENTITY',
				'details' => [
					[
						'issue'       => 'INVALID_URL',
						'description' => 'The webhook URL must use HTTPS.',
					],
				],
			],
		];
		$this->settings()->save( $this->gateway(), self::KEYS );

		$notes = $this->notes();
		self::assertStringContainsString( 'PayPal could not add the webhook: The webhook URL must use HTTPS.', $notes );
		self::assertStringContainsString( 'CHECKOUT.ORDER.APPROVED', $notes );
		self::assertStringContainsString( 'Connected to PayPal', $notes, 'payments work without it' );
	}

	public function test_the_buttons_get_the_client_id_only(): void {
		$config = $this->gateway()->express_config( 'product' );
		self::assertIsArray( $config );
		self::assertStringContainsString( 'assets/frontend/paypal-express.js', (string) $config['script'] );
		self::assertSame( PayPalGateway::EXPRESS_MODULE, $config['module'] );
		self::assertSame( 'paypal', $config['gateway'] );
		self::assertStringContainsString( 'client-id=AbcClientId', (string) $config['sdk'] );
		self::assertStringContainsString( 'currency=USD', (string) $config['sdk'] );
		self::assertStringContainsString( 'enable-funding=venmo%2Cpaylater', (string) $config['sdk'] );
		self::assertStringNotContainsString( 'EfgSecret', (string) wp_json_encode( $config ) );
		self::assertNull( $this->gateway()->express_config( 'mini-cart' ) );

		$this->settings()->save(
			$this->gateway(),
			[
				'pay_later'       => false,
				'button_color'    => 'black',
				'express_product' => false,
			]
		);
		self::assertNull( $this->gateway()->express_config( 'product' ) );
		$cart = $this->gateway()->express_config( 'cart' );
		self::assertIsArray( $cart );
		self::assertStringContainsString( 'disable-funding=paylater', (string) $cart['sdk'] );
		self::assertSame( 'black', $cart['color'] );

		$context = new CheckoutContext( new Money( 1999, 'USD' ), 'US', 'US', true, '', null, 1 );
		self::assertTrue( $this->gateway()->is_available( $context ) );
		self::assertFalse( $this->gateway()->is_available( new CheckoutContext( new Money( 1999, 'VND' ), 'VN', 'VN', true, '', null, 1 ) ) );
		self::assertStringContainsString( 'assets/frontend/paypal.js', (string) $this->gateway()->checkout_fields( $context )->script_module );
		self::assertFalse( $this->gateway()->validate( $context, [ 'session' => 'not an id' ] )->is_valid() );
		self::assertFalse( $this->gateway()->validate( $context, [ 'express' => 'apple_pay' ] )->is_valid() );
		self::assertTrue(
			$this->gateway()->validate(
				$context,
				[
					'flow'    => 'popup',
					'express' => 'venmo',
				]
			)->is_valid()
		);
	}

	public function test_the_checkout_buttons_open_the_order_in_a_window_and_the_return_captures(): void {
		FakePayPal::$routes['POST v2/checkout/orders'] = [ 201, $this->order( 'PP1', 'PAYER_ACTION_REQUIRED', '15.00' ) ];
		[ $order, $txn, $result ]                      = $this->pay( 1500, [ 'flow' => 'popup' ] );

		self::assertSame( PaymentResult::REQUIRES_ACTION, $result->type );
		self::assertSame( [ 'order_id' => 'PP1' ], $result->payload );
		$call = FakePayPal::calls_to( 'POST', 'v2/checkout/orders' )[0];
		self::assertSame( 'CAPTURE', $call['body']['intent'] );
		self::assertSame( '15.00', $call['body']['purchase_units'][0]['amount']['value'] );
		self::assertSame( 'USD', $call['body']['purchase_units'][0]['amount']['currency_code'] );
		self::assertSame( (string) $txn->id, $call['body']['purchase_units'][0]['custom_id'] );
		self::assertSame( 'https://shop.test/return', $call['body']['payment_source']['paypal']['experience_context']['return_url'] );
		self::assertSame( 'NO_SHIPPING', $call['body']['payment_source']['paypal']['experience_context']['shipping_preference'] );
		self::assertSame( 'ada@example.com', $call['body']['payment_source']['paypal']['email_address'] );
		self::assertSame( 'Lovelace', $call['body']['payment_source']['paypal']['name']['surname'] );
		self::assertSame( $txn->idempotency_key . '-no-shipping', $call['headers']['PayPal-Request-Id'] );
		self::assertSame( 'PP1', $this->payments()->find( $txn->id )?->gateway_reference );

		FakePayPal::$routes['GET v2/checkout/orders/PP1']          = [ 200, $this->order( 'PP1', 'APPROVED', '15.00' ) ];
		FakePayPal::$routes['POST v2/checkout/orders/PP1/capture'] = [ 201, $this->captured( 'PP1', 'CAP1', 'COMPLETED', '15.00' ) ];
		self::assertSame( 'applied', $this->payments()->handle_return( $txn->id, [ 'token' => 'PP1' ] ) );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );
		self::assertSame( 1500, $this->orders()->get( $order->id )->amount_paid );
		self::assertSame( 'fxc-capture-PP1', FakePayPal::calls_to( 'POST', 'v2/checkout/orders/PP1/capture' )[0]['headers']['PayPal-Request-Id'] );
		self::assertSame( '{}', FakePayPal::calls_to( 'POST', 'v2/checkout/orders/PP1/capture' )[0]['raw'] );
	}

	public function test_without_scripts_the_shopper_goes_to_paypal_and_a_closed_window_fails_the_attempt(): void {
		FakePayPal::$routes['POST v2/checkout/orders'] = [ 201, $this->order( 'PP2', 'PAYER_ACTION_REQUIRED', '20.00' ) ];
		[ $order, $txn, $result ]                      = $this->pay( 2000, [] );

		self::assertSame( PaymentResult::REDIRECT, $result->type );
		self::assertSame( 'https://www.sandbox.paypal.com/checkoutnow?token=PP2', $result->redirect_url );

		FakePayPal::$routes['GET v2/checkout/orders/PP2'] = [ 200, $this->order( 'PP2', 'PAYER_ACTION_REQUIRED', '20.00' ) ];
		self::assertSame( 'failed', $this->payments()->handle_return( $txn->id, [] ) );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status, 'the shopper can pay again' );
		self::assertSame( 'cancelled', $this->payments()->find( $txn->id )?->failure_code );
		self::assertSame( [], FakePayPal::calls_to( 'POST', 'v2/checkout/orders/PP2/capture' ) );
	}

	public function test_an_address_paypal_refuses_is_left_off_rather_than_failing_the_payment(): void {
		FakePayPal::$routes['POST v2/checkout/orders'] = [
			[
				422,
				[
					'name'    => 'UNPROCESSABLE_ENTITY',
					'details' => [ [ 'issue' => 'SHIPPING_ADDRESS_INVALID' ] ],
				],
			],
			[ 201, $this->order( 'PP3', 'PAYER_ACTION_REQUIRED', '15.00' ) ],
		];
		$order = $this->place( [ $this->simple( 1500, 5 ) => 1 ], 0, null, OrderStatus::PENDING, PayPalGateway::ID );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			\Flexa\Commerce\Database\Schema::table( 'order_addresses' ),
			[
				'order_id'   => $order->id,
				'type'       => 'shipping',
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'address_1'  => '1 Nowhere',
				'city'       => 'X',
				'postcode'   => '00000',
				'country'    => 'US',
			]
		);
		wp_cache_flush();
		$order = $this->orders()->get( $order->id );
		self::assertNotNull( $order->shipping );
		$txn    = $this->payments()->create_payment( $order, $this->gateway() );
		$result = $this->payments()->initiate( $txn->id, 'https://shop.test/return', 'https://shop.test/cancel', [ 'flow' => 'popup' ] );

		self::assertSame( PaymentResult::REQUIRES_ACTION, $result->type );
		$calls = FakePayPal::calls_to( 'POST', 'v2/checkout/orders' );
		self::assertCount( 2, $calls );
		self::assertSame( 'SET_PROVIDED_ADDRESS', $calls[0]['body']['payment_source']['paypal']['experience_context']['shipping_preference'] );
		self::assertSame( '1 Nowhere', $calls[0]['body']['purchase_units'][0]['shipping']['address']['address_line_1'] );
		self::assertArrayNotHasKey( 'shipping', $calls[1]['body']['purchase_units'][0] );
	}

	public function test_a_declined_capture_fails_the_attempt(): void {
		FakePayPal::$routes['POST v2/checkout/orders']             = [ 201, $this->order( 'PP4', 'PAYER_ACTION_REQUIRED', '15.00' ) ];
		FakePayPal::$routes['GET v2/checkout/orders/PP4']          = [ 200, $this->order( 'PP4', 'APPROVED', '15.00' ) ];
		FakePayPal::$routes['POST v2/checkout/orders/PP4/capture'] = [
			422,
			[
				'name'    => 'UNPROCESSABLE_ENTITY',
				'details' => [
					[
						'issue'       => 'INSTRUMENT_DECLINED',
						'description' => 'The instrument presented was declined.',
					],
				],
			],
		];
		[ $order, $txn ] = $this->pay( 1500, [ 'flow' => 'popup' ] );

		self::assertSame( 'failed', $this->payments()->handle_return( $txn->id, [] ) );
		self::assertSame( 'instrument_declined', $this->payments()->find( $txn->id )?->failure_code );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status );
	}

	public function test_webhooks_are_checked_by_paypal_and_an_approval_without_a_return_is_captured(): void {
		FakePayPal::$routes['POST v2/checkout/orders'] = [ 201, $this->order( 'PP5', 'PAYER_ACTION_REQUIRED', '15.00' ) ];
		[ $order, $txn ]                               = $this->pay( 1500, [] );
		$approved                                      = $this->event( 'WH-EV-1', 'CHECKOUT.ORDER.APPROVED', $this->order( 'PP5', 'APPROVED', '15.00', (string) $txn->id ) );

		FakePayPal::$routes['POST v1/notifications/verify-webhook-signature'] = [ 200, [ 'verification_status' => 'FAILURE' ] ];
		self::assertSame( 401, $this->webhook( $approved ) );
		self::assertSame( 401, $this->webhook( $approved, false ), 'no signature headers' );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status );

		FakePayPal::$routes['POST v1/notifications/verify-webhook-signature'] = [ 200, [ 'verification_status' => 'SUCCESS' ] ];
		FakePayPal::$routes['POST v2/checkout/orders/PP5/capture']            = [ 201, $this->captured( 'PP5', 'CAP5', 'COMPLETED', '15.00' ) ];
		self::assertSame( 200, $this->webhook( $approved ) );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );

		$check = FakePayPal::calls_to( 'POST', 'v1/notifications/verify-webhook-signature' );
		$sent  = end( $check );
		self::assertIsArray( $sent );
		self::assertSame( 'WH-MADE-1', $sent['body']['webhook_id'] );
		self::assertSame( 'tx-1', $sent['body']['transmission_id'] );
		self::assertStringEndsWith( '"webhook_event":' . (string) wp_json_encode( $approved ) . '}', $sent['raw'], 'the body goes back byte for byte' );

		// The capture event that follows changes nothing more.
		$completed = $this->event( 'WH-EV-2', 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( 'CAP5', 'PP5', 'COMPLETED', '15.00' ) );
		self::assertSame( 200, $this->webhook( $completed ) );
		self::assertSame( 1500, $this->orders()->get( $order->id )->amount_paid );
	}

	public function test_a_capture_that_settles_later_pays_by_webhook(): void {
		FakePayPal::$routes['POST v2/checkout/orders']             = [ 201, $this->order( 'PP6', 'PAYER_ACTION_REQUIRED', '15.00' ) ];
		FakePayPal::$routes['GET v2/checkout/orders/PP6']          = [ 200, $this->order( 'PP6', 'APPROVED', '15.00' ) ];
		FakePayPal::$routes['POST v2/checkout/orders/PP6/capture'] = [ 201, $this->captured( 'PP6', 'CAP6', 'PENDING', '15.00' ) ];
		[ $order, $txn ] = $this->pay( 1500, [] );

		$this->payments()->handle_return( $txn->id, [] );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status, 'an eCheck still clearing' );

		FakePayPal::$routes['POST v1/notifications/verify-webhook-signature'] = [ 200, [ 'verification_status' => 'SUCCESS' ] ];
		$completed = $this->event( 'WH-EV-6', 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( 'CAP6', 'PP6', 'COMPLETED', '15.00' ) );
		self::assertSame( 200, $this->webhook( $completed ) );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );
	}

	public function test_a_refund_goes_to_the_capture_and_paypal_refusing_says_why(): void {
		FakePayPal::$routes['POST v2/checkout/orders']             = [ 201, $this->order( 'PP7', 'PAYER_ACTION_REQUIRED', '20.00' ) ];
		FakePayPal::$routes['GET v2/checkout/orders/PP7']          = [
			[ 200, $this->order( 'PP7', 'APPROVED', '20.00' ) ],
			[ 200, $this->captured( 'PP7', 'CAP7', 'COMPLETED', '20.00' ) ],
		];
		FakePayPal::$routes['POST v2/checkout/orders/PP7/capture'] = [ 201, $this->captured( 'PP7', 'CAP7', 'COMPLETED', '20.00' ) ];
		[ $order, $txn ] = $this->pay( 2000, [] );
		self::assertSame( 'applied', $this->payments()->handle_return( $txn->id, [] ) );

		FakePayPal::$routes['POST v2/payments/captures/CAP7/refund'] = [
			[
				201,
				[
					'id'     => 'RF1',
					'status' => 'COMPLETED',
				],
			],
			[
				422,
				[
					'name'    => 'UNPROCESSABLE_ENTITY',
					'details' => [
						[
							'issue'       => 'REFUND_AMOUNT_EXCEEDED',
							'description' => 'The refund amount must be less than or equal to the capture amount that has not yet been refunded.',
						],
					],
				],
			],
		];
		$refund = $this->payments()->refund( $order->id, 500, [], 'Scratched', true, TransitionContext::admin() );
		self::assertSame( Transaction::SUCCEEDED, $refund->status );
		self::assertSame( 'RF1', $refund->gateway_reference );
		$call = FakePayPal::calls_to( 'POST', 'v2/payments/captures/CAP7/refund' )[0];
		self::assertSame( '5.00', $call['body']['amount']['value'] );
		self::assertSame( 'Scratched', $call['body']['note_to_payer'] );
		self::assertSame( $refund->idempotency_key, $call['headers']['PayPal-Request-Id'] );
		self::assertSame( 500, $this->orders()->get( $order->id )->amount_refunded );

		$again = $this->payments()->refund( $order->id, 500, [], '', true, TransitionContext::admin() );
		self::assertSame( Transaction::FAILED, $again->status );
		self::assertSame( 'refund_amount_exceeded', $again->failure_code );
		self::assertSame( 500, $this->orders()->get( $order->id )->amount_refunded );
	}

	public function test_an_imported_paypal_order_refunds_by_its_capture(): void {
		$order = $this->place( [ $this->simple( 3000, 5 ) => 1 ], 0, null, OrderStatus::PROCESSING, PayPalGateway::ID );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			\Flexa\Commerce\Database\Schema::table( 'transactions' ),
			[
				'order_id'          => $order->id,
				'type'              => Transaction::PAYMENT,
				'status'            => Transaction::SUCCEEDED,
				'method'            => Transaction::IMPORT,
				'gateway'           => PayPalGateway::ID,
				'amount'            => 3000,
				'currency'          => 'USD',
				'idempotency_key'   => wp_generate_uuid4(),
				'gateway_reference' => '8MC585209K746392H',
				'created_at'        => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			]
		);
		$wpdb->update( \Flexa\Commerce\Database\Schema::table( 'orders' ), [ 'amount_paid' => 3000 ], [ 'id' => $order->id ] ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		FakePayPal::$routes['POST v2/payments/captures/8MC585209K746392H/refund'] = [
			201,
			[
				'id'     => 'RF-WOO',
				'status' => 'PENDING',
			],
		];

		$refund = $this->payments()->refund( $order->id, 1200, [], '', true, TransitionContext::admin() );

		self::assertSame( Transaction::PENDING, $refund->status, 'PayPal settles it later' );
		self::assertCount( 1, FakePayPal::calls_to( 'POST', 'v2/payments/captures/8MC585209K746392H/refund' ) );

		FakePayPal::$routes['POST v1/notifications/verify-webhook-signature'] = [ 200, [ 'verification_status' => 'SUCCESS' ] ];
		$refunded = $this->event(
			'WH-EV-RF',
			'PAYMENT.CAPTURE.REFUNDED',
			[
				'id'     => 'RF-WOO',
				'status' => 'COMPLETED',
				'amount' => [
					'currency_code' => 'USD',
					'value'         => '12.00',
				],
			]
		);
		self::assertSame( 200, $this->webhook( $refunded ) );
		self::assertSame( 1200, $this->orders()->get( $order->id )->amount_refunded );
	}

	private function payments(): PaymentService {
		return Plugin::instance()->payments()->service();
	}

	private function settings(): GatewaySettings {
		return Plugin::instance()->payments()->gateways()->settings();
	}

	private function gateway(): PayPalGateway {
		$gateway = Plugin::instance()->payments()->gateways()->get( PayPalGateway::ID );
		self::assertInstanceOf( PayPalGateway::class, $gateway );
		return $gateway;
	}

	private function notes(): string {
		return implode( "\n", array_column( $this->gateway()->notes(), 'text' ) );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{0:Order,1:Transaction,2:PaymentResult}
	 */
	private function pay( int $price, array $input ): array {
		$order  = $this->place( [ $this->simple( $price, 5 ) => 1 ], 0, null, OrderStatus::PENDING, PayPalGateway::ID );
		$txn    = $this->payments()->create_payment( $order, $this->gateway() );
		$result = $this->payments()->initiate( $txn->id, 'https://shop.test/return', 'https://shop.test/cancel', $input );
		return [ $order, $txn, $result ];
	}

	/** @return array<string,mixed> */
	private function order( string $id, string $status, string $value, string $custom = '' ): array {
		$unit = [
			'reference_id' => 'default',
			'amount'       => [
				'currency_code' => 'USD',
				'value'         => $value,
			],
		];
		if ( $custom !== '' ) {
			$unit['custom_id'] = $custom;
		}
		return [
			'id'             => $id,
			'intent'         => 'CAPTURE',
			'status'         => $status,
			'purchase_units' => [ $unit ],
			'links'          => [
				[
					'rel'  => 'payer-action',
					'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . $id,
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private function captured( string $id, string $capture, string $status, string $value ): array {
		$order = $this->order( $id, 'COMPLETED', $value );
		$order['purchase_units'][0]['payments']['captures'] = [ $this->capture_resource( $capture, $id, $status, $value ) ];
		return $order;
	}

	/** @return array<string,mixed> */
	private function capture_resource( string $capture, string $order, string $status, string $value ): array {
		return [
			'id'                 => $capture,
			'status'             => $status,
			'amount'             => [
				'currency_code' => 'USD',
				'value'         => $value,
			],
			'supplementary_data' => [ 'related_ids' => [ 'order_id' => $order ] ],
		];
	}

	/**
	 * @param array<string,mixed> $resource
	 * @return array<string,mixed>
	 */
	private function event( string $id, string $type, array $resource ): array {
		return [
			'id'            => $id,
			'event_version' => '1.0',
			'create_time'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'resource_type' => 'checkout-order',
			'event_type'    => $type,
			'resource'      => $resource,
		];
	}

	/** @param array<string,mixed> $event */
	private function webhook( array $event, bool $signed = true ): int {
		$request = new \WP_REST_Request( 'POST', '/flexacommerce/v1/webhooks/paypal' );
		$request->set_body( (string) wp_json_encode( $event ) );
		$request->set_header( 'content-type', 'application/json' );
		if ( $signed ) {
			$request->set_header( 'paypal-auth-algo', 'SHA256withRSA' );
			$request->set_header( 'paypal-cert-url', 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1' );
			$request->set_header( 'paypal-transmission-id', 'tx-1' );
			$request->set_header( 'paypal-transmission-sig', 'c2lnbmF0dXJl' );
			$request->set_header( 'paypal-transmission-time', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		}
		return rest_get_server()->dispatch( $request )->get_status();
	}
}
