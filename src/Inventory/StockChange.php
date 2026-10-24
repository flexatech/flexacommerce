<?php

declare(strict_types=1);

namespace Flexa\Commerce\Inventory;

defined( 'ABSPATH' ) || exit;

/** One applied stock movement of a stock owner row. */
final class StockChange {
	public function __construct(
		public readonly int $variant_id,
		public readonly int $product_id,
		public readonly int $old_quantity,
		public readonly int $new_quantity,
		public readonly string $old_status,
		public readonly string $new_status,
		public readonly string $reason,
		public readonly int $order_id = 0,
		public readonly ?int $low_threshold = null,
	) {}

	public function delta(): int {
		return $this->new_quantity - $this->old_quantity;
	}

	public function status_changed(): bool {
		return $this->old_status !== $this->new_status;
	}

	public function crossed_low(): bool {
		return $this->low_threshold !== null
			&& $this->new_quantity > 0
			&& $this->new_quantity <= $this->low_threshold
			&& $this->old_quantity > $this->low_threshold;
	}

	public function ran_out(): bool {
		return $this->new_quantity <= 0 && $this->old_quantity > 0;
	}
}
