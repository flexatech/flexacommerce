<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/** A bundle item read against the catalog: the product, its row and the units one bundle takes (ADR-071). */
final class BundlePart {
	public function __construct(
		public readonly Product $product,
		public readonly Variant $variant,
		public readonly int $quantity,
	) {}

	/** The row that holds this part's stock. */
	public function owner(): Variant {
		return $this->product->stock_owner( $this->variant );
	}
}
