<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Payments;

use Flexa\Commerce\Contracts\Exceptions\DuplicateRegistration;
use Flexa\Commerce\Contracts\Exceptions\InvalidRefund;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Payments\CheckoutContext;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\RefundLine;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Payments\PaymentService;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

final class PaymentServiceTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		$gateways = $this->payments()->gateways();
		if ( ! $gateways->has( FakeGateway::ID ) ) {
			$gateways->register( FakeGateway::ID, static fn (): PaymentGateway => new FakeGateway() );
		}
		$fake = $gateways->get( FakeGateway::ID );
		self::assertNotNull( $fake );
		$gateways->settings()->save(
			$fake,
			[
				'enabled'        => true,
				'webhook_secret' => FakeGateway::SECRET,
			]
		);
		FakeGateway::$payment_mode = 'redirect';
		FakeGateway::$refund_mode  = 'succeeded';
	}

	public function tear_down(): void {
		foreach ( [ 'cod', 'bacs', FakeGateway::ID ] as $id ) {
			delete_option( GatewaySettings::PREFIX . $id );
		}
		parent::tear_down();
	}

	public function test_registry_rejects_bad_reserved_and_duplicate_ids(): void {
		$gateways = $this->payments()->gateways();
		$factory  = static fn (): PaymentGateway => new FakeGateway();
		foreach ( [ 'Bad-Id', '', str_repeat( 'a', 65 ) ] as $id ) {
			try {
				$gateways->register( $id, $factory );
				self::fail( 'Accepted ' . $id );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( 'Invalid gateway id', $e->getMessage() );
			}
		}
		foreach ( [ 'cod', 'bacs', 'fxc_card', FakeGateway::ID ] as $id ) {
			try {
				$gateways->register( $id, $factory );
				self::fail( 'Accepted ' . $id );
			} catch ( DuplicateRegistration $e ) {
				self::assertNotSame( '', $e->getMessage() );
			}
		}
		self::assertSame( [ 'cod', 'bacs' ], array_slice( $gateways->ids(), 0, 2 ) );
	}

	public function test_secrets_are_masked_and_survive_a_masked_save(): void {
		$gateways = $this->payments()->gateways();
		$fake     = $gateways->get( FakeGateway::ID );
		self::assertNotNull( $fake );
		$settings = $gateways->settings();
		$masked   = \Flexa\Commerce\Support\FieldValues::masked( $settings->fields( $fake ), $settings->values( $fake ) );
		self::assertSame( '••••1234', $masked['webhook_secret'] );

		$settings->save(
			$fake,
			[
				'webhook_secret' => '••••1234',
				'title'          => '<b>Card</b>',
				'unknown'        => 'x',
			]
		);
		$values = $settings->values( $fake );
		self::assertSame( FakeGateway::SECRET, $values['webhook_secret'] );
		self::assertSame( 'Card', $values['title'] );
		self::assertArrayNotHasKey( 'unknown', $values );
	}

	public function test_available_lists_enabled_gateways_only(): void {
		$context = new CheckoutContext( new Money( 1000, 'USD' ), 'US', 'US', true, 'flat_rate:1', null, 1 );
		$ids     = array_keys( $this->payments()->gateways()->available( $context ) );
		self::assertContains( 'cod', $ids, 'cod is on by default' );
		self::assertNotContains( 'bacs', $ids, 'bacs is off by default' );
		self::assertContains( FakeGateway::ID, $ids );
	}

	public function test_offline_gateways_start_in_their_status_and_bacs_shows_instructions(): void {
		$service = $this->payments();
		$bacs    = $service->gateways()->get( 'bacs' );
		self::assertNotNull( $bacs );
		$service->gateways()->settings()->save(
			$bacs,
			[
				'enabled'        => true,
				'account_number' => '12345678',
				'instructions'   => 'Pay with reference {order_number}.',
			]
		);
		self::assertSame( OrderStatus::ON_HOLD, $service->initial_status( $bacs, 1000 ) );
		self::assertSame( OrderStatus::PROCESSING, $service->initial_status( $service->gateways()->get( 'cod' ), 1000 ) );
		self::assertSame( OrderStatus::PROCESSING, $service->initial_status( $bacs, 0 ) );

		$order  = $this->place( [ $this->simple( 1000, 5 ) => 1 ], 0, null, OrderStatus::ON_HOLD, 'bacs' );
		$txn    = $service->create_payment( $order, $bacs );
		$result = $service->initiate( $txn->id, 'https://shop.test/r', 'https://shop.test/c' );

		self::assertSame( 'pending', $result->type );
		self::assertStringContainsString( 'Pay with reference ' . $order->order_number . '.', (string) $result->instructions );
		self::assertStringContainsString( 'Account number: 12345678', (string) $result->instructions );
		$stored = $service->find( $txn->id );
		self::assertNotNull( $stored );
		self::assertSame( Transaction::PENDING, $stored->status );
		self::assertSame( $result->instructions, $stored->data['instructions'] );
	}

	public function test_manual_payment_marks_the_order_paid(): void {
		$order = $this->place( [ $this->simple( 1000, 5 ) => 2 ], 0, null, OrderStatus::ON_HOLD, 'bacs' );
		$txn   = $this->payments()->record_manual_payment( $order->id, 2000, 'Transfer seen in the bank', TransitionContext::admin() );

		self::assertSame( Transaction::MANUAL, $txn->method );
		self::assertSame( Transaction::SUCCEEDED, $txn->status );
		$order = $this->orders()->get( $order->id );
		self::assertSame( OrderStatus::PROCESSING, $order->status );
		self::assertTrue( $order->is_paid() );
	}

	public function test_signed_webhook_pays_once_even_when_delivered_twice(): void {
		[ $order, $txn ] = $this->start_online( 1500 );
		$paid            = 0;
		add_action(
			'flexacommerce.order.paid',
			static function () use ( &$paid ): void {
				++$paid;
			}
		);
		$payload = $this->event( 'evt_1', 'succeeded', $txn, 1500 );

		self::assertSame( 200, $this->webhook( $payload ) );
		self::assertSame( 200, $this->webhook( $payload ) );

		$order = $this->orders()->get( $order->id );
		self::assertSame( OrderStatus::PROCESSING, $order->status );
		self::assertSame( 1500, $order->amount_paid );
		self::assertSame( 1, $paid );
		$stored = $this->payments()->find( $txn->id );
		self::assertNotNull( $stored );
		self::assertSame( Transaction::SUCCEEDED, $stored->status );
		self::assertNotNull( $stored->completed_at );
		self::assertArrayNotHasKey( 'card_last4', $stored->data['event'], 'card data is dropped' );
		self::assertSame( 'ok', $stored->data['event']['status'] );
		$row = Plugin::instance()->payments()->webhooks()->find( FakeGateway::ID, 'evt_1' );
		self::assertNotNull( $row );
	}

	public function test_bad_signature_and_unknown_gateway(): void {
		[ $order, $txn ] = $this->start_online( 1500 );
		$request         = FakeGateway::signed( $this->event( 'evt_bad', 'succeeded', $txn, 1500 ) );
		$request->set_header( 'x-fake-signature', 'nope' );

		self::assertSame( 401, $this->payments()->handle_webhook( FakeGateway::ID, $request ) );
		self::assertSame( 404, $this->payments()->handle_webhook( 'nobody', $request ) );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status );
		self::assertNull( Plugin::instance()->payments()->webhooks()->find( FakeGateway::ID, 'evt_bad' ) );
	}

	public function test_failed_after_succeeded_is_ignored(): void {
		[ $order, $txn ] = $this->start_online( 1500 );
		$this->webhook( $this->event( 'evt_ok', 'succeeded', $txn, 1500 ) );
		$this->webhook( $this->event( 'evt_late', 'failed', $txn, 1500 ) );

		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );
		$row = Plugin::instance()->payments()->webhooks()->find( FakeGateway::ID, 'evt_late' );
		self::assertNotNull( $row );
		self::assertSame( 'ignored', $row['result'] );
	}

	public function test_failed_payment_fails_a_pending_order_and_releases_stock(): void {
		$product         = $this->simple( 1000, 5 );
		[ $order, $txn ] = $this->start_online( 1000, $product );
		self::assertSame( 4, $this->stock_of( $product ) );

		$this->webhook( $this->event( 'evt_f', 'failed', $txn, 1000 ) );

		self::assertSame( OrderStatus::FAILED, $this->orders()->get( $order->id )->status );
		self::assertSame( 5, $this->stock_of( $product ) );
	}

	public function test_amount_mismatch_flags_the_order_and_does_not_pay(): void {
		[ $order, $txn ] = $this->start_online( 1500 );
		$this->webhook( $this->event( 'evt_low', 'succeeded', $txn, 100 ) );

		$order = $this->orders()->get( $order->id );
		self::assertSame( OrderStatus::PENDING, $order->status );
		self::assertSame( 0, $order->amount_paid );
		self::assertTrue( $order->needs_attention );
		$stored = $this->payments()->find( $txn->id );
		self::assertNotNull( $stored );
		self::assertSame( Transaction::PENDING, $stored->status );
	}

	public function test_currency_mismatch_answers_500_and_can_be_retried(): void {
		[ $order, $txn ]     = $this->start_online( 1500 );
		$payload             = $this->event( 'evt_eur', 'succeeded', $txn, 1500 );
		$payload['currency'] = 'EUR';

		$response = rest_get_server()->dispatch( FakeGateway::signed( $payload ) );
		self::assertSame( 500, $response->get_status() );
		self::assertNull( Plugin::instance()->payments()->webhooks()->find( FakeGateway::ID, 'evt_eur' ), 'the dedupe row rolled back' );
		self::assertSame( 0, $this->orders()->get( $order->id )->amount_paid );
	}

	public function test_rest_route_dispatches_to_the_gateway(): void {
		[ $order, $txn ] = $this->start_online( 1500 );
		$response        = rest_get_server()->dispatch( FakeGateway::signed( $this->event( 'evt_rest', 'succeeded', $txn, 1500 ) ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );
		$unknown = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/flexacommerce/v1/webhooks/nobody' ) );
		self::assertSame( 404, $unknown->get_status() );
	}

	public function test_unsigned_return_does_not_mark_paid(): void {
		[ $order, $txn ] = $this->start_online( 1500 );
		self::assertSame( 'ignored', $this->payments()->handle_return( $txn->id, [ 'amount' => 1500 ] ) );
		self::assertSame( 0, $this->orders()->get( $order->id )->amount_paid );
	}

	public function test_immediate_success_and_gateway_errors(): void {
		FakeGateway::$payment_mode = 'succeeded';
		[ $order ]                 = $this->start_online( 1500 );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->get( $order->id )->status );

		FakeGateway::$payment_mode = 'throw';
		[ $order, $txn ]           = $this->start_online( 1500 );
		$stored                    = $this->payments()->find( $txn->id );
		self::assertNotNull( $stored );
		self::assertSame( Transaction::FAILED, $stored->status );
		self::assertSame( 'gateway_error', $stored->failure_code );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status, 'the customer can retry' );
	}

	public function test_gateway_refund_restocks_and_ends_refunded(): void {
		$product         = $this->simple( 1000, 5 );
		[ $order, $txn ] = $this->start_online( 1000, $product );
		$this->webhook( $this->event( 'evt_p', 'succeeded', $txn, 1000 ) );
		$item = $this->orders()->get( $order->id )->items[0];

		$refund = $this->payments()->refund( $order->id, 1000, [ new RefundLine( $item->id, 1, 1000, true ) ], 'Changed mind', true, TransitionContext::admin() );

		self::assertSame( Transaction::SUCCEEDED, $refund->status );
		self::assertSame( $txn->id, $refund->parent_id );
		self::assertSame( 're_' . $refund->id, $refund->gateway_reference );
		$order = $this->orders()->get( $order->id );
		self::assertSame( OrderStatus::REFUNDED, $order->status );
		self::assertSame( 5, $this->stock_of( $product ) );
	}

	public function test_pending_gateway_refund_completes_by_webhook_and_blocks_a_second_refund(): void {
		[ $order, $txn ] = $this->start_online( 1000 );
		$this->webhook( $this->event( 'evt_p2', 'succeeded', $txn, 1000 ) );
		FakeGateway::$refund_mode = 'pending';

		$refund = $this->payments()->refund( $order->id, 600, [], '', true, TransitionContext::admin() );
		self::assertSame( Transaction::PENDING, $refund->status );
		try {
			$this->payments()->refund( $order->id, 600, [], '', false, TransitionContext::admin() );
			self::fail( 'Refunded more than paid while a refund was pending.' );
		} catch ( InvalidRefund $e ) {
			self::assertNotSame( '', $e->getMessage() );
		}

		$this->webhook( $this->event( 'evt_r', 'refund_succeeded', $refund, 600 ) );
		$order = $this->orders()->get( $order->id );
		self::assertSame( 600, $order->amount_refunded );
		self::assertSame( OrderStatus::PROCESSING, $order->status );
	}

	public function test_failed_gateway_refund_flags_the_order(): void {
		[ $order, $txn ] = $this->start_online( 1000 );
		$this->webhook( $this->event( 'evt_p3', 'succeeded', $txn, 1000 ) );
		FakeGateway::$refund_mode = 'failed';

		$refund = $this->payments()->refund( $order->id, 1000, [], '', true, TransitionContext::admin() );

		self::assertSame( Transaction::FAILED, $refund->status );
		$order = $this->orders()->get( $order->id );
		self::assertSame( 0, $order->amount_refunded );
		self::assertTrue( $order->needs_attention );
	}

	public function test_manual_refund_and_gateway_refund_without_payment(): void {
		$order = $this->place( [ $this->simple( 1000, 5 ) => 1 ] );
		$this->payments()->record_manual_payment( $order->id, 1000, '', TransitionContext::admin() );
		try {
			$this->payments()->refund( $order->id, 500, [], '', true, TransitionContext::admin() );
			self::fail( 'cod cannot refund online' );
		} catch ( InvalidRefund $e ) {
			self::assertStringContainsString( 'manual refund', $e->getMessage() );
		}
		$refund = $this->payments()->refund( $order->id, 500, [], 'Damaged box', false, TransitionContext::admin() );
		self::assertSame( Transaction::MANUAL, $refund->method );
		self::assertSame( 500, $this->orders()->get( $order->id )->amount_refunded );
	}

	private function payments(): PaymentService {
		return Plugin::instance()->payments()->service();
	}

	/** @return array{0:Order,1:Transaction} */
	private function start_online( int $price, ?int $product = null ): array {
		$order   = $this->place( [ $product ?? $this->simple( $price, 5 ) => 1 ], 0, null, OrderStatus::PENDING, FakeGateway::ID );
		$gateway = $this->payments()->gateways()->get( FakeGateway::ID );
		self::assertNotNull( $gateway );
		$txn = $this->payments()->create_payment( $order, $gateway );
		$this->payments()->initiate( $txn->id, 'https://shop.test/r', 'https://shop.test/c' );
		$txn = $this->payments()->find( $txn->id );
		self::assertNotNull( $txn );
		return [ $order, $txn ];
	}

	/** @return array<string,mixed> */
	private function event( string $id, string $type, Transaction $txn, int $amount ): array {
		return [
			'id'     => $id,
			'type'   => $type,
			'ref'    => (string) $txn->gateway_reference,
			'amount' => $amount,
		];
	}

	/** @param array<string,mixed> $payload */
	private function webhook( array $payload ): int {
		return $this->payments()->handle_webhook( FakeGateway::ID, FakeGateway::signed( $payload ) );
	}
}
