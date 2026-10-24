<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Pricing\PriceResolution;
use Flexa\Commerce\Inventory\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * A stored line resolved against the current catalog: prices recomputed,
 * problems flagged rather than dropped (doc 02 §7.3).
 */
final class CartItem {
	public const OK                 = 'ok';
	public const UNAVAILABLE        = 'unavailable';
	public const INSUFFICIENT_STOCK = 'insufficient_stock';

	public function __construct(
		public readonly CartLine $line,
		public readonly ?Product $product,
		public readonly ?Variant $variant,
		public readonly ?PriceResolution $unit,
		public readonly ?Money $total,
		public readonly ?Availability $availability,
		public readonly string $status,
	) {}

	public function is_ok(): bool {
		return $this->status === self::OK;
	}

	public function max_quantity( int $line_max ): int {
		$stock = $this->availability?->max_quantity();
		return $stock === null ? $line_max : min( $line_max, $stock );
	}
}
