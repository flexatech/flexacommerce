<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of a sellable row for extensions (doc 04 §5). Prices are
 * integers in the store currency's minor unit. `stock_quantity` is the
 * quantity of the row that holds the stock, null when stock is not managed.
 *
 * @api
 */
final class VariantView {
	/** @param array<string,string> $attributes combination, `*` for "any" */
	public function __construct(
		public readonly int $id,
		public readonly int $product_id,
		public readonly string $product_type,
		public readonly bool $is_base,
		public readonly ?string $sku,
		public readonly array $attributes,
		public readonly ?int $regular_price,
		public readonly ?int $sale_price,
		public readonly bool $manage_stock,
		public readonly ?int $stock_quantity,
		public readonly string $stock_status,
		public readonly string $tax_class,
		public readonly string $tax_status = 'taxable',
	) {}
}
