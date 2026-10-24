<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Cart;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the cart for extensions (doc 04 §5).
 *
 * @api
 */
final class CartView {
	/** @param list<array{key:string,product_id:int,variant_id:int,quantity:int,attributes:array<string,string>}> $lines */
	public function __construct(
		public readonly array $lines,
		public readonly int $item_count,
		public readonly Money $subtotal,
	) {}

	public function quantity_of( int $variant_id ): int {
		$total = 0;
		foreach ( $this->lines as $line ) {
			if ( $line['variant_id'] === $variant_id ) {
				$total += $line['quantity'];
			}
		}
		return $total;
	}
}
