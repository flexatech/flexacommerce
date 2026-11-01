<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * Where a subscription is (ADR-073). `pending` waits for the first order
 * to be paid, `on-hold` for a renewal order, `pending-cancel` runs to the
 * end of the paid period and then becomes `cancelled`. `expired` ran all
 * its payments.
 */
final class SubscriptionStatus {
	public const PENDING        = 'pending';
	public const ACTIVE         = 'active';
	public const ON_HOLD        = 'on-hold';
	public const PENDING_CANCEL = 'pending-cancel';
	public const CANCELLED      = 'cancelled';
	public const EXPIRED        = 'expired';

	public const ALL = [ self::PENDING, self::ACTIVE, self::ON_HOLD, self::PENDING_CANCEL, self::CANCELLED, self::EXPIRED ];

	/** Statuses that end the subscription for good. */
	public const ENDED = [ self::CANCELLED, self::EXPIRED ];

	public static function label( string $status ): string {
		return match ( $status ) {
			self::PENDING        => __( 'Pending', 'flexacommerce' ),
			self::ACTIVE         => __( 'Active', 'flexacommerce' ),
			self::ON_HOLD        => __( 'On hold', 'flexacommerce' ),
			self::PENDING_CANCEL => __( 'Pending cancellation', 'flexacommerce' ),
			self::CANCELLED      => __( 'Cancelled', 'flexacommerce' ),
			self::EXPIRED        => __( 'Expired', 'flexacommerce' ),
			default              => $status,
		};
	}
}
