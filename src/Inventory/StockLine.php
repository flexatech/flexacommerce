<?php

declare(strict_types=1);

namespace Flexa\Commerce\Inventory;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;

defined( 'ABSPATH' ) || exit;

/** A quantity of one sellable row, input of deduct() and release(). */
final class StockLine {
	public function __construct(
		public readonly Product $product,
		public readonly Variant $variant,
		public readonly int $quantity,
	) {}
}
