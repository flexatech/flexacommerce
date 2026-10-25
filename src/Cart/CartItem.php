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

	/*
	 * Snapshots for checkout, which may not read the catalog itself
	 * (doc 02 §3.2). They are only meaningful for an item that is_ok().
	 */

	public function name(): string {
		return $this->product !== null ? $this->product->title : '';
	}

	public function sku(): ?string {
		return $this->variant->sku ?? $this->product?->base->sku;
	}

	/** The row that holds the stock for this line, 0 when stock is not managed. */
	public function stock_id(): int {
		if ( $this->product === null || $this->variant === null ) {
			return 0;
		}
		$owner = $this->product->stock_owner( $this->variant );
		return $owner->manage_stock ? $owner->id : 0;
	}

	/** Unit weight in grams; a variation without its own weight uses the product's. */
	public function weight_g(): ?int {
		$value = $this->variant->weight ?? $this->product?->base->weight;
		return $value !== null && is_numeric( $value ) ? (int) round( (float) $value ) : null;
	}

	/** @return array{0:int,1:int,2:int}|null length, width, height in millimetres */
	public function dimensions_mm(): ?array {
		$own  = $this->variant;
		$base = $this->product?->base;
		$mm   = [];
		foreach ( [ $own->length ?? $base?->length, $own->width ?? $base?->width, $own->height ?? $base?->height ] as $value ) {
			if ( $value === null || ! is_numeric( $value ) ) {
				return null;
			}
			$mm[] = (int) round( (float) $value );
		}
		return [ $mm[0], $mm[1], $mm[2] ];
	}
}
