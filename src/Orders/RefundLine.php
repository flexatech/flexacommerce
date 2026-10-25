<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

defined( 'ABSPATH' ) || exit;

/** One order line in a refund: units returned, money for them, and whether the units go back to stock. */
final class RefundLine {
	public function __construct(
		public readonly int $order_item_id,
		public readonly int $qty = 0,
		public readonly int $amount = 0,
		public readonly bool $restock = false,
	) {
		if ( $qty < 0 || $amount < 0 ) {
			throw new \InvalidArgumentException( 'Refund quantities and amounts cannot be negative.' );
		}
	}

	/**
	 * @param array<mixed> $rows `[{item_id, qty, amount, restock}]` from a request
	 * @return list<self>
	 */
	public static function from_array( array $rows ): array {
		$lines = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['item_id'] ) ) {
				continue;
			}
			$lines[] = new self(
				max( 0, (int) $row['item_id'] ),
				max( 0, (int) ( $row['qty'] ?? 0 ) ),
				max( 0, (int) ( $row['amount'] ?? 0 ) ),
				! empty( $row['restock'] ),
			);
		}
		return $lines;
	}
}
