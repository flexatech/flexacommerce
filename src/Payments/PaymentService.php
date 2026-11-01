<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

use Flexa\Commerce\Contracts\Exceptions\CurrencyMismatch;
use Flexa\Commerce\Contracts\Exceptions\InvalidRefund;
use Flexa\Commerce\Contracts\Exceptions\InvalidSignature;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\Payments\PaymentEvent;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Contracts\Payments\PaymentRequest;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Contracts\Payments\RefundRequest;
use Flexa\Commerce\Contracts\Payments\RefundResult;
use Flexa\Commerce\Contracts\Payments\ReturnRequest;
use Flexa\Commerce\Database\Transaction as DbTransaction;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\RefundLine;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Transactions and their effect on orders (doc 02 §7.7 to §7.10). Every
 * path that changes a transaction (initiate, webhook, return, manual,
 * refund) goes through apply(), which locks the transaction row, so a
 * webhook racing a redirect return is applied once.
 */
final class PaymentService {
	/** Keys dropped from gateway data before it is stored (doc 04 §3.5). */
	private const DROP_KEYS = '/card|cvc|cvv|pan|secret|password|iban/i';

	public function __construct(
		private readonly GatewayRegistryService $gateways,
		private readonly TransactionRepository $transactions,
		private readonly WebhookEventRepository $webhooks,
		private readonly OrderService $orders,
		private readonly DbTransaction $db,
		private readonly Clock $clock,
		private readonly Logger $log,
		private readonly Redactor $redactor,
	) {}

	public function gateways(): GatewayRegistryService {
		return $this->gateways;
	}

	public function find( int $transaction_id ): ?Transaction {
		return $this->transactions->find( $transaction_id );
	}

	/** @return list<Transaction> newest first */
	public function for_order( int $order_id ): array {
		return $this->transactions->for_order( $order_id );
	}

	/**
	 * Order status a fresh order starts in for this gateway (doc 02 §7.5 e).
	 * Zero totals skip payment and go to processing.
	 */
	public function initial_status( ?PaymentGateway $gateway, int $total ): string {
		if ( $total === 0 ) {
			return OrderStatus::PROCESSING;
		}
		return $gateway instanceof OfflineGateway ? $gateway->order_status() : OrderStatus::PENDING;
	}

	/**
	 * Pending payment row for what the order still owes, so a gift card
	 * already paid at submit is left out (ADR-069). Call inside the checkout
	 * transaction.
	 */
	public function create_payment( Order $order, PaymentGateway $gateway ): Transaction {
		$id = $this->transactions->insert(
			$order->id,
			Transaction::PAYMENT,
			Transaction::PENDING,
			Transaction::GATEWAY,
			$gateway->id(),
			$order->balance_due(),
			$order->currency,
			self::key(),
			$this->clock->db_now()
		);
		return $this->must_find( $id );
	}

	/**
	 * Call the gateway after the checkout transaction committed (doc 02 §7.5
	 * step 6). A failure or an exception here keeps the order pending so
	 * the customer can retry; the transaction row records why.
	 *
	 * @param array<string,mixed> $input gateway fields from the checkout form
	 * @param Order|null          $order the order as the caller's transaction left it, to skip reading it again
	 */
	public function initiate( int $transaction_id, string $return_url, string $cancel_url, array $input = [], ?Order $order = null ): PaymentResult {
		$txn     = $this->must_find( $transaction_id );
		$gateway = $this->gateway_or_fail( $txn->gateway );
		$order   = $order !== null && $order->id === $txn->order_id ? $order : $this->orders->get( $txn->order_id );
		$request = new PaymentRequest(
			$txn->id,
			$txn->idempotency_key,
			$order->view(),
			$txn->money(),
			$return_url,
			$cancel_url,
			rest_url( 'flexacommerce/v1/webhooks/' . $gateway->id() ),
			$input
		);
		try {
			$result = $gateway->process_payment( $request );
		} catch ( \Throwable $e ) {
			$this->log->error(
				'Gateway {gateway} threw while starting payment for order {order_id}',
				[
					'gateway'   => $gateway->id(),
					'order_id'  => $order->id,
					'exception' => $e,
				]
			);
			$result = PaymentResult::failed( 'gateway_error', __( 'The payment provider could not be reached. Please try again.', 'flexacommerce' ) );
		}
		$this->apply_result( $txn, $gateway, $result );
		return $result;
	}

	/**
	 * Apply one event to the transaction it names. Returns what happened:
	 * `applied`, `ignored` (terminal, not allowed, duplicate), `unknown`
	 * (no such transaction) or `amount_mismatch`.
	 */
	public function apply( string $gateway_id, PaymentEvent $event, TransitionContext $ctx, ?int $transaction_id = null ): string {
		return $this->db->run(
			function () use ( $gateway_id, $event, $ctx, $transaction_id ): string {
				$txn = $transaction_id !== null
					? $this->transactions->find( $transaction_id, true )
					: $this->transactions->find_by_reference( $gateway_id, $event->transaction_ref, true );
				if ( $txn === null || $txn->gateway !== $gateway_id ) {
					$this->log->warning(
						'Event {event_id} from {gateway} names no known transaction',
						[
							'event_id' => $event->event_id,
							'gateway'  => $gateway_id,
						]
					);
					return 'unknown';
				}
				return $this->apply_locked( $txn, $event, $ctx );
			}
		);
	}

	/**
	 * Webhook pipeline (doc 02 §7.8). Returns the HTTP status to send: 401 for
	 * a bad signature, 404 for an unknown gateway, 200 otherwise. Other
	 * exceptions bubble up so the endpoint answers 500 and the gateway retries.
	 */
	public function handle_webhook( string $gateway_id, \WP_REST_Request $request ): int {
		$gateway = $this->gateways->has( $gateway_id ) ? $this->gateways->get( $gateway_id ) : null;
		if ( $gateway === null ) {
			return 404;
		}
		try {
			$events = $gateway->parse_webhook( $request );
		} catch ( InvalidSignature $e ) {
			$this->log->warning(
				'Rejected webhook from {gateway}: {reason}',
				[
					'gateway' => $gateway_id,
					'reason'  => $e->getMessage(),
				]
			);
			return 401;
		}
		$ctx = new TransitionContext( 'gateway' );
		foreach ( $events as $event ) {
			$this->db->run(
				function () use ( $gateway_id, $event, $ctx ): void {
					$now   = $this->clock->db_now();
					$claim = $this->webhooks->claim( $gateway_id, $event->event_id, $event->type, $now );
					if ( $claim === 0 ) {
						return;
					}
					$txn    = $this->transactions->find_by_reference( $gateway_id, $event->transaction_ref, true );
					$result = $txn === null ? 'unknown' : $this->apply_locked( $txn, $event, $ctx );
					$this->webhooks->finish( $claim, $result, $txn?->id, $now );
				}
			);
		}
		return 200;
	}

	/**
	 * The customer came back from the gateway. Only a gateway with signed
	 * returns can mark the order paid from here; for the others a success is
	 * a hint and the webhook decides (doc 02 §7.8). A failure fails the
	 * attempt only, like a declined card at checkout: the order stays
	 * pending and the customer can pay again (`failed`, ADR-052).
	 *
	 * @param array<string,mixed> $query
	 */
	public function handle_return( int $transaction_id, array $query ): string {
		$txn     = $this->must_find( $transaction_id );
		$gateway = $this->gateway_or_fail( $txn->gateway );
		$event   = $gateway->verify_return( new ReturnRequest( $txn->id, $txn->gateway_reference, $query ) );
		if ( $event === null ) {
			return 'ignored';
		}
		if ( in_array( $event->type, [ 'succeeded', 'authorized' ], true ) && ! $gateway->supports( 'signed_returns' ) ) {
			return 'ignored';
		}
		if ( $event->type === 'failed' ) {
			return $this->fail_attempt( $txn->id, $event->failure_code, $event->failure_message, new TransitionContext( 'customer' ) ) ? 'failed' : 'ignored';
		}
		return $this->apply( $gateway->id(), $event, new TransitionContext( 'customer' ), $txn->id );
	}

	/** Money received outside the site (cash on delivery, bank transfer). */
	public function record_manual_payment( int $order_id, int $amount, string $note, TransitionContext $ctx ): Transaction {
		return $this->db->run(
			function () use ( $order_id, $amount, $note, $ctx ): Transaction {
				$order = $this->orders->get( $order_id );
				$id    = $this->transactions->insert(
					$order->id,
					Transaction::PAYMENT,
					Transaction::SUCCEEDED,
					Transaction::MANUAL,
					$order->payment_method,
					$amount,
					$order->currency,
					self::key(),
					$this->clock->db_now(),
					null,
					null,
					[],
					$note !== '' ? [ 'note' => sanitize_text_field( $note ) ] : []
				);
				$txn   = $this->must_find( $id );
				$this->orders->record_payment( $order_id, $amount, $ctx->with_message( $note ), $txn->view() );
				return $txn;
			}
		);
	}

	/**
	 * Refund (doc 02 §7.10). Through the gateway: the pending refund rows are
	 * committed first, then each gateway is called outside any database
	 * transaction. A refund larger than one payment is split over the
	 * payments, newest first, so an order paid partly by gift card gets the
	 * card payment back last (ADR-069). Manual: recorded as succeeded at once.
	 * Returns the first refund row.
	 *
	 * @param list<RefundLine> $lines
	 * @param int|null         $payment_id refund only this payment
	 * @throws InvalidRefund
	 */
	public function refund( int $order_id, int $amount, array $lines, string $reason, bool $via_gateway, TransitionContext $ctx, ?int $payment_id = null ): Transaction {
		$reason = sanitize_text_field( $reason );

		if ( ! $via_gateway ) {
			return $this->db->run(
				function () use ( $order_id, $amount, $lines, $reason, $ctx ): Transaction {
					$order = $this->orders->get( $order_id );
					$this->assert_refundable( $order, $amount, $lines );
					[ $rows, $meta ] = self::refund_rows( $order, $lines, $reason );
					$id              = $this->transactions->insert( $order->id, Transaction::REFUND, Transaction::SUCCEEDED, Transaction::MANUAL, $order->payment_method, $amount, $order->currency, self::key(), $this->clock->db_now(), null, null, $rows, $meta );
					$txn             = $this->must_find( $id );
					$this->orders->record_refund( $order_id, $amount, $lines, $reason, $ctx, $txn->view() );
					return $txn;
				}
			);
		}

		$chunks = $this->db->run(
			function () use ( $order_id, $amount, $lines, $reason, $payment_id ): array {
				$order = $this->orders->get( $order_id );
				$this->assert_refundable( $order, $amount, $lines );
				$out = [];
				foreach ( $this->refund_plan( $order, $amount, $payment_id ) as $i => [ $payment, $share ] ) {
					$gateway         = $this->gateway_or_fail( $payment->gateway );
					[ $rows, $meta ] = self::refund_rows( $order, $i === 0 ? $lines : [], $reason );
					$id              = $this->transactions->insert( $order->id, Transaction::REFUND, Transaction::PENDING, Transaction::GATEWAY, $payment->gateway, $share, $order->currency, self::key(), $this->clock->db_now(), $payment->id, null, $rows, $meta );
					$out[]           = [ $this->must_find( $id ), $payment, $gateway ];
				}
				return $out;
			}
		);

		$first = null;
		foreach ( $chunks as [ $refund, $payment, $gateway ] ) {
			$done  = $this->send_refund( $order_id, $refund, $payment, $gateway, $reason, $ctx );
			$first = $first ?? $done;
		}
		return $first ?? throw new InvalidRefund( __( 'No payment on this order can be refunded through its payment method. Record a manual refund instead.', 'flexacommerce' ) );
	}

	private function send_refund( int $order_id, Transaction $refund, Transaction $payment, PaymentGateway $gateway, string $reason, TransitionContext $ctx ): Transaction {
		try {
			$result = $gateway->refund( new RefundRequest( $refund->id, (string) $payment->gateway_reference, $refund->money(), $reason, $refund->idempotency_key ) );
		} catch ( \Throwable $e ) {
			$this->log->error(
				'Gateway {gateway} threw while refunding order {order_id}',
				[
					'gateway'   => $gateway->id(),
					'order_id'  => $order_id,
					'exception' => $e,
				]
			);
			$result = RefundResult::failed( 'gateway_error', __( 'The payment provider could not be reached.', 'flexacommerce' ) );
		}

		$type = match ( $result->type ) {
			'succeeded' => 'refund_succeeded',
			'failed'    => 'refund_failed',
			default     => null,
		};
		if ( $type === null ) {
			if ( $result->reference !== null && $result->reference !== '' ) {
				$this->transactions->set_reference( $refund->id, $result->reference, $this->clock->db_now() );
			}
			$this->orders->payment_note( $order_id, __( 'Refund sent to the payment provider, waiting for confirmation.', 'flexacommerce' ), $ctx );
			return $this->must_find( $refund->id );
		}
		$event = new PaymentEvent( 'refund-' . $refund->id, $type, (string) $result->reference, $refund->money(), '', [], $result->failure_code, $result->failure_message );
		$this->apply( $refund->gateway, $event, $ctx, $refund->id );
		return $this->must_find( $refund->id );
	}

	/** Old webhook dedupe rows (cron). */
	public function purge_webhooks( int $days = 90 ): int {
		return $this->webhooks->purge( $this->clock->db_ago( $days * DAY_IN_SECONDS ) );
	}

	private function apply_result( Transaction $txn, PaymentGateway $gateway, PaymentResult $result ): void {
		$ctx = new TransitionContext( 'gateway' );
		$now = $this->clock->db_now();
		switch ( $result->type ) {
			case PaymentResult::SUCCEEDED:
			case PaymentResult::AUTHORIZED:
				$this->apply( $gateway->id(), new PaymentEvent( 'initiate-' . $txn->id, $result->type, (string) $result->reference, $txn->money() ), $ctx, $txn->id );
				return;
			case PaymentResult::FAILED:
				$this->fail_attempt( $txn->id, $result->failure_code, $result->failure_message, $ctx );
				return;
			case PaymentResult::REQUIRES_ACTION:
				$this->transactions->update_status( $txn->id, Transaction::REQUIRES_ACTION, $now, $result->reference, '', '', $this->clean( $gateway, [ 'action' => $result->payload ] ) );
				return;
			default:
				$data = $result->instructions !== null && $result->instructions !== '' ? [ 'instructions' => $result->instructions ] : [];
				if ( ( $result->reference !== null && $result->reference !== '' ) || $data !== [] ) {
					$this->transactions->update_status( $txn->id, Transaction::PENDING, $now, $result->reference, '', '', $data );
				}
		}
	}

	/**
	 * One attempt failed while the customer is still there. The order stays
	 * pending: the customer can retry (doc 02 §7.5 step 6).
	 */
	private function fail_attempt( int $transaction_id, string $code, string $message, TransitionContext $ctx ): bool {
		return $this->db->run(
			function () use ( $transaction_id, $code, $message, $ctx ): bool {
				$locked = $this->transactions->find( $transaction_id, true );
				if ( $locked === null || ! $locked->can_move_to( Transaction::FAILED ) ) {
					return false;
				}
				$this->transactions->update_status( $locked->id, Transaction::FAILED, $this->clock->db_now(), null, $code !== '' ? $code : 'failed', $message );
				$this->orders->payment_note( $locked->order_id, $this->failure_text( $message ), $ctx );
				return true;
			}
		);
	}

	private function apply_locked( Transaction $txn, PaymentEvent $event, TransitionContext $ctx ): string {
		if ( strtoupper( $event->amount->currency ) !== strtoupper( $txn->currency ) ) {
			throw CurrencyMismatch::between( $event->amount->currency, $txn->currency );
		}
		$refund_event = str_starts_with( $event->type, 'refund_' );
		if ( $refund_event !== ( $txn->type === Transaction::REFUND ) ) {
			return 'ignored';
		}
		$status = match ( $event->type ) {
			'succeeded', 'refund_succeeded' => Transaction::SUCCEEDED,
			'failed', 'refund_failed'       => Transaction::FAILED,
			'authorized'                    => Transaction::AUTHORIZED,
			default                         => Transaction::CANCELLED,
		};
		if ( ! $txn->can_move_to( $status ) ) {
			if ( $txn->status !== $status ) {
				$this->log->warning(
					'Ignored {type} for transaction {id} in status {status}',
					[
						'type'   => $event->type,
						'id'     => $txn->id,
						'status' => $txn->status,
					]
				);
			}
			return 'ignored';
		}
		$gateway = $this->gateways->get( $txn->gateway );
		$now     = $this->clock->db_now();
		$raw     = $gateway !== null ? $this->clean( $gateway, $event->raw ) : [];

		if ( $status === Transaction::SUCCEEDED && $event->amount->amount !== $txn->amount ) {
			$this->transactions->update_status( $txn->id, $txn->status, $now, null, '', '', [ 'received_amount' => $event->amount->amount ] );
			$this->orders->flag_attention(
				$txn->order_id,
				sprintf(
					/* translators: 1: amount reported by the provider, 2: amount expected, both in minor units. */
					__( 'The payment provider reported %1$d but %2$d was expected. Check the payment before shipping.', 'flexacommerce' ),
					$event->amount->amount,
					$txn->amount
				),
				$ctx
			);
			return 'amount_mismatch';
		}

		$this->transactions->update_status( $txn->id, $status, $now, $event->transaction_ref, $event->failure_code, $event->failure_message, $raw === [] ? [] : [ 'event' => $raw ] );
		$view = $this->must_find( $txn->id )->view();

		switch ( $event->type ) {
			case 'succeeded':
				$this->orders->record_payment( $txn->order_id, $txn->amount, $ctx, $view );
				break;
			case 'authorized':
				$this->orders->record_authorized( $txn->order_id, $ctx );
				break;
			case 'failed':
				$this->orders->record_payment_failed( $txn->order_id, $event->failure_message, $ctx );
				break;
			case 'cancelled':
				$this->orders->payment_note( $txn->order_id, __( 'Payment cancelled.', 'flexacommerce' ), $ctx );
				break;
			case 'refund_succeeded':
				$reason = is_string( $txn->data['reason'] ?? null ) ? $txn->data['reason'] : '';
				$this->orders->record_refund( $txn->order_id, $txn->amount, RefundLine::from_array( $txn->refund_lines ), $reason, $ctx, $view );
				break;
			case 'refund_failed':
				$text = $this->failure_text( $event->failure_message, true );
				$this->orders->payment_note( $txn->order_id, $text, $ctx );
				$this->orders->flag_attention( $txn->order_id, $text, $ctx );
				break;
		}
		return 'applied';
	}

	/**
	 * Includes refunds still pending at the gateway, so two refunds sent
	 * back to back cannot return more than was paid.
	 *
	 * @param list<RefundLine> $lines
	 */
	private function assert_refundable( Order $order, int $amount, array $lines ): void {
		$this->orders->assert_refundable( $order, $amount, $lines );
		$pending = 0;
		foreach ( $this->transactions->for_order( $order->id ) as $txn ) {
			if ( $txn->type === Transaction::REFUND && ! $txn->is_terminal() ) {
				$pending += $txn->amount;
			}
		}
		if ( $amount + $pending > $order->refundable() ) {
			throw new InvalidRefund( __( 'Another refund for this order is still waiting for the payment provider.', 'flexacommerce' ) );
		}
	}

	/**
	 * The succeeded gateway payments a refund goes back to and the share of
	 * each, newest payment first. A payment keeps what its earlier refunds
	 * have not taken; one that cannot refund part of itself is used only for
	 * its whole amount.
	 *
	 * @return list<array{0:Transaction,1:int}>
	 */
	private function refund_plan( Order $order, int $amount, ?int $payment_id ): array {
		$all      = $this->transactions->for_order( $order->id );
		$refunded = [];
		foreach ( $all as $txn ) {
			if ( $txn->type === Transaction::REFUND && $txn->parent_id !== null && ! in_array( $txn->status, [ Transaction::FAILED, Transaction::CANCELLED ], true ) ) {
				$refunded[ $txn->parent_id ] = ( $refunded[ $txn->parent_id ] ?? 0 ) + $txn->amount;
			}
		}
		$plan    = [];
		$left    = $amount;
		$partial = false;
		foreach ( array_reverse( $all ) as $txn ) {
			if ( $left <= 0 ) {
				break;
			}
			if ( $txn->type !== Transaction::PAYMENT || $txn->status !== Transaction::SUCCEEDED || ! in_array( $txn->method, [ Transaction::GATEWAY, Transaction::IMPORT ], true ) ) {
				continue;
			}
			if ( $payment_id !== null && $txn->id !== $payment_id ) {
				continue;
			}
			$gateway = $this->gateways->get( $txn->gateway );
			if ( $gateway === null || ! $gateway->supports( 'refunds' ) || $txn->gateway_reference === null ) {
				continue;
			}
			$room = $txn->amount - ( $refunded[ $txn->id ] ?? 0 );
			if ( $room <= 0 ) {
				continue;
			}
			$share = min( $room, $left );
			if ( $share < $txn->amount && ! $gateway->supports( 'partial_refunds' ) ) {
				$partial = true;
				continue;
			}
			$plan[] = [ $txn, $share ];
			$left  -= $share;
		}
		if ( $left > 0 ) {
			throw new InvalidRefund(
				$partial
					? __( 'This payment method can only refund the full amount.', 'flexacommerce' )
					: __( 'No payment on this order can be refunded through its payment method. Record a manual refund instead.', 'flexacommerce' )
			);
		}
		return $plan;
	}

	/**
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	private function clean( PaymentGateway $gateway, array $data ): array {
		return $this->redactor->context( $this->drop( $gateway->redact( $data ) ) );
	}

	/**
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	private function drop( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( preg_match( self::DROP_KEYS, (string) $key ) === 1 ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = $this->drop( $value );
			}
		}
		return $data;
	}

	private function failure_text( string $message, bool $refund = false ): string {
		if ( $refund ) {
			return $message !== ''
				/* translators: %s: reason given by the payment provider. */
				? sprintf( __( 'Refund failed: %s', 'flexacommerce' ), $message )
				: __( 'Refund failed.', 'flexacommerce' );
		}
		return $message !== ''
			/* translators: %s: reason given by the payment provider. */
			? sprintf( __( 'Payment attempt failed: %s', 'flexacommerce' ), $message )
			: __( 'Payment attempt failed.', 'flexacommerce' );
	}

	private function gateway_or_fail( string $id ): PaymentGateway {
		return $this->gateways->get( $id ) ?? throw new \UnexpectedValueException( sprintf( 'The payment gateway "%s" is not registered.', $id ) );
	}

	/**
	 * The refund lines as stored on the transaction, each with its tax
	 * split, and the meta with the refunded tax per rate (doc 02 §7.12.10).
	 *
	 * @param list<RefundLine> $lines
	 * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
	 */
	private static function refund_rows( Order $order, array $lines, string $reason ): array {
		$rows  = [];
		$taxes = [];
		foreach ( $lines as $line ) {
			$row  = [
				'item_id' => $line->order_item_id,
				'qty'     => $line->qty,
				'amount'  => $line->amount,
				'restock' => $line->restock,
			];
			$item = $order->item( $line->order_item_id );
			if ( $item !== null ) {
				$split = $item->refund_split( $line->amount );
				if ( $split['taxes'] !== [] ) {
					$row['taxes'] = $split['taxes'];
					foreach ( $split['taxes'] as $key => $tax ) {
						$taxes[ $key ] = ( $taxes[ $key ] ?? 0 ) + $tax;
					}
				}
			}
			$rows[] = $row;
		}
		$meta = $reason !== '' ? [ 'reason' => $reason ] : [];
		if ( $taxes !== [] ) {
			$meta['taxes'] = $taxes;
		}
		return [ $rows, $meta ];
	}

	private function must_find( int $id ): Transaction {
		$txn = $this->transactions->find( $id );
		if ( $txn === null ) {
			throw new \RuntimeException( sprintf( 'Transaction %d not found.', $id ) );
		}
		return $txn;
	}

	private static function key(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}
