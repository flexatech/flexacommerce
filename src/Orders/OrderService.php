<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidOrderRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidRefund;
use Flexa\Commerce\Contracts\Exceptions\InvalidTransition;
use Flexa\Commerce\Contracts\Exceptions\OrderNotFound;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Contracts\Payments\TransactionView;
use Flexa\Commerce\Database\AfterCommit;
use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Inventory\OptionStockRepository;
use Flexa\Commerce\Inventory\StockService;
use Flexa\Commerce\Inventory\StockUnit;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Order lifecycle (doc 02 §7.4 to §7.10). Every change locks the order row,
 * writes order_history and fires its public actions after commit. This
 * service never calls gateways or the mailer; PaymentService calls it, and
 * Notifications listen to its actions.
 */
final class OrderService {
	/** @var array<int,OrderView> views built for the after-commit actions of the current write */
	private array $views = [];

	/** @param ?\Closure(int):void $release_coupons gives back the coupon uses of a cancelled order, in its transaction */
	public function __construct(
		private readonly OrderRepository $orders,
		private readonly HistoryRepository $history,
		private readonly StockService $stock,
		private readonly Transaction $transaction,
		private readonly AfterCommit $after_commit,
		private readonly Clock $clock,
		private readonly OrderNumber $numbers,
		private readonly ?\Closure $release_coupons = null,
		private readonly ?OptionStockRepository $option_stock = null,
	) {}

	public function find( int $order_id ): ?Order {
		return $this->orders->find( $order_id );
	}

	/** @throws OrderNotFound */
	public function get( int $order_id ): Order {
		return $this->orders->find( $order_id ) ?? throw new OrderNotFound( __( 'Order not found.', 'flexacommerce' ) );
	}

	public function find_by_checkout_token( string $token ): ?Order {
		return $this->orders->find_by_checkout_token( $token );
	}

	/** Allocate the next order number. Must run before the checkout transaction opens. */
	public function next_number(): string {
		return $this->numbers->next();
	}

	/**
	 * Insert an order and take its stock, all or nothing. When the checkout
	 * token was already used, the existing order is returned and nothing is
	 * written (a double submit).
	 *
	 * @throws InsufficientStock
	 * @throws \Flexa\Commerce\Contracts\Exceptions\OrderTotalsMismatch
	 */
	public function create( NewOrder $new, string $number, TransitionContext $ctx ): Order {
		$new->assert_totals();
		if ( ! in_array( $new->status, S::INITIAL, true ) ) {
			throw new InvalidTransition( 'new', $new->status );
		}
		$this->views = [];

		return $this->transaction->run(
			function () use ( $new, $number, $ctx ): Order {
				$now      = $this->clock->db_now();
				$order_id = $this->orders->insert( $new, $number, OrderAccess::new_key(), $now );
				if ( $order_id === 0 ) {
					// A parallel submit committed this token after our snapshot, so only a locking read finds it.
					// Its items may still be hidden by the snapshot: callers re-read the order after commit.
					return $this->orders->find_by_checkout_token( (string) $new->checkout_token, true ) ?? throw new OrderNotFound( 'The order for this checkout token disappeared.' );
				}
				$order = $this->orders->find( $order_id, true ) ?? throw new OrderNotFound( 'The new order could not be read back.' );
				$this->deduct( $order, $ctx );

				$status = $this->initial_status( $order );
				if ( $status !== $order->status ) {
					$this->orders->update_status( $order_id, $status, $now );
				}
				$this->history->add(
					$order_id,
					'status',
					$ctx->message !== '' ? $ctx->message : __( 'Order placed.', 'flexacommerce' ),
					$now,
					$ctx->actor_type,
					$ctx->actor_id,
					false,
					null,
					$status
				);
				if ( $new->attention !== null ) {
					$this->flag( $order, $new->attention, $ctx );
				}
				if ( $new->created_via !== 'migration' ) {
					$this->emit(
						$order_id,
						static function ( OrderView $view ): void {
							do_action( 'flexacommerce.order.created', $view );
						}
					);
					// The new lines tell without reading the order back whether it holds only downloads.
					if ( $status === S::PROCESSING && self::only_downloads( array_column( array_filter( $new->items, static fn ( NewOrderItem $i ): bool => $i->type === OrderItem::PRODUCT ), 'meta' ) ) ) {
						$this->complete_if_nothing_to_ship( $this->lock( $order_id ), $ctx );
					}
				}
				$order = $this->orders->find( $order_id ) ?? throw new OrderNotFound( 'The new order could not be read back.' );
				// Nothing else writes the new order before the commit, so the created action uses this read.
				$this->views[ $order_id ] = $order->view();
				return $order;
			}
		);
	}

	/**
	 * Move an order to another status (doc 02 §7.6). Same status is a no-op.
	 *
	 * @throws InvalidTransition
	 * @throws InsufficientStock when a released order must take its stock again
	 */
	public function transition( int $order_id, string $to, TransitionContext $ctx ): Order {
		$this->views = [];
		return $this->transaction->run(
			function () use ( $order_id, $to, $ctx ): Order {
				$order = $this->lock( $order_id );
				if ( $order->status === $to ) {
					return $order;
				}
				$this->assert_allowed( $order, $to );
				$this->apply_transition( $order, $to, $ctx );
				return $this->orders->find( $order_id ) ?? $order;
			}
		);
	}

	public function cancel( int $order_id, TransitionContext $ctx ): Order {
		return $this->transition( $order_id, S::CANCELLED, $ctx );
	}

	public function add_note( int $order_id, string $message, bool $customer_visible, TransitionContext $ctx ): int {
		$message = trim( wp_kses_post( $message ) );
		if ( $message === '' ) {
			throw new InvalidOrderRequest( __( 'A note cannot be empty.', 'flexacommerce' ), 'note' );
		}
		$this->views = [];
		return $this->transaction->run(
			function () use ( $order_id, $message, $customer_visible, $ctx ): int {
				$this->lock( $order_id );
				$id = $this->history->add( $order_id, 'note', $message, $this->clock->db_now(), $ctx->actor_type, $ctx->actor_id, $customer_visible );
				$this->emit(
					$order_id,
					static function ( OrderView $view ) use ( $id, $customer_visible ): void {
						do_action( 'flexacommerce.order.note_added', $view, $id, $customer_visible );
					}
				);
				return $id;
			}
		);
	}

	/**
	 * Add a received payment (doc 02 §7.7). Once the order is paid in full,
	 * pending, on-hold and failed orders move to processing. A failed order
	 * whose stock is gone goes on hold and needs attention; a cancelled order
	 * keeps its status and needs attention so the admin refunds.
	 *
	 * Runs inside the caller's transaction when there is one.
	 */
	public function record_payment( int $order_id, int $amount, TransitionContext $ctx, ?TransactionView $txn = null ): Order {
		if ( $amount <= 0 ) {
			throw new InvalidOrderRequest( __( 'A payment must be more than zero.', 'flexacommerce' ), 'amount' );
		}
		$this->views = [];
		return $this->transaction->run(
			function () use ( $order_id, $amount, $ctx, $txn ): Order {
				$order   = $this->lock( $order_id );
				$now     = $this->clock->db_now();
				$paid    = $order->amount_paid + $amount;
				$payment = S::payment_status( $order->total, $paid, $order->amount_refunded );
				$this->orders->update_payment( $order_id, $paid, $order->amount_refunded, $payment, $now, $order->paid_at ?? $now );
				$this->history->add(
					$order_id,
					'payment',
					$ctx->message !== '' ? $ctx->message : sprintf(
						/* translators: %s: amount as a decimal number. */
						__( 'Payment of %s received.', 'flexacommerce' ),
						$order->money( $amount )->to_decimal_string( $order->currency_exponent )
					),
					$now,
					$ctx->actor_type,
					$ctx->actor_id,
					false,
					null,
					null,
					[
						'amount'         => $amount,
						'transaction_id' => $txn?->id,
					]
				);
				$fully_paid = $paid >= $order->total;
				if ( $fully_paid && $order->payment_status !== S::PAID && $txn !== null ) {
					$this->emit(
						$order_id,
						static function ( OrderView $view ) use ( $txn ): void {
							do_action( 'flexacommerce.order.paid', $view, $txn );
						}
					);
				}

				if ( $fully_paid ) {
					$order = $this->lock( $order_id );
					if ( $order->status === S::CANCELLED ) {
						$this->flag( $order, __( 'Payment received for a cancelled order. Refund it or reopen it by hand.', 'flexacommerce' ), $ctx );
					} elseif ( in_array( $order->status, [ S::PENDING, S::ON_HOLD, S::FAILED ], true ) ) {
						try {
							$this->apply_transition( $order, S::PROCESSING, $ctx->with_message( '' ) );
							$this->complete_if_nothing_to_ship( $this->lock( $order_id ), $ctx );
						} catch ( InsufficientStock $e ) {
							// On hold without its stock: the admin restocks or refunds.
							$this->apply_transition( $order, S::ON_HOLD, $ctx->with_message( __( 'Payment received but the stock is gone; the order holds no stock.', 'flexacommerce' ) ), false, false );
							$this->flag( $this->lock( $order_id ), __( 'Payment received but there was not enough stock to fulfil the order.', 'flexacommerce' ), $ctx );
						}
					}
				}
				return $this->orders->find( $order_id ) ?? $order;
			}
		);
	}

	/** A payment attempt failed: pending orders become failed, others only get a note. */
	public function record_payment_failed( int $order_id, string $reason, TransitionContext $ctx ): Order {
		$this->views = [];
		return $this->transaction->run(
			function () use ( $order_id, $reason, $ctx ): Order {
				$order = $this->lock( $order_id );
				$text  = $reason !== '' ? sprintf(
					/* translators: %s: reason given by the payment provider. */
					__( 'Payment failed: %s', 'flexacommerce' ),
					$reason
				) : __( 'Payment failed.', 'flexacommerce' );
				if ( $order->status === S::PENDING ) {
					$this->apply_transition( $order, S::FAILED, $ctx->with_message( $text ) );
				} else {
					$this->history->add( $order_id, 'payment', $text, $this->clock->db_now(), $ctx->actor_type, $ctx->actor_id );
				}
				return $this->orders->find( $order_id ) ?? $order;
			}
		);
	}

	/** Funds authorized, capture pending: pending orders go on hold. */
	public function record_authorized( int $order_id, TransitionContext $ctx ): Order {
		$this->views = [];
		return $this->transaction->run(
			function () use ( $order_id, $ctx ): Order {
				$order = $this->lock( $order_id );
				if ( $order->status === S::PENDING ) {
					$this->apply_transition( $order, S::ON_HOLD, $ctx->with_message( __( 'Payment authorized, capture pending.', 'flexacommerce' ) ) );
				}
				return $this->orders->find( $order_id ) ?? $order;
			}
		);
	}

	/** A history row of type payment, with no status or totals change. */
	public function payment_note( int $order_id, string $message, TransitionContext $ctx ): void {
		$this->history->add( $order_id, 'payment', $message, $this->clock->db_now(), $ctx->actor_type, $ctx->actor_id );
	}

	/**
	 * @param list<RefundLine> $lines
	 * @throws InvalidRefund
	 */
	public function assert_refundable( Order $order, int $amount, array $lines ): void {
		if ( $amount <= 0 ) {
			throw new InvalidRefund( __( 'A refund must be more than zero.', 'flexacommerce' ) );
		}
		if ( $amount > $order->refundable() ) {
			throw new InvalidRefund( __( 'The refund is more than what was paid and not yet refunded.', 'flexacommerce' ) );
		}
		$seen = [];
		foreach ( $lines as $line ) {
			$item = $order->item( $line->order_item_id );
			if ( $item === null || isset( $seen[ $line->order_item_id ] ) ) {
				throw new InvalidRefund( __( 'A refund line does not belong to this order.', 'flexacommerce' ) );
			}
			$seen[ $line->order_item_id ] = true;
			if ( $line->qty > $item->refundable_qty() || ( $line->restock && $item->type !== OrderItem::PRODUCT ) ) {
				throw new InvalidRefund(
					sprintf(
						/* translators: %s: item name. */
						__( 'Too many units of "%s" in the refund.', 'flexacommerce' ),
						$item->name
					)
				);
			}
			if ( $line->amount < 0 || $line->amount > $item->total + $item->tax_total - $item->refunded_total ) {
				throw new InvalidRefund(
					sprintf(
						/* translators: %s: item name. */
						__( 'The refund amount for "%s" is more than the item cost.', 'flexacommerce' ),
						$item->name
					)
				);
			}
		}
	}

	/**
	 * Apply a succeeded refund (doc 02 §7.10): sums, per-line quantities,
	 * the restock the admin chose, and `refunded` once everything paid was
	 * returned.
	 *
	 * @param list<RefundLine> $lines
	 */
	public function record_refund( int $order_id, int $amount, array $lines, string $reason, TransitionContext $ctx, ?TransactionView $txn = null ): Order {
		$this->views = [];
		return $this->transaction->run(
			function () use ( $order_id, $amount, $lines, $reason, $ctx, $txn ): Order {
				$order = $this->lock( $order_id );
				$this->assert_refundable( $order, $amount, $lines );
				$now      = $this->clock->db_now();
				$restock  = [];
				$restocks = [];
				foreach ( $lines as $line ) {
					$item = $order->item( $line->order_item_id );
					if ( $item === null ) {
						continue;
					}
					$data  = [
						'refunded_qty'   => $item->refunded_qty + $line->qty,
						'refunded_total' => $item->refunded_total + $line->amount,
					];
					$split = $item->refund_split( $line->amount );
					if ( $split['taxes'] !== [] ) {
						$done = $item->refunded_taxes();
						foreach ( $split['taxes'] as $key => $tax ) {
							$done[ $key ] = ( $done[ $key ] ?? 0 ) + $tax;
						}
						$data['meta'] = [ 'refunded_taxes' => $done ] + $item->meta;
					}
					$held = $item->option_held();
					$put  = $line->restock ? min( $line->qty, $held ) : 0;
					if ( $put > 0 && $this->option_stock !== null ) {
						$this->option_stock->put_back( array_fill_keys( array_keys( $item->option_stock() ), $put ) );
						$data['meta'] = [ 'option_held' => $held - $put ] + ( $data['meta'] ?? $item->meta );
					}
					$back = $line->restock ? min( $line->qty, $item->stock_reduced_qty ) : 0;
					if ( $back > 0 && $item->stock_id() > 0 ) {
						$restock[]                 = new StockUnit( $item->stock_id(), (int) $item->product_id, (int) $item->variant_id, $back );
						$data['stock_reduced_qty'] = $item->stock_reduced_qty - $back;
						$data['restocked_qty']     = $item->restocked_qty + $back;
						$restocks[]                = [
							'item_id' => $item->id,
							'qty'     => $back,
						];
					}
					$this->orders->update_item( $item->id, $data );
				}
				if ( $restock !== [] ) {
					$this->stock->release_units( $restock, 'refund', $order_id );
					$this->history->add( $order_id, 'stock', __( 'Refunded items returned to stock.', 'flexacommerce' ), $now, $ctx->actor_type, $ctx->actor_id, false, null, null, [ 'lines' => $restocks ] );
				}

				$refunded = $order->amount_refunded + $amount;
				$this->orders->update_payment( $order_id, $order->amount_paid, $refunded, S::payment_status( $order->total, $order->amount_paid, $refunded ), $now );
				$this->history->add(
					$order_id,
					'refund',
					sprintf(
						/* translators: 1: amount as a decimal number, 2: reason or empty. */
						__( 'Refunded %1$s. %2$s', 'flexacommerce' ),
						$order->money( $amount )->to_decimal_string( $order->currency_exponent ),
						$reason
					),
					$now,
					$ctx->actor_type,
					$ctx->actor_id,
					true,
					null,
					null,
					[
						'amount'         => $amount,
						'transaction_id' => $txn?->id,
					]
				);
				if ( $txn !== null ) {
					$this->emit(
						$order_id,
						static function ( OrderView $view ) use ( $txn ): void {
							do_action( 'flexacommerce.order.refunded', $view, $txn );
						}
					);
				}
				if ( $refunded >= $order->amount_paid && OrderStateMachine::allows( $order->status, S::REFUNDED ) ) {
					$this->apply_transition( $this->lock( $order_id ), S::REFUNDED, $ctx->with_message( '' ) );
				}
				return $this->orders->find( $order_id ) ?? $order;
			}
		);
	}

	public function flag_attention( int $order_id, string $reason, TransitionContext $ctx ): Order {
		$this->views = [];
		return $this->transaction->run(
			function () use ( $order_id, $reason, $ctx ): Order {
				$order = $this->lock( $order_id );
				$this->flag( $order, $reason, $ctx );
				return $this->orders->find( $order_id ) ?? $order;
			}
		);
	}

	/**
	 * Privacy eraser (doc 06 §2.8): names, emails, phones, addresses, the IP
	 * and note text are blanked in place; amounts and lines stay for the
	 * books. False when the order was anonymized already.
	 */
	public function anonymize( int $order_id, TransitionContext $ctx ): bool {
		$this->views = [];
		return $this->transaction->run(
			function () use ( $order_id, $ctx ): bool {
				$order = $this->lock( $order_id );
				if ( $order->anonymized_at !== null ) {
					return false;
				}
				$now = $this->clock->db_now();
				$this->orders->anonymize( $order_id, $now );
				$this->history->add( $order_id, 'system', __( 'Personal data removed.', 'flexacommerce' ), $now, $ctx->actor_type, $ctx->actor_id );
				$this->emit( $order_id, static fn ( OrderView $view ) => do_action( 'flexacommerce.order.anonymized', $view ) );
				return true;
			}
		);
	}

	public function clear_attention( int $order_id, TransitionContext $ctx ): Order {
		return $this->transaction->run(
			function () use ( $order_id, $ctx ): Order {
				$order = $this->lock( $order_id );
				if ( $order->needs_attention ) {
					$now = $this->clock->db_now();
					$this->orders->set_needs_attention( $order_id, false, $now );
					$this->history->add( $order_id, 'system', __( 'Marked as reviewed.', 'flexacommerce' ), $now, $ctx->actor_type, $ctx->actor_id );
				}
				return $this->orders->find( $order_id ) ?? $order;
			}
		);
	}

	public function rotate_key( int $order_id, TransitionContext $ctx ): string {
		return $this->transaction->run(
			function () use ( $order_id, $ctx ): string {
				$this->lock( $order_id );
				$key = OrderAccess::new_key();
				$now = $this->clock->db_now();
				$this->orders->set_order_key( $order_id, $key, $now );
				$this->history->add( $order_id, 'system', __( 'Order access key rotated; earlier links no longer work.', 'flexacommerce' ), $now, $ctx->actor_type, $ctx->actor_id );
				return $key;
			}
		);
	}

	/** The customer picked another gateway on the order-pay page. Only pending orders. */
	public function change_payment_method( int $order_id, string $method, string $title, TransitionContext $ctx ): Order {
		return $this->transaction->run(
			function () use ( $order_id, $method, $title, $ctx ): Order {
				$order = $this->lock( $order_id );
				if ( $order->status !== S::PENDING ) {
					throw new InvalidTransition( $order->status, S::PENDING );
				}
				if ( $order->payment_method === $method ) {
					return $order;
				}
				$now = $this->clock->db_now();
				$this->orders->update(
					$order_id,
					[
						'payment_method'       => $method,
						'payment_method_title' => $title,
						'updated_at'           => $now,
					]
				);
				/* translators: %s: payment method title. */
				$this->history->add( $order_id, 'payment', sprintf( __( 'Payment method changed to %s.', 'flexacommerce' ), $title ), $now, $ctx->actor_type, $ctx->actor_id );
				return $this->orders->find( $order_id ) ?? $order;
			}
		);
	}

	/**
	 * Cancel pending orders older than the hold time (cron, doc 02 §6.6).
	 * Each order is its own transaction, so one failure does not stop the
	 * batch. Returns how many were cancelled.
	 */
	public function expire_pending( int $limit = 200 ): int {
		$minutes   = max( 1, Settings::int( 'hold_minutes' ) );
		$now       = $this->clock->now();
		$cancelled = 0;
		foreach ( $this->orders->stale_pending( $this->clock->db_ago( $minutes * 60 ), $limit ) as $order ) {
			$hold = apply_filters( 'flexacommerce.order.pending_hold_minutes', $minutes, $order->view() );
			$hold = is_int( $hold ) && $hold > 0 ? $hold : $minutes;
			if ( $hold > $minutes && strtotime( $order->created_at . ' UTC' ) > $now->getTimestamp() - $hold * 60 ) {
				continue;
			}
			try {
				$this->transition( $order->id, S::CANCELLED, new TransitionContext( 'cron', 0, __( 'Unpaid order cancelled after the hold time.', 'flexacommerce' ) ) );
				++$cancelled;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $cancelled;
	}

	/**
	 * A paid order of downloads only has nothing left to do, so it moves on
	 * to completed (ADR-058). `flexacommerce.order.needs_processing` can keep
	 * it at processing.
	 */
	private function complete_if_nothing_to_ship( Order $order, TransitionContext $ctx ): void {
		if ( ! self::only_downloads( array_map( static fn ( OrderItem $i ): array => $i->meta, $order->items_of_type( OrderItem::PRODUCT ) ) ) ) {
			return;
		}
		/**
		 * Whether an order of downloads only stays processing instead of
		 * completing when it is paid (ADR-058).
		 *
		 * @param bool      $needs false
		 * @param OrderView $view
		 */
		if ( (bool) apply_filters( 'flexacommerce.order.needs_processing', false, $order->view() ) ) {
			return;
		}
		$this->apply_transition( $order, S::COMPLETED, $ctx->with_message( __( 'Order completed: it only holds downloads.', 'flexacommerce' ) ) );
	}

	/**
	 * True when every product line is virtual and sells files (ADR-058).
	 *
	 * @param array<array-key,array<string,mixed>> $metas product line meta
	 */
	private static function only_downloads( array $metas ): bool {
		foreach ( $metas as $meta ) {
			if ( empty( $meta['virtual'] ) || empty( $meta['downloadable'] ) ) {
				return false;
			}
		}
		return $metas !== [];
	}

	private function lock( int $order_id ): Order {
		return $this->orders->find( $order_id, true ) ?? throw new OrderNotFound( __( 'Order not found.', 'flexacommerce' ) );
	}

	private function assert_allowed( Order $order, string $to ): void {
		if ( ! OrderStateMachine::allows( $order->status, $to ) ) {
			throw new InvalidTransition(
				$order->status,
				$to,
				sprintf(
					/* translators: 1: current status, 2: requested status. */
					__( 'An order that is %1$s cannot be set to %2$s.', 'flexacommerce' ),
					S::label( $order->status ),
					S::label( $to )
				)
			);
		}
		// The filter may only forbid (doc 04 §5.2).
		if ( apply_filters( 'flexacommerce.order.can_transition', true, $order->status, $to, $order->view() ) === false ) {
			throw new InvalidTransition( $order->status, $to, __( 'This status change is not allowed for this order.', 'flexacommerce' ) );
		}
	}

	/**
	 * Status write, stock effect, history row and deferred actions. `$check`
	 * false skips the table and `$stock` false skips the stock effect, both
	 * for internal recovery moves only.
	 */
	private function apply_transition( Order $order, string $to, TransitionContext $ctx, bool $check = true, bool $stock = true ): void {
		if ( $check && ! OrderStateMachine::allows( $order->status, $to ) ) {
			throw new InvalidTransition( $order->status, $to );
		}
		$from   = $order->status;
		$effect = $stock ? OrderStateMachine::stock_effect( $from, $to ) : 0;
		if ( $effect < 0 ) {
			$this->deduct( $order, $ctx );
		} elseif ( $effect > 0 ) {
			$this->release( $order, $ctx );
		}
		if ( $to === S::CANCELLED && $this->release_coupons !== null && $order->items_of_type( OrderItem::DISCOUNT ) !== [] ) {
			( $this->release_coupons )( $order->id );
		}

		$now        = $this->clock->db_now();
		$timestamps = match ( $to ) {
			S::COMPLETED => [ 'completed_at' => $now ],
			S::CANCELLED => [ 'cancelled_at' => $now ],
			default      => [],
		};
		$this->orders->update_status( $order->id, $to, $now, $timestamps );
		$this->history->add(
			$order->id,
			'status',
			$ctx->message !== '' ? $ctx->message : sprintf(
				/* translators: 1: previous status, 2: new status. */
				__( 'Status changed from %1$s to %2$s.', 'flexacommerce' ),
				S::label( $from ),
				S::label( $to )
			),
			$now,
			$ctx->actor_type,
			$ctx->actor_id,
			$ctx->customer_visible,
			$from,
			$to,
			$ctx->context
		);
		$actor   = $ctx->actor_type;
		$context = $ctx->context;
		$this->emit(
			$order->id,
			static function ( OrderView $view ) use ( $from, $to, $actor, $context ): void {
				do_action( 'flexacommerce.order.status_changed', $view, $from, $to, $actor, $context );
				do_action( "flexacommerce.order.status.{$to}", $view, $from );
			}
		);
	}

	/**
	 * Take stock for every product line that does not hold it yet. The
	 * option choices go first: if the variants are short, they go back.
	 */
	private function deduct( Order $order, TransitionContext $ctx ): void {
		$taken = $this->deduct_options( $order );
		try {
			$this->deduct_variants( $order, $ctx );
		} catch ( InsufficientStock $e ) {
			$this->option_stock?->put_back( $taken );
			foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
				if ( $item->option_stock() !== [] ) {
					$this->orders->update_item( $item->id, [ 'meta' => $item->meta ] );
				}
			}
			throw $e;
		}
	}

	/**
	 * Take the option choices of every product line that does not hold them
	 * yet (ADR-040); each line keeps how many it holds in `option_held`.
	 *
	 * @return array<string,int> what was taken, key => quantity
	 * @throws InsufficientStock
	 */
	private function deduct_options( Order $order ): array {
		if ( $this->option_stock === null ) {
			return [];
		}
		$wanted = [];
		$held   = [];
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			$qty = $item->quantity - $item->refunded_qty - $item->option_held();
			if ( $item->option_stock() === [] || $qty <= 0 ) {
				continue;
			}
			foreach ( $item->option_stock() as $key => $label ) {
				$wanted[ $key ] = [
					'quantity'   => ( $wanted[ $key ]['quantity'] ?? 0 ) + $qty,
					'label'      => $label,
					'variant_id' => (int) $item->variant_id,
				];
			}
			$held[] = [ $item, $item->option_held() + $qty ];
		}
		if ( $wanted === [] ) {
			return [];
		}
		$this->option_stock->take( $wanted );
		foreach ( $held as [ $item, $count ] ) {
			$this->orders->update_item( $item->id, [ 'meta' => [ 'option_held' => $count ] + $item->meta ] );
		}
		return array_map( static fn ( array $want ): int => $want['quantity'], $wanted );
	}

	private function deduct_variants( Order $order, TransitionContext $ctx ): void {
		$units = [];
		$items = [];
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			$qty = $item->quantity - $item->refunded_qty - $item->stock_reduced_qty;
			if ( $item->stock_id() > 0 && $qty > 0 ) {
				$units[] = new StockUnit( $item->stock_id(), (int) $item->product_id, (int) $item->variant_id, $qty );
				$items[] = [ $item, $qty ];
			}
		}
		if ( $units === [] ) {
			return;
		}
		$changes = $this->stock->deduct_units( $units, 'order', $order->id );
		$managed = [];
		foreach ( $changes as $change ) {
			$managed[ $change->variant_id ] = true;
		}
		$lines = [];
		$held  = [];
		foreach ( $items as [ $item, $qty ] ) {
			if ( isset( $managed[ $item->stock_id() ] ) ) {
				$held[ $item->id ] = $item->stock_reduced_qty + $qty;
				$lines[]           = [
					'item_id' => $item->id,
					'qty'     => $qty,
				];
			}
		}
		$this->orders->set_stock_reduced( $held );
		if ( $lines !== [] ) {
			$this->history->add( $order->id, 'stock', __( 'Stock reduced.', 'flexacommerce' ), $this->clock->db_now(), $ctx->actor_type, $ctx->actor_id, false, null, null, [ 'lines' => $lines ] );
		}
	}

	/** Put back exactly what each line holds and zero it, so a replay restores nothing. */
	private function release( Order $order, TransitionContext $ctx ): void {
		if ( $this->option_stock !== null ) {
			$back = [];
			foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
				$held = $item->option_held();
				if ( $held <= 0 ) {
					continue;
				}
				foreach ( array_keys( $item->option_stock() ) as $key ) {
					$back[ $key ] = ( $back[ $key ] ?? 0 ) + $held;
				}
				$this->orders->update_item( $item->id, [ 'meta' => [ 'option_held' => 0 ] + $item->meta ] );
			}
			$this->option_stock->put_back( $back );
		}
		$units = [];
		$lines = [];
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			if ( $item->stock_reduced_qty > 0 && $item->stock_id() > 0 ) {
				$units[] = new StockUnit( $item->stock_id(), (int) $item->product_id, (int) $item->variant_id, $item->stock_reduced_qty );
				$lines[] = [
					'item_id' => $item->id,
					'qty'     => $item->stock_reduced_qty,
				];
			}
		}
		if ( $units === [] ) {
			return;
		}
		$this->orders->set_stock_reduced( array_fill_keys( array_column( $lines, 'item_id' ), 0 ) );
		$this->stock->release_units( $units, 'order_released', $order->id );
		$this->history->add( $order->id, 'stock', __( 'Stock restored.', 'flexacommerce' ), $this->clock->db_now(), $ctx->actor_type, $ctx->actor_id, false, null, null, [ 'lines' => $lines ] );
	}

	private function flag( Order $order, string $reason, TransitionContext $ctx ): void {
		$now = $this->clock->db_now();
		$this->orders->set_needs_attention( $order->id, true, $now );
		$this->history->add( $order->id, 'system', $reason, $now, $ctx->actor_type, $ctx->actor_id );
		$this->emit(
			$order->id,
			static function ( OrderView $view ) use ( $reason ): void {
				do_action( 'flexacommerce.order.needs_attention', $view, $reason );
			}
		);
	}

	/** The filter may pick pending, on-hold or processing (doc 04 §5.2). */
	private function initial_status( Order $order ): string {
		$status = apply_filters( 'flexacommerce.order.initial_status', $order->status, $order->view(), $order->payment_method );
		return is_string( $status ) && in_array( $status, S::INITIAL, true ) ? $status : $order->status;
	}

	/**
	 * Fire an order action once the transaction commits, with a view read
	 * after the commit (create() leaves its last read). Views are shared by
	 * the actions of the same write.
	 *
	 * @param callable(OrderView):void $action
	 */
	private function emit( int $order_id, callable $action ): void {
		$this->after_commit->defer(
			function () use ( $order_id, $action ): void {
				if ( ! isset( $this->views[ $order_id ] ) ) {
					$order = $this->orders->find( $order_id );
					if ( $order === null ) {
						return;
					}
					$this->views[ $order_id ] = $order->view();
				}
				$action( $this->views[ $order_id ] );
			}
		);
	}
}
