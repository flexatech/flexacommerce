<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\Orders\AddressView;
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

	/**
	 * Fired some days before an active row renews (ADR-075). Args: the
	 * first order's OrderView, and a context with `subscription_id`,
	 * `title`, `price`, `renews_at` (UTC), `trial`, `automatic`,
	 * `payment_label` and `subscription_url`. The renewal reminder email
	 * listens to it.
	 */
	public const RENEWAL_REMINDER = 'flexacommerce.subscription.renewal_reminder';

	/** Paid linked orders capture() looks through, newest first. */
	private const CAPTURE_ORDERS = 3;

	/** True while our own automatic charge runs, so the paid renewal keeps the method it was charged with. */
	private bool $charging = false;

	/**
	 * @param (\Closure(): PaymentService)|null $payments    lazy, so the cron builds payments only when it charges
	 * @param (\Closure(int): string)|null      $account_url the subscription's page in the account
	 * @param (\Closure(): int)|null            $reminder_days days before a renewal the reminder goes out, 0 when it is off
	 * @param (\Closure(Snapshot, array<int,int>, AddressView, ?AddressView, int): Snapshot)|null $reprice the snapshot priced again with new quantities and addresses (ADR-076)
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
		private readonly ?\Closure $reminder_days = null,
		private readonly ?\Closure $reprice = null,
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
		// Not counted either: a reminder changes nothing the customer has.
		$this->remind( $now );
		return $done;
	}

	/**
	 * Emails the customers of active rows that renew within the reminder
	 * days, once per renewal date (ADR-075). The date is claimed before the
	 * hook fires, so a request that dies sends nothing rather than twice.
	 * A period no longer than the notice gets no reminder: it would land
	 * before the payment it follows.
	 */
	private function remind( string $now ): void {
		$days = $this->reminder_days !== null ? (int) ( $this->reminder_days )() : 0;
		if ( $days < 1 ) {
			return;
		}
		$notice = $days * DAY_IN_SECONDS;
		$until  = gmdate( 'Y-m-d H:i:s', (int) strtotime( $now . ' UTC' ) + $notice );
		foreach ( $this->rows->to_remind( $now, $until, self::BATCH ) as $row ) {
			$next = (string) $row->next_payment_at;
			$this->rows->update( $row->id, [ 'reminded_for' => $next ], $now );
			$trial = $row->in_trial( $now );
			$since = $trial ? (string) $row->start_at : $next;
			$after = $trial ? $next : $row->plan->after( $next, 1, $row->anchor_day );
			if ( (int) strtotime( $after . ' UTC' ) - (int) strtotime( $since . ' UTC' ) <= $notice ) {
				continue;
			}
			$order = $row->parent_order_id !== null ? $this->orders->find( $row->parent_order_id ) : null;
			if ( $order === null ) {
				continue;
			}
			$date = (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $next . ' UTC' ) );
			/* translators: %s: renewal date. */
			$this->rows->add_event( $row->id, 'reminder', sprintf( __( 'Reminder of the renewal on %s emailed.', 'flexacommerce' ), $date ), null, 'system', null, $now );
			/**
			 * An active subscription renews soon (ADR-075).
			 *
			 * @param OrderView           $order   the subscription's first order
			 * @param array<string,mixed> $context subscription_id, title, price, renews_at, trial, automatic, payment_label, subscription_url
			 */
			do_action(
				self::RENEWAL_REMINDER,
				$order->view(),
				[
					'subscription_id'  => $row->id,
					'title'            => $row->title(),
					'price'            => $this->price_text( $row ),
					'renews_at'        => $next,
					'trial'            => $trial,
					'automatic'        => $row->is_automatic(),
					'payment_label'    => $row->payment_label !== '' ? $row->payment_label : $row->payment_method_title,
					'subscription_url' => $row->customer_id > 0 && $this->account_url !== null ? (string) ( $this->account_url )( $row->id ) : '',
				]
			);
		}
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
		// A paused row also keeps the period it paid for (ADR-076).
		if ( $at_end && ( $row->status === SubscriptionStatus::ACTIVE || $row->is_paused() ) && $until !== null && $until > $this->clock->db_now() ) {
			$now = $this->clock->db_now();
			$this->rows->update(
				$id,
				[
					'status'          => SubscriptionStatus::PENDING_CANCEL,
					'next_payment_at' => null,
					'end_at'          => $until,
					'paused_at'       => null,
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
			$this->unpause( $row, $ctx );
		}
		return $this->rows->find( $id ) ?? $row;
	}

	/** Whether the customer can pause renewals from the account page (ADR-076). */
	public function can_pause( Subscription $row ): bool {
		return $row->status === SubscriptionStatus::ACTIVE && $row->next_payment_at !== null;
	}

	/**
	 * The customer pauses renewals (ADR-076). Nothing is charged or
	 * shipped until they resume; the merchant sees the row on hold.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function pause( int $id, TransitionContext $ctx ): Subscription {
		$row = $this->rows->find( $id ) ?? throw new \InvalidArgumentException( __( 'Subscription not found.', 'flexacommerce' ) );
		if ( ! $this->can_pause( $row ) ) {
			throw new \InvalidArgumentException( __( 'Only an active subscription can be paused.', 'flexacommerce' ) );
		}
		$now = $this->clock->db_now();
		$this->rows->update(
			$id,
			[
				'status'    => SubscriptionStatus::ON_HOLD,
				'paused_at' => $now,
			],
			$now
		);
		$this->rows->add_event( $id, 'paused', __( 'Paused by the customer.', 'flexacommerce' ), null, $ctx->actor_type, $ctx->actor_id, $now );
		return $this->rows->find( $id ) ?? $row;
	}

	/**
	 * The customer resumes a row they paused (ADR-076). When its payment
	 * day passed meanwhile, it renews now and the next periods count from
	 * today. Returns that renewal order, or null when none was due.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function resume( int $id, TransitionContext $ctx ): ?Order {
		$row = $this->rows->find( $id ) ?? throw new \InvalidArgumentException( __( 'Subscription not found.', 'flexacommerce' ) );
		if ( ! $row->is_paused() ) {
			throw new \InvalidArgumentException( __( 'Only a subscription you paused can be resumed.', 'flexacommerce' ) );
		}
		return $this->unpause( $row, $ctx ) ? $this->renew( $id, $ctx ) : null;
	}

	/**
	 * Active again. A row whose payment day passed while it waited, with no
	 * renewal left unpaid, renews from today instead of catching up one
	 * missed period per cron run. Returns whether it is due now.
	 */
	private function unpause( Subscription $row, TransitionContext $ctx ): bool {
		$now    = $this->clock->db_now();
		$fields = [
			'status'      => SubscriptionStatus::ACTIVE,
			'retry_count' => 0,
			'retry_at'    => null,
			'paused_at'   => null,
		];
		$due    = $row->next_payment_at !== null && $row->next_payment_at <= $now && $this->unpaid_renewal( $row ) === null;
		if ( $due ) {
			$fields += [
				'next_payment_at' => $now,
				'anchor_day'      => (int) substr( $now, 8, 2 ),
			];
		}
		$this->rows->update( $row->id, $fields, $now );
		$this->rows->add_event( $row->id, 'resumed', __( 'Resumed.', 'flexacommerce' ), null, $ctx->actor_type, $ctx->actor_id, $now );
		return $due;
	}

	/** Whether the customer can change the items or the address of the renewals (ADR-076). */
	public function can_change( Subscription $row ): bool {
		return $this->reprice !== null && $row->snapshot !== null && $row->next_payment_at !== null
			&& ( $row->status === SubscriptionStatus::ACTIVE || $row->is_paused() );
	}

	/**
	 * What the renewals would hold with other quantities or another
	 * address (ADR-076): priced again at today's prices, shipped and taxed
	 * to the address. `$quantities` maps an index of the snapshot's items
	 * to its new quantity. `$address` replaces the shipping address, or the
	 * billing one when nothing is shipped.
	 *
	 * @param array<int,int> $quantities
	 * @throws \InvalidArgumentException the change cannot be made, with a message for the customer
	 */
	public function quote_change( Subscription $row, array $quantities, ?AddressView $address ): Snapshot {
		if ( ! $this->can_change( $row ) || $row->snapshot === null || $this->reprice === null ) {
			throw new \InvalidArgumentException( __( 'This subscription cannot be changed now.', 'flexacommerce' ) );
		}
		[ $billing, $shipping ] = $this->addresses( $row, $address );
		if ( $billing === null ) {
			throw new \InvalidArgumentException( __( 'This subscription has no address to price it with. Contact the store to change it.', 'flexacommerce' ) );
		}
		return ( $this->reprice )( $row->snapshot, $quantities, $billing, $shipping, $row->customer_id );
	}

	/**
	 * Store a change quote_change() priced. The next renewal bills it; a
	 * renewal already waiting for payment keeps what it holds.
	 */
	public function apply_change( Subscription $row, Snapshot $snapshot, ?AddressView $address, TransitionContext $ctx ): Subscription {
		[ $billing, $shipping ] = $this->addresses( $row, $address );
		$now                    = $this->clock->db_now();
		$this->rows->update(
			$row->id,
			[
				'snapshot' => $snapshot->to_json(),
				'total'    => $snapshot->total,
				'billing'  => Subscription::address_json( $billing ),
				'shipping' => Subscription::address_json( $shipping ),
			],
			$now
		);
		$changed = $this->rows->find( $row->id ) ?? $row;
		$parts   = [];
		if ( $snapshot->title() !== $row->title() ) {
			/* translators: %s: what the subscription is for, like "Coffee beans × 2". */
			$parts[] = sprintf( __( 'Items changed to %s.', 'flexacommerce' ), $snapshot->title() );
		}
		if ( $address !== null ) {
			$parts[] = $row->shipping !== null ? __( 'Shipping address changed.', 'flexacommerce' ) : __( 'Billing address changed.', 'flexacommerce' );
		}
		/* translators: %s: the renewal price, like "$10.00 / month". */
		$parts[] = sprintf( __( 'Renewal price %s.', 'flexacommerce' ), $this->price_text( $changed ) );
		$this->rows->add_event( $row->id, 'changed', implode( ' ', $parts ), null, $ctx->actor_type, $ctx->actor_id, $now );
		return $changed;
	}

	/**
	 * The billing and shipping addresses with `$address` put in place. A
	 * new billing address keeps the email and phone of the old one when
	 * the form left them out.
	 *
	 * @return array{0:?AddressView,1:?AddressView}
	 */
	private function addresses( Subscription $row, ?AddressView $address ): array {
		if ( $address === null ) {
			return [ $row->billing, $row->shipping ];
		}
		if ( $row->shipping !== null ) {
			return [ $row->billing ?? new AddressView( 'billing', email: $row->customer_email ), self::typed( $address, 'shipping' ) ];
		}
		$email = $address->email !== '' ? $address->email : ( $row->billing->email ?? $row->customer_email );
		$phone = $address->phone !== '' ? $address->phone : ( $row->billing->phone ?? '' );
		return [ self::typed( $address, 'billing', $email, $phone ), null ];
	}

	private static function typed( AddressView $a, string $type, ?string $email = null, ?string $phone = null ): AddressView {
		return new AddressView( $type, $a->first_name, $a->last_name, $a->company, $a->address_1, $a->address_2, $a->city, $a->state, $a->postcode, $a->country, $email ?? $a->email, $phone ?? $a->phone );
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
				'paused_at'       => null,
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
