<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

defined( 'ABSPATH' ) || exit;

/** Order statuses (doc 02 §7.6) and payment statuses (§7.7). */
final class OrderStatus {
	public const PENDING    = 'pending';
	public const ON_HOLD    = 'on-hold';
	public const PROCESSING = 'processing';
	public const COMPLETED  = 'completed';
	public const CANCELLED  = 'cancelled';
	public const FAILED     = 'failed';
	public const REFUNDED   = 'refunded';

	public const ALL = [ self::PENDING, self::ON_HOLD, self::PROCESSING, self::COMPLETED, self::CANCELLED, self::FAILED, self::REFUNDED ];

	/** Statuses a new order may start in. */
	public const INITIAL = [ self::PENDING, self::ON_HOLD, self::PROCESSING ];

	/** Statuses in which the order holds its stock (§7.4). */
	public const HOLDS_STOCK = [ self::PENDING, self::ON_HOLD, self::PROCESSING, self::COMPLETED ];

	public const UNPAID             = 'unpaid';
	public const PARTIALLY_PAID     = 'partially_paid';
	public const PAID               = 'paid';
	public const PARTIALLY_REFUNDED = 'partially_refunded';
	public const FULLY_REFUNDED     = 'refunded';

	public const PAYMENT_STATUSES = [ self::UNPAID, self::PARTIALLY_PAID, self::PAID, self::PARTIALLY_REFUNDED, self::FULLY_REFUNDED ];

	public static function holds_stock( string $status ): bool {
		return in_array( $status, self::HOLDS_STOCK, true );
	}

	/** Derived from the stored sums on every payment event (§7.7). */
	public static function payment_status( int $total, int $paid, int $refunded ): string {
		if ( $refunded > 0 ) {
			return $refunded >= $paid ? self::FULLY_REFUNDED : self::PARTIALLY_REFUNDED;
		}
		if ( $paid >= $total && ( $paid > 0 || $total === 0 ) ) {
			return self::PAID;
		}
		return $paid > 0 ? self::PARTIALLY_PAID : self::UNPAID;
	}

	public static function label( string $status ): string {
		return match ( $status ) {
			self::PENDING    => __( 'Pending payment', 'flexacommerce' ),
			self::ON_HOLD    => __( 'On hold', 'flexacommerce' ),
			self::PROCESSING => __( 'Processing', 'flexacommerce' ),
			self::COMPLETED  => __( 'Completed', 'flexacommerce' ),
			self::CANCELLED  => __( 'Cancelled', 'flexacommerce' ),
			self::FAILED     => __( 'Failed', 'flexacommerce' ),
			self::REFUNDED   => __( 'Refunded', 'flexacommerce' ),
			default          => $status,
		};
	}

	public static function payment_label( string $status ): string {
		return match ( $status ) {
			self::UNPAID             => __( 'Unpaid', 'flexacommerce' ),
			self::PARTIALLY_PAID     => __( 'Partially paid', 'flexacommerce' ),
			self::PAID               => __( 'Paid', 'flexacommerce' ),
			self::PARTIALLY_REFUNDED => __( 'Partially refunded', 'flexacommerce' ),
			self::FULLY_REFUNDED     => __( 'Refunded', 'flexacommerce' ),
			default                  => $status,
		};
	}
}
