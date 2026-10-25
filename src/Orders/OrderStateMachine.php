<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/**
 * The transition table of doc 02 §7.6. Same-status moves are not listed:
 * OrderService treats them as no-ops.
 */
final class OrderStateMachine {
	public const TRANSITIONS = [
		S::PENDING    => [ S::ON_HOLD, S::PROCESSING, S::CANCELLED, S::FAILED ],
		S::ON_HOLD    => [ S::PROCESSING, S::CANCELLED ],
		S::PROCESSING => [ S::ON_HOLD, S::COMPLETED, S::CANCELLED, S::REFUNDED ],
		S::COMPLETED  => [ S::PROCESSING, S::REFUNDED ],
		S::FAILED     => [ S::PENDING, S::PROCESSING, S::CANCELLED ],
		S::CANCELLED  => [],
		S::REFUNDED   => [],
	];

	public static function allows( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
	}

	public static function is_terminal( string $status ): bool {
		return ( self::TRANSITIONS[ $status ] ?? [] ) === [];
	}

	/** @return list<string> */
	public static function targets( string $from ): array {
		return self::TRANSITIONS[ $from ] ?? [];
	}

	/**
	 * Stock effect of a move: -1 deduct again, 1 release, 0 nothing. Moving
	 * to `refunded` never touches stock; refunds restock line by line.
	 */
	public static function stock_effect( string $from, string $to ): int {
		if ( $to === S::REFUNDED || $from === S::REFUNDED ) {
			return 0;
		}
		$held_before = S::holds_stock( $from );
		$held_after  = S::holds_stock( $to );
		if ( $held_before === $held_after ) {
			return 0;
		}
		return $held_after ? -1 : 1;
	}
}
