<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Payments;

use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeClient;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeGateway;
use Flexa\Commerce\Payments\PaymentService;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * The Stripe gateway against a table of Stripe answers (FakeStripe). The
 * contract test at the end runs against stripe-mock when FXC_STRIPE_MOCK
 * holds its URL, like http://localhost:12111.
 */
final class StripeGatewayTest extends OrderTestCase {
	private const KEYS = [
		'enabled'              => true,
		'mode'                 => 'test',
		'test_publishable_key' => 'pk_test_abc123',
		'test_secret_key'      => 'sk_test_abc123',
	];

	public function set_up(): void {
		parent::set_up();
		FakeStripe::install();
		FakeStripe::connectable();
		$this->settings()->save( $this->gateway(), self::KEYS );
	}

	public function tear_down(): void {
		FakeStripe::uninstall();
		delete_option( GatewaySettings::PREFIX . StripeGateway::ID );
		delete_option( StripeGateway::STATUS_OPTION );
		FakeStripe::forget( StripeGateway::ID );
		parent::tear_down();
	}

	public function test_saving_keys_reads_the_account_and_creates_the_webhook(): void {
		$values = $this->settings()->values( $this->gateway() );
		self::assertSame( 'whsec_made_for_you', $values['test_webhook_secret'] );

		$created = FakeStripe::calls_to( 'POST', 'webhook_endpoints' );
		self::assertCount( 1, $created );
		self::assertSame( rest_url( 'flexacommerce/v1/webhooks/stripe' ), $created[0]['body']['url'] );
		self::assertContains( 'payment_intent.succeeded', $created[0]['body']['enabled_events'] );
		self::assertSame( StripeClient::API_VERSION, $created[0]['body']['api_version'] );
		self::assertSame( 'Bearer sk_test_abc123', $created[0]['headers']['Authorization'] );

		$notes = $this->notes();
		self::assertStringContainsString( 'Connected to the Stripe account Hopper Goods in test mode.', $notes );
		self::assertStringContainsString( 'Stripe sends payment updates to', $notes );

		FakeStripe::$calls = [];
		$this->settings()->save( $this->gateway(), [ 'title' => 'Card' ] );
		self::assertSame( [], FakeStripe::$calls, 'the same keys are not checked again' );
		self::assertStringContainsString( 'Hopper Goods', $this->notes() );
	}

	public function test_an_old_endpoint_for_this_site_is_replaced(): void {
		FakeStripe::$routes['GET webhook_endpoints'] = [
			200,
			[
				'data' => [
					[
						'id'  => 'we_old',
						'url' => rest_url( 'flexacommerce/v1/webhooks/stripe' ),
					],
					[
						'id'  => 'we_other',
						'url' => 'https://other.test/hook',
					],
				],
			],
		];
		FakeStripe::$calls                           = [];
		$this->settings()->save( $this->gateway(), [ 'test_secret_key' => 'sk_test_new456' ] );

		self::assertCount( 1, FakeStripe::calls_to( 'DELETE', 'webhook_endpoints/we_old' ) );
		self::assertSame( [], FakeStripe::calls_to( 'DELETE', 'webhook_endpoints/we_other' ) );
		self::assertCount( 1, FakeStripe::calls_to( 'POST', 'webhook_endpoints' ) );
	}

	public function test_a_key_in_the_wrong_field_or_refused_by_stripe_saves_nothing(): void {
		foreach ( [
			'test_secret_key'      => 'pk_test_wrong',
			'test_publishable_key' => 'pk_live_wrong',
		] as $field => $key ) {
			try {
				$this->settings()->save( $this->gateway(), [ $field => $key ] );
				self::fail( 'Saved ' . $key . ' in ' . $field );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( 'this key should start with', $e->getMessage() );
			}
		}

		FakeStripe::$routes['GET account'] = [
			401,
			[
				'error' => [
					'type'    => 'invalid_request_error',
					'message' => 'Invalid API Key provided: sk_test_***9999',
				],
			],
		];
		try {
			$this->settings()->save( $this->gateway(), [ 'test_secret_key' => 'sk_test_9999' ] );
			self::fail( 'Saved a key Stripe refused.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( 'Stripe did not accept the test secret key: Invalid API Key', $e->getMessage() );
		}
		$stored = get_option( GatewaySettings::PREFIX . StripeGateway::ID );
		self::assertIsArray( $stored );
		self::assertSame( 'sk_test_abc123', $stored['test_secret_key'] );
	}

	public function test_the_admin_endpoint_answers_400_for_a_refused_key_and_lists_the_notes(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new \WP_REST_Request( 'PATCH', '/flexacommerce/v1/admin/payments/gateways/stripe' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'values' => [ 'test_secret_key' => 'pk_test_wrong' ] ] ) );
		$response = rest_get_server()->dispatch( $request );
		self::assertSame( 400, $response->get_status() );
		self::assertStringContainsString( 'should start with sk_test_', (string) $response->as_error()->get_error_message() );

		$list = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/payments/gateways' ) );
		self::assertSame( 200, $list->get_status() );
		$stripe = array_values( array_filter( (array) $list->get_data(), static fn ( array $g ): bool => $g['id'] === 'stripe' ) )[0];
		self::assertSame( 'success', $stripe['notes'][0]['tone'] );
		self::assertSame( '••••c123', $stripe['values']['test_secret_key'] );
		self::assertSame( [], array_filter( (array) get_option( StripeGateway::STATUS_OPTION ), static fn ( mixed $e ): bool => is_array( $e ) && isset( $e['secret'] ) ) );
		wp_set_current_user( 0 );
	}

	public function test_when_the_webhook_cannot_be_made_the_note_says_how_to_add_it(): void {
		delete_option( GatewaySettings::PREFIX . StripeGateway::ID );
		FakeStripe::forget( StripeGateway::ID );
		FakeStripe::$routes['POST webhook_endpoints'] = [
			403,
			[
				'error' => [
					'type'    => 'invalid_request_error',
					'message' => 'The provided key does not have the required permissions.',
				],
			],
		];
		$this->settings()->save( $this->gateway(), self::KEYS + [ 'test_secret_key' => 'rk_test_limited' ] );

		$notes = $this->notes();
		self::assertStringContainsString( 'Stripe could not add the webhook: The provided key does not have the required permissions.', $notes );
		self::assertStringContainsString( rest_url( 'flexacommerce/v1/webhooks/stripe' ), $notes );
		self::assertStringContainsString( 'checkout.session.async_payment_succeeded', $notes );
		self::assertSame( '', $this->settings()->values( $this->gateway() )['test_webhook_secret'] );
	}

	public function test_the_checkout_offers_the_payment_element_with_its_public_config(): void {
		$context = new \Flexa\Commerce\Contracts\Payments\CheckoutContext( new \Flexa\Commerce\Contracts\Money( 1999, 'USD' ), 'US', 'US', true, 'flat_rate:1', null, 1 );
		self::assertTrue( $this->gateway()->is_available( $context ) );
		$fields = $this->gateway()->checkout_fields( $context );
		self::assertStringContainsString( 'assets/frontend/stripe.js', (string) $fields->script_module );
		self::assertSame( 'pk_test_abc123', $fields->data['key'] );
		self::assertSame( 1999, $fields->data['amount'] );
		self::assertSame( 'usd', $fields->data['currency'] );
		self::assertSame( 0, $fields->data['shift'] );
		self::assertStringNotContainsString( 'sk_test', (string) wp_json_encode( $fields->data ) );

		$yen = new \Flexa\Commerce\Contracts\Payments\CheckoutContext( new \Flexa\Commerce\Contracts\Money( 1999, 'JPY' ), 'JP', 'JP', true, '', null, 1 );
		self::assertTrue( $this->gateway()->is_available( $yen ) );
	}

	public function test_a_card_payment_confirms_the_intent_and_pays_the_order(): void {
		FakeStripe::$routes['POST payment_intents'] = [ 200, $this->intent( 'pi_ok', 'succeeded', 1500 ) ];
		[ $order, $txn, $result ]                   = $this->pay(
			1500,
			[
				'payment_method'      => 'pm_card_visa',
				'payment_method_type' => 'card',
			]
		);

		self::assertSame( PaymentResult::SUCCEEDED, $result->type );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );
		$call = FakeStripe::calls_to( 'POST', 'payment_intents' )[0];
		self::assertSame( '1500', $call['body']['amount'] );
		self::assertSame( 'usd', $call['body']['currency'] );
		self::assertSame( 'pm_card_visa', $call['body']['payment_method'] );
		self::assertSame( 'true', $call['body']['confirm'] );
		self::assertSame( (string) $order->id, $call['body']['metadata']['order_id'] );
		self::assertSame( (string) $txn->id, $call['body']['metadata']['transaction_id'] );
		self::assertArrayNotHasKey( 'mandate_data', $call['body'] );
		self::assertSame( $txn->idempotency_key, $call['headers']['Idempotency-Key'] );
		self::assertSame( 'pi_ok', $this->payments()->find( $txn->id )?->gateway_reference );
	}

	public function test_a_bank_debit_sends_the_mandate_and_waits_for_the_webhook(): void {
		FakeStripe::$routes['POST payment_intents'] = [ 200, $this->intent( 'pi_sepa', 'processing', 1500 ) ];
		[ $order, $txn ]                            = $this->pay(
			1500,
			[
				'payment_method'      => 'pm_sepa',
				'payment_method_type' => 'sepa_debit',
			]
		);

		$call = FakeStripe::calls_to( 'POST', 'payment_intents' )[0];
		self::assertSame( 'online', $call['body']['mandate_data']['customer_acceptance']['type'] );
		self::assertSame( Transaction::PENDING, $this->payments()->find( $txn->id )?->status );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status );

		// Days later the debit bounces: the order fails and the stock comes back.
		$failed = $this->event(
			'evt_sepa_fail',
			'payment_intent.payment_failed',
			$this->intent(
				'pi_sepa',
				'requires_payment_method',
				1500,
				[
					'last_payment_error' => [
						'code'           => 'insufficient_funds',
						'message'        => 'The account has insufficient funds.',
						'payment_method' => [ 'type' => 'sepa_debit' ],
					],
				]
			)
		);
		self::assertSame( 200, $this->webhook( $failed ) );
		self::assertSame( OrderStatus::FAILED, $this->orders()->get( $order->id )->status );
	}

	public function test_3d_secure_goes_back_through_the_return_and_keeps_no_client_secret(): void {
		FakeStripe::$routes['POST payment_intents'] = [ 200, $this->intent( 'pi_3ds', 'requires_action', 1500, [ 'client_secret' => 'pi_3ds_secret_xyz' ] ) ];
		[ $order, $txn, $result ]                   = $this->pay( 1500, [ 'payment_method' => 'pm_card_3ds' ] );

		self::assertSame( PaymentResult::REQUIRES_ACTION, $result->type );
		self::assertSame( 'pi_3ds_secret_xyz', $result->payload['client_secret'] );
		$stored = $this->payments()->find( $txn->id );
		self::assertNotNull( $stored );
		self::assertSame( 'pi_3ds', $stored->gateway_reference );
		self::assertStringNotContainsString( 'secret_xyz', (string) wp_json_encode( $stored ) );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . \Flexa\Commerce\Database\Schema::table( 'transactions' ) . ' WHERE id = %d', $txn->id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		self::assertStringNotContainsString( 'secret_xyz', (string) wp_json_encode( $row ) );

		FakeStripe::$routes['GET payment_intents/pi_3ds'] = [ 200, $this->intent( 'pi_3ds', 'succeeded', 1500 ) ];
		self::assertSame( 'applied', $this->payments()->handle_return( $txn->id, [] ) );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );
		$checkout = Plugin::instance()->checkout()->service();
		self::assertSame( '', $checkout->last_failure( $this->orders()->get( $order->id ) ) );
	}

	public function test_a_failed_3d_secure_return_keeps_the_order_open_and_tells_the_shopper(): void {
		FakeStripe::$routes['POST payment_intents']          = [ 200, $this->intent( 'pi_3ds_no', 'requires_action', 1500, [ 'client_secret' => 's' ] ) ];
		FakeStripe::$routes['GET payment_intents/pi_3ds_no'] = [
			200,
			$this->intent(
				'pi_3ds_no',
				'requires_payment_method',
				1500,
				[
					'last_payment_error' => [
						'code'    => 'payment_intent_authentication_failure',
						'message' => 'We are unable to authenticate your payment method.',
					],
				]
			),
		];
		[ $order, $txn ]                                     = $this->pay( 1500, [ 'payment_method' => 'pm_card_3ds' ] );

		self::assertSame( 'failed', $this->payments()->handle_return( $txn->id, [] ) );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status, 'the shopper can try again' );
		$stored = $this->payments()->find( $txn->id );
		self::assertSame( Transaction::FAILED, $stored?->status );
		self::assertSame( 'payment_intent_authentication_failure', $stored?->failure_code );
		self::assertSame( 'We are unable to authenticate your payment method.', Plugin::instance()->checkout()->service()->last_failure( $this->orders()->get( $order->id ) ) );
		self::assertSame( 'ignored', $this->payments()->handle_return( $txn->id, [] ), 'a second return changes nothing' );
	}

	public function test_a_declined_card_fails_the_attempt_with_stripes_message(): void {
		FakeStripe::$routes['POST payment_intents'] = [
			402,
			[
				'error' => [
					'type'         => 'card_error',
					'code'         => 'card_declined',
					'decline_code' => 'insufficient_funds',
					'message'      => 'Your card has insufficient funds.',
				],
			],
		];
		[ $order, $txn, $result ]                   = $this->pay( 1500, [ 'payment_method' => 'pm_card_chargeDeclined' ] );

		self::assertSame( PaymentResult::FAILED, $result->type );
		self::assertSame( 'Your card has insufficient funds.', $result->failure_message );
		$stored = $this->payments()->find( $txn->id );
		self::assertSame( 'insufficient_funds', $stored?->failure_code );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status );
		self::assertSame( 'Your card has insufficient funds.', Plugin::instance()->checkout()->service()->last_failure( $this->orders()->get( $order->id ) ) );
	}

	public function test_a_stripe_outage_is_a_generic_gateway_error(): void {
		FakeStripe::$routes['POST payment_intents'] = [
			500,
			[
				'error' => [
					'type'    => 'api_error',
					'message' => 'Internal details the shopper should not see.',
				],
			],
		];
		[ $order, $txn, $result ]                   = $this->pay( 1500, [ 'payment_method' => 'pm_card_visa' ] );

		self::assertSame( 'gateway_error', $result->failure_code );
		self::assertStringNotContainsString( 'Internal details', $result->failure_message );
		self::assertSame( Transaction::FAILED, $this->payments()->find( $txn->id )?->status );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status );
	}

	public function test_without_a_payment_method_the_shopper_pays_on_a_checkout_session(): void {
		FakeStripe::$routes['POST checkout/sessions'] = [
			200,
			[
				'id'  => 'cs_test_1',
				'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
			],
		];
		[ $order, $txn, $result ]                     = $this->pay( 2500, [] );

		self::assertSame( PaymentResult::REDIRECT, $result->type );
		self::assertSame( 'https://checkout.stripe.com/c/pay/cs_test_1', $result->redirect_url );
		$call = FakeStripe::calls_to( 'POST', 'checkout/sessions' )[0];
		self::assertSame( '2500', $call['body']['line_items'][0]['price_data']['unit_amount'] );
		self::assertSame( 'https://shop.test/return', $call['body']['success_url'] );
		self::assertSame( 'https://shop.test/cancel', $call['body']['cancel_url'] );
		self::assertSame( (string) $txn->id, $call['body']['client_reference_id'] );
		self::assertSame( '1', $call['body']['payment_intent_data']['metadata']['fxc_session'] );
		self::assertSame( $txn->idempotency_key, $call['headers']['Idempotency-Key'] );

		FakeStripe::$routes['GET checkout/sessions/cs_test_1'] = [
			[
				200,
				[
					'id'             => 'cs_test_1',
					'payment_status' => 'unpaid',
					'amount_total'   => 2500,
					'currency'       => 'usd',
				],
			],
			[
				200,
				[
					'id'             => 'cs_test_1',
					'payment_status' => 'paid',
					'amount_total'   => 2500,
					'currency'       => 'usd',
					'payment_intent' => 'pi_from_session',
				],
			],
		];
		self::assertSame( 'ignored', $this->payments()->handle_return( $txn->id, [] ), 'not paid yet' );
		self::assertSame( 'applied', $this->payments()->handle_return( $txn->id, [] ) );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );

		// A refund finds the payment intent behind the session.
		FakeStripe::$routes['POST refunds'] = [
			200,
			[
				'id'     => 're_1',
				'status' => 'succeeded',
				'amount' => 1000,
			],
		];
		$refund                             = $this->payments()->refund( $order->id, 1000, [], 'Late', true, TransitionContext::admin() );
		self::assertSame( Transaction::SUCCEEDED, $refund->status );
		self::assertSame( 're_1', $refund->gateway_reference );
		$call = FakeStripe::calls_to( 'POST', 'refunds' )[0];
		self::assertSame( 'pi_from_session', $call['body']['payment_intent'] );
		self::assertSame( '1000', $call['body']['amount'] );
		self::assertSame( (string) $refund->id, $call['body']['metadata']['refund_transaction_id'] );
		self::assertSame( $refund->idempotency_key, $call['headers']['Idempotency-Key'] );
	}

	public function test_signed_webhooks_pay_once_and_bad_signatures_are_refused(): void {
		FakeStripe::$routes['POST payment_intents'] = [ 200, $this->intent( 'pi_hook', 'processing', 1500 ) ];
		[ $order ]                                  = $this->pay( 1500, [ 'payment_method' => 'pm_x' ] );
		$event                                      = $this->event( 'evt_ok', 'payment_intent.succeeded', $this->intent( 'pi_hook', 'succeeded', 1500 ) );

		self::assertSame( 401, $this->webhook( $event, 'whsec_someone_else' ) );
		self::assertSame( 401, $this->webhook( $event, null, time() - 600 ), 'a replay from ten minutes ago' );
		self::assertSame( 401, $this->webhook( $event, '' ), 'no header' );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status );

		// During a secret roll Stripe signs with the old and the new secret.
		self::assertSame( 200, $this->webhook( $event, null, null, true ) );
		self::assertSame( 200, $this->webhook( $event ) );
		$order = $this->orders()->get( $order->id );
		self::assertSame( OrderStatus::PROCESSING, $order->status );
		self::assertSame( 1500, $order->amount_paid );
	}

	public function test_webhooks_skip_card_retries_and_the_sessions_own_intent(): void {
		FakeStripe::$routes['POST payment_intents'] = [ 200, $this->intent( 'pi_card', 'processing', 1500 ) ];
		[ $order ]                                  = $this->pay( 1500, [ 'payment_method' => 'pm_x' ] );

		$declined = $this->intent(
			'pi_card',
			'requires_payment_method',
			1500,
			[
				'last_payment_error' => [
					'code'           => 'card_declined',
					'payment_method' => [ 'type' => 'card' ],
				],
			]
		);
		self::assertSame( 200, $this->webhook( $this->event( 'evt_decl', 'payment_intent.payment_failed', $declined ) ) );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status, 'the shopper may still try another card' );

		$session_intent = $this->intent( 'pi_card', 'succeeded', 1500, [ 'metadata' => [ 'fxc_session' => '1' ] ] );
		self::assertSame( 200, $this->webhook( $this->event( 'evt_sess_pi', 'payment_intent.succeeded', $session_intent ) ) );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status );
	}

	public function test_a_pending_refund_settles_by_webhook(): void {
		FakeStripe::$routes['POST payment_intents'] = [ 200, $this->intent( 'pi_r', 'succeeded', 2000 ) ];
		FakeStripe::$routes['POST refunds']         = [
			200,
			[
				'id'     => 're_slow',
				'status' => 'pending',
			],
		];
		[ $order ]                                  = $this->pay( 2000, [ 'payment_method' => 'pm_x' ] );
		$refund                                     = $this->payments()->refund( $order->id, 500, [], '', true, TransitionContext::admin() );
		self::assertSame( Transaction::PENDING, $refund->status );

		$settled = [
			'id'       => 're_slow',
			'object'   => 'refund',
			'status'   => 'succeeded',
			'amount'   => 500,
			'currency' => 'usd',
		];
		self::assertSame( 200, $this->webhook( $this->event( 'evt_re', 'charge.refund.updated', $settled ) ) );
		self::assertSame( 500, $this->orders()->get( $order->id )->amount_refunded );
	}

	public function test_stripe_refusing_a_refund_tells_the_merchant_why(): void {
		FakeStripe::$routes['POST payment_intents'] = [ 200, $this->intent( 'pi_twice', 'succeeded', 2000 ) ];
		FakeStripe::$routes['POST refunds']         = [
			400,
			[
				'error' => [
					'type'    => 'invalid_request_error',
					'code'    => 'charge_already_refunded',
					'message' => 'Charge ch_1 has already been refunded.',
				],
			],
		];
		[ $order ]                                  = $this->pay( 2000, [ 'payment_method' => 'pm_x' ] );
		$refund                                     = $this->payments()->refund( $order->id, 2000, [], '', true, TransitionContext::admin() );

		self::assertSame( Transaction::FAILED, $refund->status );
		self::assertSame( 'charge_already_refunded', $refund->failure_code );
		self::assertSame( 0, $this->orders()->get( $order->id )->amount_refunded );
	}

	public function test_an_imported_stripe_order_refunds_by_its_charge(): void {
		$order = $this->place( [ $this->simple( 3000, 5 ) => 1 ], 0, null, OrderStatus::PROCESSING, StripeGateway::ID );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			\Flexa\Commerce\Database\Schema::table( 'transactions' ),
			[
				'order_id'          => $order->id,
				'type'              => Transaction::PAYMENT,
				'status'            => Transaction::SUCCEEDED,
				'method'            => Transaction::IMPORT,
				'gateway'           => StripeGateway::ID,
				'amount'            => 3000,
				'currency'          => 'USD',
				'idempotency_key'   => wp_generate_uuid4(),
				'gateway_reference' => 'ch_woo_1',
				'created_at'        => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			]
		);
		$wpdb->update( \Flexa\Commerce\Database\Schema::table( 'orders' ), [ 'amount_paid' => 3000 ], [ 'id' => $order->id ] ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		FakeStripe::$routes['POST refunds'] = [
			200,
			[
				'id'     => 're_woo',
				'status' => 'succeeded',
			],
		];

		$refund = $this->payments()->refund( $order->id, 1200, [], '', true, TransitionContext::admin() );

		self::assertSame( Transaction::SUCCEEDED, $refund->status );
		$call = FakeStripe::calls_to( 'POST', 'refunds' )[0];
		self::assertSame( 'ch_woo_1', $call['body']['charge'] );
		self::assertArrayNotHasKey( 'payment_intent', $call['body'] );
	}

	/**
	 * The same calls against stripe-mock, which checks every request
	 * against Stripe's OpenAPI spec and answers with fixtures.
	 */
	public function test_contract_with_stripe_mock(): void {
		$base = (string) getenv( 'FXC_STRIPE_MOCK' );
		if ( $base === '' ) {
			self::markTestSkipped( 'Set FXC_STRIPE_MOCK to the stripe-mock URL, like http://localhost:12111.' );
		}
		FakeStripe::uninstall();
		$gateway = new StripeGateway( $base );
		$gateway->configure( self::KEYS );
		$gateway->connect( self::KEYS, [] );
		$status = get_option( StripeGateway::STATUS_OPTION );
		self::assertIsArray( $status );
		self::assertNotSame( '', $status['test']['account'] );
		// The fixture has no signing secret (Stripe sends it on creation only), but the request passed the spec.
		self::assertSame( 'Stripe did not return the webhook signing secret.', $status['test']['error'] );

		$order = $this->place( [ $this->simple( 1500, 5 ) => 1 ], 0, null, OrderStatus::PENDING, StripeGateway::ID );
		$view  = $order->view();
		$money = new \Flexa\Commerce\Contracts\Money( 1500, 'USD' );
		// A request the spec refuses throws; the fixtures' own statuses say nothing.
		$intent = $gateway->process_payment(
			new \Flexa\Commerce\Contracts\Payments\PaymentRequest(
				1,
				wp_generate_uuid4(),
				$view,
				$money,
				'https://shop.test/r',
				'https://shop.test/c',
				'https://shop.test/w',
				[
					'payment_method'      => 'pm_card_visa',
					'payment_method_type' => 'sepa_debit',
				]
			)
		);
		self::assertNotSame( 'gateway_error', $intent->failure_code );
		$session = $gateway->process_payment( new \Flexa\Commerce\Contracts\Payments\PaymentRequest( 1, wp_generate_uuid4(), $view, $money, 'https://shop.test/r', 'https://shop.test/c', 'https://shop.test/w' ) );
		self::assertSame( PaymentResult::REDIRECT, $session->type );
		self::assertStringStartsWith( 'cs_', (string) $session->reference );
		foreach ( [ 'pi_123', 'cs_123' ] as $ref ) {
			$gateway->verify_return( new \Flexa\Commerce\Contracts\Payments\ReturnRequest( 1, $ref, [] ) );
		}
		foreach ( [ 'pi_123', 'ch_123' ] as $ref ) {
			$refund = $gateway->refund( new \Flexa\Commerce\Contracts\Payments\RefundRequest( 2, $ref, $money, 'Test', wp_generate_uuid4() ) );
			self::assertNotSame( 'failed', $refund->type, $refund->failure_message );
		}
	}

	private function payments(): PaymentService {
		return Plugin::instance()->payments()->service();
	}

	private function settings(): GatewaySettings {
		return Plugin::instance()->payments()->gateways()->settings();
	}

	private function gateway(): StripeGateway {
		$gateway = Plugin::instance()->payments()->gateways()->get( StripeGateway::ID );
		self::assertInstanceOf( StripeGateway::class, $gateway );
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
		$order  = $this->place( [ $this->simple( $price, 5 ) => 1 ], 0, null, OrderStatus::PENDING, StripeGateway::ID );
		$txn    = $this->payments()->create_payment( $order, $this->gateway() );
		$result = $this->payments()->initiate( $txn->id, 'https://shop.test/return', 'https://shop.test/cancel', $input );
		return [ $order, $txn, $result ];
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function intent( string $id, string $status, int $amount, array $extra = [] ): array {
		return $extra + [
			'id'              => $id,
			'object'          => 'payment_intent',
			'status'          => $status,
			'amount'          => $amount,
			'amount_received' => $status === 'succeeded' ? $amount : 0,
			'currency'        => 'usd',
			'livemode'        => false,
		];
	}

	/**
	 * @param array<string,mixed> $object
	 * @return array<string,mixed>
	 */
	private function event( string $id, string $type, array $object ): array {
		return [
			'id'       => $id,
			'object'   => 'event',
			'type'     => $type,
			'created'  => time(),
			'livemode' => false,
			'data'     => [ 'object' => $object ],
		];
	}

	/**
	 * Deliver a Stripe event to the webhook route and return the status.
	 *
	 * @param array<string,mixed> $event
	 * @param string|null         $secret null for the stored one; '' sends no signature
	 */
	private function webhook( array $event, ?string $secret = null, ?int $time = null, bool $two = false ): int {
		$body     = (string) wp_json_encode( $event );
		$time   ??= time();
		$secret ??= (string) $this->settings()->values( $this->gateway() )['test_webhook_secret'];
		$request = new \WP_REST_Request( 'POST', '/flexacommerce/v1/webhooks/stripe' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		if ( $secret !== '' ) {
			$header = 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
			if ( $two ) {
				$header = 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, 'whsec_old' ) . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
			}
			$request->set_header( 'stripe-signature', $header );
		}
		return rest_get_server()->dispatch( $request )->get_status();
	}
}
