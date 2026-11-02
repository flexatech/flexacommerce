<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Catalog\SubscriptionPlan;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalGateway;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeGateway;
use Flexa\Commerce\Subscriptions\Snapshot;
use Flexa\Commerce\Subscriptions\Subscription;
use Flexa\Commerce\Subscriptions\SubscriptionRepository;
use Flexa\Commerce\Subscriptions\SubscriptionService;
use Flexa\Commerce\Subscriptions\SubscriptionStatus;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions subscriptions into fxc_subscriptions (ADR-079).
 * A row comes in with billing held: WooCommerce Subscriptions may still be
 * charging it, so nothing renews here until the merchant starts billing.
 * A row already released is left alone. Runs inside the target's
 * transaction.
 */
final class FxcSubscriptionWriter {
	/** WooCommerce Subscriptions statuses => FlexaCommerce ones. A switched subscription was replaced by another. */
	private const STATUSES = [
		'pending'        => SubscriptionStatus::PENDING,
		'active'         => SubscriptionStatus::ACTIVE,
		'on-hold'        => SubscriptionStatus::ON_HOLD,
		'pending-cancel' => SubscriptionStatus::PENDING_CANCEL,
		'cancelled'      => SubscriptionStatus::CANCELLED,
		'switched'       => SubscriptionStatus::CANCELLED,
		'expired'        => SubscriptionStatus::EXPIRED,
	];

	/** WooCommerce gateway ids => the built-in gateway that takes the renewals paid by hand. */
	private const GATEWAYS = [
		'stripe'                   => StripeGateway::ID,
		'ppcp-gateway'             => PayPalGateway::ID,
		'ppcp-credit-card-gateway' => PayPalGateway::ID,
	];

	private const RELATIONS = [ SubscriptionRepository::PARENT, SubscriptionRepository::RENEWAL, SubscriptionRepository::SWITCH ];

	/** @param \Closure():SubscriptionRepository $rows */
	public function __construct(
		private readonly \Closure $rows,
		private readonly FxcOrderWriter $orders,
		private readonly Clock $clock,
	) {}

	public function subscription( Record $record, Mapping $map ): WriteResult {
		$data     = $record->data;
		$rows     = ( $this->rows )();
		$problems = [];
		$currency = strtoupper( (string) ( $data['currency'] ?? Settings::string( 'currency' ) ) );
		$exponent = Currency::exponent( $currency );

		$target   = $map->target_id( Record::SUBSCRIPTION, $record->source_id() );
		$existing = $target > 0 ? $rows->find( $target ) : null;
		if ( $existing !== null && ! $existing->billing_held ) {
			return WriteResult::skipped( [ Problem::info( 'billing_started', 'FlexaCommerce already bills this subscription, so the copy in WooCommerce is not read again.' ) ] );
		}

		$source = (string) ( $data['status'] ?? '' );
		$status = self::STATUSES[ $source ] ?? null;
		if ( $status === null ) {
			$status     = SubscriptionStatus::ON_HOLD;
			$problems[] = Problem::warning( 'custom_status', sprintf( 'The status "%s" has no match; the subscription is on hold.', $source ) );
		}

		$inclusive               = (bool) ( $data['prices_include_tax'] ?? false );
		[ $items, $item_issues ] = $this->orders->snapshot_items( is_array( $data['items'] ?? null ) ? $data['items'] : [], $inclusive, $map, $currency );
		$problems                = array_merge( $problems, $item_issues );
		$products                = array_filter( $items, static fn ( $item ): bool => $item->type === OrderItem::PRODUCT && $item->product_id !== null );
		if ( $products === [] ) {
			throw new Rejected( 'missing_product', 'None of the products of this subscription were imported.' );
		}

		$at       = static fn ( string $key ): ?string => Dates::to_db( is_string( $data[ $key ] ?? null ) ? $data[ $key ] : null );
		$now      = $this->clock->db_now();
		$created  = $at( 'created_at' ) ?? $now;
		$start    = $at( 'start_at' ) ?? $created;
		$trial    = $at( 'trial_end_at' );
		$next     = $at( 'next_payment_at' );
		$end      = $at( 'end_at' );
		$interval = (int) ( $data['interval'] ?? 1 );
		if ( $interval < 1 || $interval > SubscriptionPlan::MAX_INTERVAL ) {
			$interval   = max( 1, min( SubscriptionPlan::MAX_INTERVAL, $interval ) );
			$problems[] = Problem::warning( 'plan_clamped', sprintf( 'The billing interval was changed to %d.', $interval ) );
		}
		$trial_days = $trial !== null && $trial > $start ? (int) ceil( ( strtotime( $trial . ' UTC' ) - strtotime( $start . ' UTC' ) ) / DAY_IN_SECONDS ) : 0;
		$trial_days = min( SubscriptionPlan::MAX_TRIAL, $trial_days );
		$trial      = $trial_days > 0 ? $trial : null;
		$anchor     = (int) substr( $next ?? $trial ?? $start, 8, 2 );
		$plan       = new SubscriptionPlan( (string) ( $data['period'] ?? SubscriptionPlan::MONTH ), $interval, 0, $trial_days );

		$links       = [];
		$parent      = null;
		$billed      = 0;
		$missing     = 0;
		$parent_paid = false;
		foreach ( is_array( $data['orders'] ?? null ) ? $data['orders'] : [] as $link ) {
			$relation = is_array( $link ) ? (string) ( $link['relation'] ?? '' ) : '';
			if ( ! in_array( $relation, self::RELATIONS, true ) ) {
				continue;
			}
			$order = $map->target_id( Record::ORDER, Ref::parse( is_string( $link['order'] ?? null ) ? $link['order'] : null )->id ?? '' );
			if ( $order <= 0 ) {
				++$missing;
				continue;
			}
			$links[] = [ $order, $relation ];
			$paid    = ! empty( $link['paid'] );
			if ( $relation === SubscriptionRepository::PARENT ) {
				$parent      = $order;
				$parent_paid = $paid;
			} elseif ( $relation === SubscriptionRepository::RENEWAL && $paid ) {
				++$billed;
			}
		}
		// The first order pays the first period, unless it only started a free trial.
		$billed += $parent_paid && $trial === null ? 1 : 0;
		if ( $missing > 0 ) {
			$problems[] = Problem::warning( 'missing_order', sprintf( '%d orders of this subscription were not imported, so it does not link to them.', $missing ) );
		}

		// FlexaCommerce counts payments where WooCommerce Subscriptions keeps an end date.
		$length = 0;
		if ( $status === SubscriptionStatus::EXPIRED ) {
			$length = max( 1, $billed );
		} elseif ( $end !== null && in_array( $status, [ SubscriptionStatus::ACTIVE, SubscriptionStatus::ON_HOLD, SubscriptionStatus::PENDING ], true ) ) {
			$left = 0;
			while ( $next !== null && $left < SubscriptionPlan::MAX_LENGTH && $plan->after( $next, $left, $anchor ) < $end ) {
				++$left;
			}
			$length = max( 1, min( SubscriptionPlan::MAX_LENGTH, $billed + $left ) );
			if ( $left === 0 ) {
				$next = null;
			}
		}
		$plan = new SubscriptionPlan( $plan->period, $plan->interval, $length, $plan->trial_days );

		$money    = static function ( mixed $value ) use ( $currency, $exponent ): int {
			return Decimal::to_minor( Decimal::clean( $value ) ?? '0', $currency, $exponent )[0];
		};
		$totals   = is_array( $data['totals'] ?? null ) ? $data['totals'] : [];
		$total    = $money( $totals['total'] ?? '0' );
		$snapshot = new Snapshot(
			$plan,
			$items,
			$money( $totals['subtotal'] ?? '0' ),
			$money( $totals['shipping'] ?? '0' ),
			$money( $totals['tax'] ?? '0' ),
			$total,
			$inclusive,
			$money( $totals['discount'] ?? '0' ),
			$money( $totals['fees'] ?? '0' ),
		);

		$payment = is_array( $data['payment'] ?? null ) ? $data['payment'] : [];
		$gateway = (string) ( $payment['gateway'] ?? '' );
		$token   = $gateway !== '' ? (string) ( $payment['token'] ?? '' ) : '';
		$method  = (string) ( $data['payment_method'] ?? '' );
		if ( ! empty( $payment['provider_billed'] ) ) {
			$problems[] = Problem::warning( 'paypal_plan', 'PayPal bills this subscription through a PayPal plan. Cancel the plan in PayPal before you start billing here, or the customer pays twice.' );
		} elseif ( empty( $payment['manual'] ) && $token === '' && ! in_array( $status, SubscriptionStatus::ENDED, true ) && $total > 0 ) {
			$problems[] = Problem::warning( 'manual_renewals', sprintf( 'FlexaCommerce cannot charge the method saved with "%s"; the customer pays each renewal by hand.', $method ) );
		}

		$account  = Ref::int_id( is_string( $data['customer'] ?? null ) ? $data['customer'] : null );
		$customer = $map->user_id( $account, true );
		if ( $account > 0 && $customer === 0 ) {
			$problems[] = Problem::warning( 'missing_customer', sprintf( 'User %d no longer exists; the subscription has no account.', $account ) );
		}
		$billing  = FxcStoreWriter::address( 'billing', is_array( $data['addresses']['billing'] ?? null ) ? $data['addresses']['billing'] : [] );
		$shipping = FxcStoreWriter::address( 'shipping', is_array( $data['addresses']['shipping'] ?? null ) ? $data['addresses']['shipping'] : [] );
		$ended    = in_array( $status, SubscriptionStatus::ENDED, true );

		$fields = [
			'status'               => $status,
			'customer_id'          => $customer,
			'customer_email'       => mb_substr( (string) ( $billing->email ?? '' ), 0, 200 ),
			'currency'             => $currency,
			'period'               => $plan->period,
			'interval_count'       => $plan->interval,
			'length'               => $plan->length,
			'trial_days'           => $plan->trial_days,
			'anchor_day'           => $anchor,
			'billed'               => min( 65535, $billed ),
			'total'                => $total,
			'parent_order_id'      => $parent,
			'payment_method'       => mb_substr( $gateway !== '' ? $gateway : ( self::GATEWAYS[ $method ] ?? $method ), 0, 100 ),
			'payment_method_title' => mb_substr( (string) ( $data['payment_method_title'] ?? '' ), 0, 200 ),
			'payment_customer'     => $token !== '' ? mb_substr( (string) ( $payment['customer'] ?? '' ), 0, 255 ) : '',
			'payment_token'        => $token !== '' ? mb_substr( $token, 0, 255 ) : null,
			'payment_label'        => $token !== '' ? mb_substr( (string) ( $payment['label'] ?? '' ), 0, 200 ) : '',
			'billing_held'         => 1,
			'snapshot'             => $snapshot->to_json(),
			'billing'              => Subscription::address_json( $billing ),
			'shipping'             => Subscription::address_json( $shipping ),
			'start_at'             => $status === SubscriptionStatus::PENDING ? null : $start,
			'trial_end_at'         => $trial,
			'next_payment_at'      => $ended || $status === SubscriptionStatus::PENDING_CANCEL ? null : $next,
			'end_at'               => $end,
			'cancelled_at'         => $status === SubscriptionStatus::CANCELLED ? ( $at( 'cancelled_at' ) ?? $end ?? $now ) : null,
		];

		if ( $existing !== null ) {
			$rows->update( $existing->id, $fields, $now );
			$id     = $existing->id;
			$action = WriteResult::UPDATED;
		} else {
			$id = $rows->insert( $fields, $now );
			$rows->update( $id, [ 'created_at' => $created ], $now );
			foreach ( is_array( $data['history'] ?? null ) ? $data['history'] : [] as $note ) {
				if ( is_array( $note ) && is_string( $note['message'] ?? null ) && trim( $note['message'] ) !== '' ) {
					$rows->add_event( $id, 'note', wp_strip_all_tags( $note['message'] ), null, 'system', null, Dates::to_db( is_string( $note['created_at'] ?? null ) ? $note['created_at'] : null ) ?? $created );
				}
			}
			$rows->add_event( $id, 'imported', __( 'Imported from WooCommerce Subscriptions. Renewals wait until billing starts here.', 'flexacommerce' ), $parent, 'system', null, $now );
			$action = WriteResult::CREATED;
		}
		foreach ( $links as [ $order, $relation ] ) {
			$rows->link( $id, $order, $relation, $now );
		}
		if ( $customer > 0 ) {
			update_user_meta( $customer, SubscriptionService::USER_META, '1' );
		}

		$map->remember( Record::SUBSCRIPTION, $record->source_id(), $id, $record->checksum );
		return new WriteResult( $action, $id, $problems );
	}

	public function modified_at( int $id ): ?string {
		$row = ( $this->rows )()->find( $id );
		return $row?->updated_at;
	}

	public function delete( int $id ): bool {
		$rows = ( $this->rows )();
		if ( $rows->find( $id ) === null ) {
			return false;
		}
		$rows->delete( $id );
		return true;
	}
}
