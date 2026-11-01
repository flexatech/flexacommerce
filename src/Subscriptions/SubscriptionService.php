<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Database\Transaction as DbTransaction;
use Flexa\Commerce\Notifications\EmailTemplates;
use Flexa\Commerce\Notifications\Mailer;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\PaymentService;
use Flexa\Commerce\Payments\RecurringGateway;
use Flexa\Commerce\Payments\SavedMethod;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriptions (ADR-073). Checkout makes one subscription per plan in
 * the cart, `pending` until its order is paid. The hourly cron makes a
 * renewal order from the stored snapshot when a payment day comes. A
 * method saved by a recurring gateway pays it by itself, with retries
 * after a failure (ADR-074); otherwise the shopper pays it from the email
 * or the account page, and the subscription waits `on-hold` until then.
 * A plan with a set number of payments expires after the last paid
 * period; a shopper who cancels keeps what they paid for until its end.
 */
final class SubscriptionService {
	/** Rows handled per cron run, renewals and endings each. */
	public const BATCH = 25;

	/** User meta set on a customer's first subscription, so other accounts read no table. */
	public const USER_META = '_fxc_has_subscriptions';

	/** Order meta naming the subscription a renewal order bills. */
	public const ORDER_META = '_fxc_subscription';

	public const CREATED_VIA = 'subscription';

	/** Days to the next automatic try after each failed one; after the last, the customer pays from the email (ADR-074). */
	public const RETRY_DAYS = [ 1, 2, 4 ];

	/**
	 * Fired when a renewal order waits for the customer: no saved method,
	 * or the automatic charge failed. Args: OrderView, and a context with
	 * `failed` (the reason, '' for none), `retry_at` (UTC, or null),
	 * `final`, `attempt` and `subscription_url`. The renewal invoice email
	 * listens to it.
	 */
	public const RENEWAL_DUE = 'flexacommerce.subscription.renewal_due';

	/** Paid linked orders capture() looks through, newest first. */
	private const CAPTURE_ORDERS = 3;

	/** True while our own automatic charge runs, so the paid renewal keeps the method it was charged with. */
	private bool $charging = false;

	/**
	 * @param (\Closure(): PaymentService)|null $payments    lazy, so the cron builds payments only when it charges
	 * @param (\Closure(int): string)|null      $account_url the subscription's page in the account
	 */
	public function __construct(
		private readonly SubscriptionRepository $rows,
		private readonly OrderService $orders,
		private readonly DbTransaction $db,
		private readonly Mailer $mailer,
		private readonly EmailTemplates $templates,
		private readonly MoneyFactory $money,
		private readonly Clock $clock,
		private readonly Logger $log,
		private readonly ?\Closure $payments = null,
		private readonly ?\Closure $account_url = null,
	) {}

	public function repository(): SubscriptionRepository {
		return $this->rows;
	}

	public function find( int $id ): ?Subscription {
		return $this->rows->find( $id );
	}

	/** Whether the account ever had a subscription, read from user meta. */
	public static function has_any( int $user_id ): bool {
		return $user_id > 0 && get_user_meta( $user_id, self::USER_META, true ) !== '';
	}

	/**
	 * One subscription per snapshot, for an order just placed. Runs inside
	 * the checkout transaction.
	 *
	 * @param list<Snapshot> $snapshots
	 * @return list<int> the new ids
	 */
	public function create_for_order( Order $order, array $snapshots ): array {
		$now = $this->clock->db_now();
		$ids = [];
		foreach ( $snapshots as $snapshot ) {
			$id = $this->rows->insert(
				[
					'status'               => SubscriptionStatus::PENDING,
					'customer_id'          => (int) $order->customer_id,
					'customer_email'       => $order->customer_email,
					'currency'             => $order->currency,
					'period'               => $snapshot->plan->period,
					'interval_count'       => $snapshot->plan->interval,
					'length'               => $snapshot->plan->length,
					'trial_days'           => $snapshot->plan->trial_days,
					'total'                => $snapshot->total,
					'parent_order_id'      => $order->id,
					'payment_method'       => $order->payment_method,
					'payment_method_title' => $order->payment_method_title,
					'snapshot'             => $snapshot->to_json(),
					'billing'              => Subscription::address_json( $order->billing ),
					'shipping'             => Subscription::address_json( $order->shipping ),
				],
				$now
			);
			$this->rows->link( $id, $order->id, SubscriptionRepository::PARENT, $now );
			$this->rows->add_event( $id, 'created', __( 'Subscription created at checkout.', 'flexacommerce' ), $order->id, 'customer', $order->customer_id, $now );
			$ids[] = $id;
		}
		if ( $ids !== [] && (int) $order->customer_id > 0 ) {
			update_user_meta( (int) $order->customer_id, self::USER_META, '1' );
		}
		return $ids;
	}

	/**
	 * Order events (priority 5): a paid first order starts its
	 * subscriptions and a cancelled or failed one ends them; a paid renewal
	 * takes its subscription off hold.
	 */
	public function on_order( OrderView $view ): void {
		$paid = in_array( $view->status, [ OrderStatus::PROCESSING, OrderStatus::COMPLETED ], true );
		$dead = in_array( $view->status, [ OrderStatus::CANCELLED, OrderStatus::FAILED ], true );
		if ( ! $paid && ! $dead ) {
			return;
		}
		if ( $view->created_via === self::CREATED_VIA ) {
			if ( $paid ) {
				foreach ( $this->rows->for_renewal( $view->id ) as $row ) {
					$this->renewal_paid( $row->id, $view->id );
				}
			}
			return;
		}
		foreach ( $this->rows->for_parent( $view->id ) as $row ) {
			if ( $paid && $row->status === SubscriptionStatus::PENDING ) {
				$this->activate( $row->id, $view->id );
			} elseif ( $dead && ! $row->is_ended() && ! $this->renewed( $row ) ) {
				// A first order cancelled before any renewal (cash on delivery refused, say) takes its subscription with it.
				$this->end( $row->id, SubscriptionStatus::CANCELLED, __( 'The first order was cancelled.', 'flexacommerce' ), TransitionContext::system() );
			}
		}
	}

	/**
	 * Saved methods to look up, renewals for the rows whose payment day has
	 * come, failed charges to try again, then the rows whose last period is
	 * over. Returns the number of rows handled.
	 */
	public function run(): int {
		$now  = $this->clock->db_now();
		$done = 0;
		// Bookkeeping, not counted: read the method each new row's first payment saved.
		foreach ( $this->rows->uncaptured( self::BATCH ) as $row ) {
			$this->capture( $row );
		}
		foreach ( $this->rows->due( $now, self::BATCH ) as $row ) {
			try {
				$this->renew( $row->id, new TransitionContext( 'cron' ) );
			} catch ( \Throwable $e ) {
				$this->log->error(
					'Renewal of subscription {id} failed: {message}',
					[
						'id'      => $row->id,
						'message' => $e->getMessage(),
					]
				);
				$this->hold( $row->id, __( 'The renewal order could not be created.', 'flexacommerce' ) . ' ' . $e->getMessage(), new TransitionContext( 'cron' ) );
			}
			++$done;
		}
		foreach ( $this->rows->retry_due( $now, self::BATCH ) as $row ) {
			$this->retry( $row->id, new TransitionContext( 'cron' ) );
			++$done;
		}
		foreach ( $this->rows->ending( $now, self::BATCH ) as $row ) {
			$this->end(
				$row->id,
				$row->status === SubscriptionStatus::PENDING_CANCEL ? SubscriptionStatus::CANCELLED : SubscriptionStatus::EXPIRED,
				$row->status === SubscriptionStatus::PENDING_CANCEL ? __( 'The paid period ended.', 'flexacommerce' ) : __( 'All payments were made.', 'flexacommerce' ),
				new TransitionContext( 'cron' )
			);
			++$done;
		}
		return $done;
	}

	/**
	 * A renewal order for the next period, now, charged to the saved method
	 * when there is one. The subscription waits on hold until the order is
	 * paid; a free renewal keeps it active. Out of stock puts it on hold
	 * with no order.
	 *
	 * @throws \InvalidArgumentException the subscription cannot renew
	 */
	public function renew( int $id, TransitionContext $ctx ): ?Order {
		$row = $this->rows->find( $id );
		if ( $row === null || ! in_array( $row->status, [ SubscriptionStatus::ACTIVE, SubscriptionStatus::ON_HOLD ], true ) || $row->snapshot === null ) {
			throw new \InvalidArgumentException( __( 'This subscription cannot renew.', 'flexacommerce' ) );
		}
		if ( $row->next_payment_at === null ) {
			throw new \InvalidArgumentException( __( 'This subscription has no payment left.', 'flexacommerce' ) );
		}
		$number = $this->orders->next_number();
		try {
			$order = $this->db->run(
				function () use ( $id, $number, $ctx ): ?Order {
					$row = $this->rows->find( $id, true );
					if ( $row === null || $row->snapshot === null || $row->next_payment_at === null || ! in_array( $row->status, [ SubscriptionStatus::ACTIVE, SubscriptionStatus::ON_HOLD ], true ) ) {
						return null;
					}
					$now   = $this->clock->db_now();
					$order = $this->orders->create( $this->renewal_order( $row ), $number, $ctx->with_message( __( 'Renewal order created.', 'flexacommerce' ) ) );
					$this->rows->link( $row->id, $order->id, SubscriptionRepository::RENEWAL, $now );
					$billed = $row->billed + 1;
					$this->rows->update(
						$row->id,
						[
							'status'      => $order->total > 0 && $order->status === OrderStatus::PENDING ? SubscriptionStatus::ON_HOLD : SubscriptionStatus::ACTIVE,
							'billed'      => $billed,
							'retry_count' => 0,
							'retry_at'    => null,
						] + $this->schedule( $row, $billed, $row->plan->after( $row->next_payment_at, 1, $row->anchor_day ) ),
						$now
					);
					/* translators: %s: order number. */
					$this->rows->add_event( $row->id, 'renewal', sprintf( __( 'Renewal order #%s created.', 'flexacommerce' ), $order->order_number ), $order->id, $ctx->actor_type, $ctx->actor_id, $now );
					return $order;
				}
			);
		} catch ( InsufficientStock $e ) {
			$this->hold( $id, $e->getMessage(), $ctx );
			return null;
		}
		if ( $order === null || $order->status !== OrderStatus::PENDING || $order->balance_due() <= 0 ) {
			return $order;
		}
		$this->collect( $id, $order, $ctx, 'renewal' );
		return $this->orders->get( $order->id );
	}

	/**
	 * The saved method the row's paid orders left with a recurring gateway,
	 * newest order first; '' when they saved none (ADR-074). Asking the
	 * gateway can fail: the row then stays unknown and is asked again on a
	 * later run.
	 */
	public function capture( Subscription $row ): Subscription {
		$payments = $this->payments();
		if ( $payments === null ) {
			return $row;
		}
		$found   = null;
		$gateway = null;
		$checked = 0;
		foreach ( array_reverse( $this->rows->orders( $row->id ) ) as $link ) {
			$order = $this->orders->find( $link['order_id'] );
			if ( $order === null || $order->paid_at === null ) {
				continue;
			}
			if ( ++$checked > self::CAPTURE_ORDERS ) {
				break;
			}
			foreach ( $payments->for_order( $order->id ) as $txn ) {
				$gateway = $txn->type === Transaction::PAYMENT && $txn->method === Transaction::GATEWAY && in_array( $txn->status, [ Transaction::SUCCEEDED, Transaction::AUTHORIZED ], true ) && (string) $txn->gateway_reference !== ''
					? $this->recurring( $txn->gateway )
					: null;
				if ( $gateway === null ) {
					continue;
				}
				try {
					$found = $gateway->saved_method( (string) $txn->gateway_reference );
				} catch ( \Throwable $e ) {
					$this->log->warning(
						'Could not read the saved payment method of subscription {id}: {message}',
						[
							'id'      => $row->id,
							'message' => $e->getMessage(),
						]
					);
					// Asked again after the other rows.
					$this->rows->update( $row->id, [], $this->clock->db_now() );
					return $row;
				}
				if ( $found !== null ) {
					break 2;
				}
			}
		}
		$now = $this->clock->db_now();
		if ( $found === null || $gateway === null ) {
			$this->rows->update( $row->id, [ 'payment_token' => '' ], $now );
			return $this->rows->find( $row->id ) ?? $row;
		}
		$this->save_method( $row, $gateway, $found, $now );
		/* translators: %s: a payment method, like "Visa ending in 4242". */
		$this->rows->add_event( $row->id, 'payment-method', sprintf( __( 'Renewals are charged to %s.', 'flexacommerce' ), $found->label ), null, 'system', null, $now );
		return $this->rows->find( $row->id ) ?? $row;
	}

	/** Whether the customer can save a new payment method for the renewals. */
	public function can_update_method( Subscription $row ): bool {
		return in_array( $row->status, [ SubscriptionStatus::ACTIVE, SubscriptionStatus::ON_HOLD, SubscriptionStatus::PENDING_CANCEL ], true ) && $this->setup_gateway( $row ) !== null;
	}

	/**
	 * The payment provider's page where the customer saves a new method;
	 * it comes back to `$return_url` with the reference finish_update()
	 * takes.
	 *
	 * @throws \InvalidArgumentException no gateway can, or the provider failed
	 */
	public function update_url( Subscription $row, string $return_url ): string {
		$gateway = $this->can_update_method( $row ) ? $this->setup_gateway( $row ) : null;
		if ( $gateway === null ) {
			throw new \InvalidArgumentException( __( 'The payment method of this subscription cannot be changed here.', 'flexacommerce' ) );
		}
		$name = $row->billing !== null ? trim( $row->billing->first_name . ' ' . $row->billing->last_name ) : '';
		try {
			$url = $gateway->setup_url( $row->payment_method === $gateway->id() ? $row->payment_customer : '', $row->customer_email, $name, $row->customer_id, self::tag( $row ), $return_url, $return_url );
		} catch ( \Throwable $e ) {
			$this->log->error(
				'Could not start saving a payment method for subscription {id}: {message}',
				[
					'id'      => $row->id,
					'message' => $e->getMessage(),
				]
			);
			$url = '';
		}
		if ( $url === '' ) {
			throw new \InvalidArgumentException( __( 'The payment provider could not be reached. Please try again.', 'flexacommerce' ) );
		}
		return $url;
	}

	/**
	 * Back from the provider's page: the new method pays this row and the
	 * customer's other rows that used the old one, then the unpaid renewal
	 * is charged to it right away. Returns that charge, or null when
	 * nothing was owed.
	 *
	 * @throws \InvalidArgumentException the provider did not confirm a method for this row
	 */
	public function finish_update( Subscription $row, string $reference, TransitionContext $ctx ): ?PaymentResult {
		$gateway = $this->setup_gateway( $row );
		try {
			$method = $gateway?->finish_setup( $reference, self::tag( $row ) );
		} catch ( \Throwable $e ) {
			$this->log->error(
				'Could not read the new payment method of subscription {id}: {message}',
				[
					'id'      => $row->id,
					'message' => $e->getMessage(),
				]
			);
			$method = null;
		}
		if ( $gateway === null || $method === null ) {
			throw new \InvalidArgumentException( __( 'The new payment method could not be confirmed. Please try again.', 'flexacommerce' ) );
		}
		$now  = $this->clock->db_now();
		$rows = [ $row ];
		if ( $row->customer_id > 0 && $row->is_automatic() ) {
			foreach ( $this->rows->for_customer( $row->customer_id ) as $other ) {
				if ( $other->id !== $row->id && ! $other->is_ended() && $other->payment_token === $row->payment_token ) {
					$rows[] = $other;
				}
			}
		}
		foreach ( $rows as $each ) {
			$this->save_method( $each, $gateway, $method, $now );
			/* translators: %s: a payment method, like "Visa ending in 4242". */
			$this->rows->add_event( $each->id, 'payment-method', sprintf( __( 'Payment method changed to %s.', 'flexacommerce' ), $method->label ), null, $ctx->actor_type, $ctx->actor_id, $now );
		}
		$order = $this->unpaid_renewal( $this->rows->find( $row->id ) ?? $row );
		return $order !== null ? $this->collect( $row->id, $order, $ctx, 'customer' ) : null;
	}

	/**
	 * The merchant charges the saved method again for the unpaid renewal,
	 * now. The failure counts as one of the tries.
	 *
	 * @throws \InvalidArgumentException nothing to charge
	 */
	public function retry_now( int $id, TransitionContext $ctx ): PaymentResult {
		$row   = $this->rows->find( $id ) ?? throw new \InvalidArgumentException( __( 'Subscription not found.', 'flexacommerce' ) );
		$order = $this->unpaid_renewal( $row ) ?? throw new \InvalidArgumentException( __( 'This subscription has no unpaid renewal.', 'flexacommerce' ) );
		return $this->collect( $id, $order, $ctx, 'retry' ) ?? throw new \InvalidArgumentException( __( 'This subscription has no saved payment method to charge.', 'flexacommerce' ) );
	}

	/** A failed charge whose next try has come: charge the unpaid renewal again. */
	private function retry( int $id, TransitionContext $ctx ): void {
		$row   = $this->rows->find( $id );
		$order = $row !== null ? $this->unpaid_renewal( $row ) : null;
		if ( $row === null || $order === null ) {
			$this->rows->update(
				$id,
				[
					'retry_count' => 0,
					'retry_at'    => null,
				],
				$this->clock->db_now()
			);
			return;
		}
		$this->collect( $id, $order, $ctx, 'retry' );
	}

	/**
	 * Charge a pending renewal order to the row's saved method. `$why` is
	 * `renewal` (a new order), `retry` (a later try) or `customer` (the
	 * customer is on the page: a failure is shown there, not counted).
	 * Returns null when the row has no method to charge; a new renewal then
	 * goes to the customer as an invoice.
	 */
	private function collect( int $id, Order $order, TransitionContext $ctx, string $why ): ?PaymentResult {
		$row = $this->rows->find( $id );
		if ( $row === null ) {
			return null;
		}
		if ( $row->payment_token === null ) {
			$row = $this->capture( $row );
		}
		$gateway  = $row->is_automatic() ? $this->recurring( $row->payment_method ) : null;
		$payments = $this->payments();
		if ( $gateway === null || $payments === null ) {
			if ( $why === 'renewal' ) {
				$this->renewal_due( $order, $row, '', null, false );
			} elseif ( $why === 'retry' ) {
				$this->rows->update(
					$id,
					[
						'retry_count' => 0,
						'retry_at'    => null,
					],
					$this->clock->db_now()
				);
			}
			return null;
		}
		if ( $order->payment_method !== $gateway->id() ) {
			$order = $this->orders->change_payment_method( $order->id, $gateway->id(), $payments->gateways()->title( $gateway ), $ctx );
		}
		$this->charging = true;
		try {
			$result = $payments->charge_saved( $order, $gateway, new SavedMethod( $row->payment_customer, (string) $row->payment_token, $row->payment_label ) );
		} finally {
			$this->charging = false;
		}
		$now = $this->clock->db_now();
		if ( $result->type === PaymentResult::PENDING ) {
			$this->rows->add_event( $id, 'renewal-processing', __( 'The automatic payment is processing.', 'flexacommerce' ), $order->id, 'system', null, $now );
		} elseif ( $result->type === PaymentResult::FAILED && $why !== 'customer' ) {
			$this->charge_failed( $row, $order, $result->failure_message, $now );
		}
		return $result;
	}

	/** Counts a failed try, plans the next one, and tells the customer after the first and the last. */
	private function charge_failed( Subscription $row, Order $order, string $message, string $now ): void {
		$attempt  = $row->retry_count + 1;
		$final    = $attempt > count( self::RETRY_DAYS );
		$retry_at = $final ? null : ( new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) ) )->modify( '+' . self::RETRY_DAYS[ $attempt - 1 ] . ' days' )->format( 'Y-m-d H:i:s' );
		$this->rows->update(
			$row->id,
			[
				'retry_count' => $attempt,
				'retry_at'    => $retry_at,
			],
			$now
		);
		$reason = rtrim( trim( $message ), '.' );
		$this->rows->add_event(
			$row->id,
			'payment-failed',
			$final
				/* translators: %s: why the payment failed. */
				? sprintf( __( 'The automatic payment failed (%s). No more tries; the customer pays from the email.', 'flexacommerce' ), $reason )
				/* translators: 1: why the payment failed; 2: date of the next try. */
				: sprintf( __( 'The automatic payment failed (%1$s). Next try on %2$s.', 'flexacommerce' ), $reason, wp_date( (string) get_option( 'date_format' ), (int) strtotime( (string) $retry_at . ' UTC' ) ) ),
			$order->id,
			'system',
			null,
			$now
		);
		if ( $attempt === 1 || $final ) {
			$this->renewal_due( $order, $row, $message !== '' ? $message : __( 'The payment did not go through.', 'flexacommerce' ), $retry_at, $final, $attempt );
		}
	}

	private function renewal_due( Order $order, Subscription $row, string $failed, ?string $retry_at, bool $final, int $attempt = 0 ): void {
		/**
		 * A renewal order waits for the customer (ADR-074).
		 *
		 * @param OrderView            $order
		 * @param array<string,mixed>  $context failed, retry_at, final, attempt, subscription_url
		 */
		do_action(
			self::RENEWAL_DUE,
			$this->orders->get( $order->id )->view(),
			[
				'failed'           => $failed,
				'retry_at'         => $retry_at,
				'final'            => $final,
				'attempt'          => $attempt,
				'subscription_url' => $row->customer_id > 0 && $this->account_url !== null ? (string) ( $this->account_url )( $row->id ) : '',
			]
		);
	}

	private function save_method( Subscription $row, RecurringGateway $gateway, SavedMethod $method, string $now ): void {
		$payments = $this->payments();
		$this->rows->update(
			$row->id,
			[
				'payment_method'       => $gateway->id(),
				'payment_method_title' => $payments !== null ? $payments->gateways()->title( $gateway ) : $gateway->title(),
				'payment_customer'     => $method->customer,
				'payment_token'        => $method->token,
				'payment_label'        => $method->label,
			],
			$now
		);
	}

	/** An enabled gateway that charges saved methods, by id. */
	private function recurring( string $id ): ?RecurringGateway {
		$payments = $id !== '' ? $this->payments() : null;
		try {
			$gateway = $payments?->gateways()->get( $id );
		} catch ( \Throwable ) {
			return null;
		}
		return $gateway instanceof RecurringGateway && $payments->gateways()->settings()->enabled( $gateway ) ? $gateway : null;
	}

	/** Where a new method is saved: the row's own gateway, else the first enabled one that can. */
	private function setup_gateway( Subscription $row ): ?RecurringGateway {
		$own = $this->recurring( $row->payment_method );
		if ( $own !== null ) {
			return $own;
		}
		foreach ( $this->payments()?->gateways()->ids() ?? [] as $id ) {
			$gateway = $this->recurring( $id );
			if ( $gateway !== null ) {
				return $gateway;
			}
		}
		return null;
	}

	private function payments(): ?PaymentService {
		return $this->payments !== null ? ( $this->payments )() : null;
	}

	/** What a saved method is for, checked when the customer comes back. */
	private static function tag( Subscription $row ): string {
		return 'subscription-' . $row->id;
	}

	/**
	 * The shopper or the merchant cancels. `$at_end` lets an active row run
	 * to the end of the period it paid for (`pending-cancel`); otherwise it
	 * ends now and its unpaid renewal orders are cancelled.
	 *
	 * @throws \InvalidArgumentException it is already over
	 */
	public function cancel( int $id, bool $at_end, TransitionContext $ctx ): Subscription {
		$row = $this->rows->find( $id ) ?? throw new \InvalidArgumentException( __( 'Subscription not found.', 'flexacommerce' ) );
		if ( $row->is_ended() ) {
			throw new \InvalidArgumentException( __( 'This subscription has already ended.', 'flexacommerce' ) );
		}
		$until = $row->next_payment_at ?? $row->end_at;
		if ( $at_end && $row->status === SubscriptionStatus::ACTIVE && $until !== null && $until > $this->clock->db_now() ) {
			$now = $this->clock->db_now();
			$this->rows->update(
				$id,
				[
					'status'          => SubscriptionStatus::PENDING_CANCEL,
					'next_payment_at' => null,
					'end_at'          => $until,
				],
				$now
			);
			$this->rows->add_event( $id, 'pending-cancel', __( 'Cancelled; it ends when the paid period does.', 'flexacommerce' ), null, $ctx->actor_type, $ctx->actor_id, $now );
			$this->notify_cancelled( $id );
			return $this->rows->find( $id ) ?? $row;
		}
		$this->end( $id, SubscriptionStatus::CANCELLED, __( 'Cancelled.', 'flexacommerce' ), $ctx );
		$this->notify_cancelled( $id );
		return $this->rows->find( $id ) ?? $row;
	}

	/**
	 * Undo a cancellation that has not taken effect yet.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function reactivate( int $id, TransitionContext $ctx ): Subscription {
		$row = $this->rows->find( $id ) ?? throw new \InvalidArgumentException( __( 'Subscription not found.', 'flexacommerce' ) );
		if ( $row->status !== SubscriptionStatus::PENDING_CANCEL || $row->end_at === null ) {
			throw new \InvalidArgumentException( __( 'Only a subscription waiting to end can be reactivated.', 'flexacommerce' ) );
		}
		$now = $this->clock->db_now();
		$this->rows->update( $id, [ 'status' => SubscriptionStatus::ACTIVE ] + $this->schedule( $row, $row->billed, $row->end_at ), $now );
		$this->rows->add_event( $id, 'reactivated', __( 'Reactivated.', 'flexacommerce' ), null, $ctx->actor_type, $ctx->actor_id, $now );
		return $this->rows->find( $id ) ?? $row;
	}

	/**
	 * The merchant pauses renewals, or lets them run again. A resumed row
	 * whose payment day passed renews on the next cron run.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function set_hold( int $id, bool $hold, TransitionContext $ctx ): Subscription {
		$row = $this->rows->find( $id ) ?? throw new \InvalidArgumentException( __( 'Subscription not found.', 'flexacommerce' ) );
		if ( $hold ) {
			if ( $row->status !== SubscriptionStatus::ACTIVE ) {
				throw new \InvalidArgumentException( __( 'Only an active subscription can be put on hold.', 'flexacommerce' ) );
			}
			$this->hold( $id, __( 'Put on hold.', 'flexacommerce' ), $ctx );
		} else {
			if ( $row->status !== SubscriptionStatus::ON_HOLD ) {
				throw new \InvalidArgumentException( __( 'Only a subscription on hold can be resumed.', 'flexacommerce' ) );
			}
			$now = $this->clock->db_now();
			$this->rows->update(
				$id,
				[
					'status'      => SubscriptionStatus::ACTIVE,
					'retry_count' => 0,
					'retry_at'    => null,
				],
				$now
			);
			$this->rows->add_event( $id, 'resumed', __( 'Resumed.', 'flexacommerce' ), null, $ctx->actor_type, $ctx->actor_id, $now );
		}
		return $this->rows->find( $id ) ?? $row;
	}

	/**
	 * The merchant moves the next payment day.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function reschedule( int $id, string $next_payment_at, TransitionContext $ctx ): Subscription {
		$row = $this->rows->find( $id ) ?? throw new \InvalidArgumentException( __( 'Subscription not found.', 'flexacommerce' ) );
		if ( ! in_array( $row->status, [ SubscriptionStatus::ACTIVE, SubscriptionStatus::ON_HOLD ], true ) || $row->next_payment_at === null ) {
			throw new \InvalidArgumentException( __( 'This subscription has no payment to move.', 'flexacommerce' ) );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $next_payment_at, new \DateTimeZone( 'UTC' ) );
		$now  = $this->clock->db_now();
		if ( $date === false || $date->format( 'Y-m-d H:i:s' ) !== $next_payment_at || $next_payment_at <= $now ) {
			throw new \InvalidArgumentException( __( 'Choose a date in the future.', 'flexacommerce' ) );
		}
		$this->rows->update(
			$id,
			[
				'next_payment_at' => $next_payment_at,
				'anchor_day'      => (int) $date->format( 'j' ),
			],
			$now
		);
		$this->rows->add_event( $id, 'rescheduled', __( 'Next payment date changed.', 'flexacommerce' ), null, $ctx->actor_type, $ctx->actor_id, $now );
		return $this->rows->find( $id ) ?? $row;
	}

	/** The unpaid renewal order of a row on hold, which the account page links to. */
	public function unpaid_renewal( Subscription $row ): ?Order {
		$links = array_reverse( $this->rows->orders( $row->id ) );
		foreach ( $links as $link ) {
			if ( $link['relation'] !== SubscriptionRepository::RENEWAL ) {
				continue;
			}
			$order = $this->orders->find( $link['order_id'] );
			if ( $order !== null && $order->status === OrderStatus::PENDING && $order->balance_due() > 0 ) {
				return $order;
			}
		}
		return null;
	}

	private function renewed( Subscription $row ): bool {
		return in_array( SubscriptionRepository::RENEWAL, array_column( $this->rows->orders( $row->id ), 'relation' ), true );
	}

	/** A subscription the account may see and act on. */
	public function owned( int $id, int $user_id ): ?Subscription {
		$row = $user_id > 0 ? $this->rows->find( $id ) : null;
		return $row !== null && $row->customer_id === $user_id ? $row : null;
	}

	/** "$10.00 / month": the renewal price in words. */
	public function price_text( Subscription $row ): string {
		return $row->plan->per( $this->money->format( $this->money->from_minor( $row->total, $row->currency ) ) );
	}

	private function activate( int $id, int $order_id ): void {
		$this->db->run(
			function () use ( $id, $order_id ): void {
				$row = $this->rows->find( $id, true );
				if ( $row === null || $row->status !== SubscriptionStatus::PENDING ) {
					return;
				}
				$now   = $this->clock->db_now();
				$plan  = $row->plan;
				$trial = $plan->trial_days > 0 ? ( new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $plan->trial_days . ' days' )->format( 'Y-m-d H:i:s' ) : null;
				// The first order pays the first period, unless it only starts a free trial.
				$billed = $trial !== null ? 0 : 1;
				$anchor = (int) substr( $trial ?? $now, 8, 2 );
				$next   = $trial ?? $plan->after( $now, 1, $anchor );
				$this->rows->update(
					$id,
					[
						'status'       => SubscriptionStatus::ACTIVE,
						'start_at'     => $now,
						'trial_end_at' => $trial,
						'billed'       => $billed,
						'anchor_day'   => $anchor,
					] + $this->schedule( $row, $billed, $next ),
					$now
				);
				$this->rows->add_event( $id, 'active', __( 'First order paid; the subscription started.', 'flexacommerce' ), $order_id, 'system', null, $now );
			}
		);
	}

	private function renewal_paid( int $id, int $order_id ): void {
		$row = $this->rows->find( $id );
		if ( $row === null ) {
			return;
		}
		$fields = [];
		if ( $row->retry_count > 0 || $row->retry_at !== null ) {
			$fields += [
				'retry_count' => 0,
				'retry_at'    => null,
			];
		}
		// Paid by hand, maybe with a new card: the next run looks up what it saved (ADR-074).
		if ( ! $this->charging && $row->payment_token !== null ) {
			$fields['payment_token'] = null;
		}
		$active = $row->status === SubscriptionStatus::ON_HOLD && $this->unpaid_renewal( $row ) === null;
		if ( $active ) {
			$fields['status'] = SubscriptionStatus::ACTIVE;
		}
		if ( $fields === [] ) {
			return;
		}
		$now = $this->clock->db_now();
		$this->rows->update( $id, $fields, $now );
		if ( $active ) {
			$this->rows->add_event( $id, 'renewal-paid', __( 'Renewal paid.', 'flexacommerce' ), $order_id, 'system', null, $now );
		}
	}

	private function hold( int $id, string $message, TransitionContext $ctx ): void {
		$now = $this->clock->db_now();
		$this->rows->update( $id, [ 'status' => SubscriptionStatus::ON_HOLD ], $now );
		$this->rows->add_event( $id, 'on-hold', $message, null, $ctx->actor_type, $ctx->actor_id, $now );
	}

	/** Cancelled or expired: no more payments, and no unpaid renewal left open. */
	private function end( int $id, string $status, string $message, TransitionContext $ctx ): void {
		$now = $this->clock->db_now();
		$row = $this->rows->find( $id );
		if ( $row === null || $row->is_ended() ) {
			return;
		}
		$this->rows->update(
			$id,
			[
				'status'          => $status,
				'next_payment_at' => null,
				'end_at'          => $row->end_at !== null && $row->end_at <= $now ? $row->end_at : $now,
				'cancelled_at'    => $status === SubscriptionStatus::CANCELLED ? $now : null,
			],
			$now
		);
		$this->rows->add_event( $id, $status, $message, null, $ctx->actor_type, $ctx->actor_id, $now );
		foreach ( $this->rows->orders( $id ) as $link ) {
			if ( $link['relation'] !== SubscriptionRepository::RENEWAL ) {
				continue;
			}
			$order = $this->orders->find( $link['order_id'] );
			if ( $order !== null && $order->status === OrderStatus::PENDING ) {
				$this->orders->cancel( $order->id, $ctx->with_message( __( 'The subscription ended.', 'flexacommerce' ) ) );
			}
		}
	}

	/**
	 * When the next payment falls, or when the row ends instead because the
	 * plan's payments are all made.
	 *
	 * @return array{next_payment_at:?string,end_at:?string}
	 */
	private function schedule( Subscription $row, int $billed, string $next ): array {
		return $row->plan->length > 0 && $billed >= $row->plan->length
			? [
				'next_payment_at' => null,
				'end_at'          => $next,
			]
			: [
				'next_payment_at' => $next,
				'end_at'          => null,
			];
	}

	private function renewal_order( Subscription $row ): NewOrder {
		$snapshot = $row->snapshot ?? throw new \InvalidArgumentException( 'No snapshot.' );
		// An internal gateway paid the first order (a gift card); the shopper picks one on the pay page.
		$method = str_starts_with( $row->payment_method, 'fxc_' ) ? '' : $row->payment_method;
		return new NewOrder(
			status: $snapshot->total > 0 ? OrderStatus::PENDING : OrderStatus::PROCESSING,
			currency: $row->currency,
			currency_exponent: $this->money->exponent( $row->currency ),
			items: $snapshot->items,
			subtotal: $snapshot->subtotal,
			shipping_total: $snapshot->shipping_total,
			total: $snapshot->total,
			customer_email: $row->customer_email,
			customer_id: $row->customer_id > 0 ? $row->customer_id : null,
			billing: $row->billing,
			shipping: $row->shipping,
			payment_method: $method,
			payment_method_title: $method !== '' ? $row->payment_method_title : '',
			created_via: self::CREATED_VIA,
			discount_total: $snapshot->discount_total,
			fee_total: $snapshot->fee_total,
			tax_total: $snapshot->tax_total,
			meta: [ self::ORDER_META => (string) $row->id ],
			prices_include_tax: $snapshot->prices_include_tax,
		);
	}

	/** Tells the store a subscription was cancelled. */
	private function notify_cancelled( int $id ): void {
		$row = $this->rows->find( $id );
		$to  = (string) get_option( 'admin_email' );
		if ( $row === null || $to === '' ) {
			return;
		}
		$html = $this->templates->render(
			'cancelled-subscription',
			[
				'email_id'     => CancelledSubscriptionEmail::ID,
				'subscription' => $row,
				'title'        => $row->title(),
				'price'        => $this->price_text( $row ),
				'ends'         => $row->end_at !== null ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row->end_at . ' UTC' ) ) : '',
				'url'          => add_query_arg(
					[
						'page'         => 'flexacommerce-subscriptions',
						'subscription' => $row->id,
					],
					admin_url( 'admin.php' )
				),
			]
		);
		$this->mailer->send(
			new CancelledSubscriptionEmail(),
			new EmailMessage(
				/* translators: %d: subscription number. */
				sprintf( __( 'Subscription #%d was cancelled', 'flexacommerce' ), $row->id ),
				__( 'Subscription cancelled', 'flexacommerce' ),
				$html,
				null,
				[ $to ],
				[ 'subscription_id' => $row->id ]
			),
			[
				'enabled'    => true,
				'recipients' => '',
				'subject'    => '',
				'heading'    => '',
			]
		);
	}
}
