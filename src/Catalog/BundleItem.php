<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/** One row a bundle holds, and how many of it one bundle takes (ADR-071). */
final class BundleItem {
	public function __construct(
		public readonly int $product_id,
		public readonly int $variant_id,
		public readonly int $quantity,
	) {}

	/** @return array{product_id:int,variant_id:int,quantity:int} */
	public function to_array(): array {
		return [
			'product_id' => $this->product_id,
			'variant_id' => $this->variant_id,
			'quantity'   => $this->quantity,
		];
	}
}
