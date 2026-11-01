<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Database\Transaction as DbTransaction;
use Flexa\Commerce\Notifications\EmailTemplates;
use Flexa\Commerce\Notifications\Mailer;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriptions with manual renewals (ADR-073). Checkout makes one
 * subscription per plan in the cart, `pending` until its order is paid.
 * The hourly cron makes a renewal order from the stored snapshot when a
 * payment day comes; the shopper pays it from the email or the account
 * page, and the subscription waits `on-hold` until then. A plan with a
 * set number of payments expires after the last paid period; a shopper
 * who cancels keeps what they paid for until its end.
 */
final class SubscriptionService {
	/** Rows handled per cron run, renewals and endings each. */
	public const BATCH = 25;

	/** User meta set on a customer's first subscription, so other accounts read no table. */
	public const USER_META = '_fxc_has_subscriptions';

	/** Order meta naming the subscription a renewal order bills. */
	public const ORDER_META = '_fxc_subscription';

	public const CREATED_VIA = 'subscription';

	public function __construct(
		private readonly SubscriptionRepository $rows,
		private readonly OrderService $orders,
		private readonly DbTransaction $db,
		private readonly Mailer $mailer,
		private readonly EmailTemplates $templates,
		private readonly MoneyFactory $money,
		private readonly Clock $clock,
		private readonly Logger $log,
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
	 * Renewals for the rows whose payment day has come, then the rows whose
	 * last period is over. Returns the number of rows handled.
	 */
	public function run(): int {
		$now  = $this->clock->db_now();
		$done = 0;
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
	 * A renewal order for the next period, now. The subscription waits on
	 * hold until the order is paid; a free renewal keeps it active. Out of
	 * stock puts it on hold with no order.
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
			return $this->db->run(
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
							'status' => $order->total > 0 && $order->status === OrderStatus::PENDING ? SubscriptionStatus::ON_HOLD : SubscriptionStatus::ACTIVE,
							'billed' => $billed,
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
			$this->rows->update( $id, [ 'status' => SubscriptionStatus::ACTIVE ], $now );
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
		if ( $row === null || $row->status !== SubscriptionStatus::ON_HOLD || $this->unpaid_renewal( $row ) !== null ) {
			return;
		}
		$now = $this->clock->db_now();
		$this->rows->update( $id, [ 'status' => SubscriptionStatus::ACTIVE ], $now );
		$this->rows->add_event( $id, 'renewal-paid', __( 'Renewal paid.', 'flexacommerce' ), $order_id, 'system', null, $now );
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
