<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Orders;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * One order line: a product, shipping, fee, discount or tax line (doc 03
 * §4.3). Name, SKU, attributes and unit price are snapshots taken when the
 * order was placed.
 *
 * @api
 */
final class OrderItemView {
	/** @param list<array{label:string,value:string}> $attributes */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly ?int $product_id,
		public readonly ?int $variant_id,
		public readonly string $code,
		public readonly string $name,
		public readonly ?string $sku,
		public readonly array $attributes,
		public readonly int $quantity,
		public readonly Money $unit_price,
		public readonly Money $subtotal,
		public readonly Money $tax_total,
		public readonly Money $total,
		public readonly int $refunded_qty,
		public readonly Money $refunded_total,
	) {}
}
