<?php

declare(strict_types=1);

namespace Flexa\Commerce\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * A quantity of one stock owner row, without the catalog objects. Orders keep
 * `stock_id` on each item so cancel and refund can put stock back without
 * loading products (Orders does not depend on Catalog).
 */
final class StockUnit {
	public function __construct(
		public readonly int $stock_id,
		public readonly int $product_id,
		public readonly int $variant_id,
		public readonly int $quantity,
	) {}

	public static function of( StockLine $line ): self {
		$owner = $line->product->stock_owner( $line->variant );
		return new self( $owner->id, $line->product->id, $line->variant->id, $line->quantity );
	}
}
