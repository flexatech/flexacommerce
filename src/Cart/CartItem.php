<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Catalog\BundlePart;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\SubscriptionPlan;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Pricing\PriceResolution;
use Flexa\Commerce\Contracts\Shipping\PackageLine;
use Flexa\Commerce\Inventory\Availability;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A stored line resolved against the current catalog: prices recomputed,
 * problems flagged rather than dropped (doc 02 §7.3).
 */
final class CartItem {
	public const OK                 = 'ok';
	public const UNAVAILABLE        = 'unavailable';
	public const INSUFFICIENT_STOCK = 'insufficient_stock';

	/**
	 * @param array<string,string> $option_stock the option choices that track stock, key => name (ADR-040)
	 * @param list<BundlePart>     $parts        what one unit of a bundle holds (ADR-071)
	 * @param ?Money               $recurring    a subscription line's price each period, before discounts (ADR-073)
	 */
	public function __construct(
		public readonly CartLine $line,
		public readonly ?Product $product,
		public readonly ?Variant $variant,
		public readonly ?PriceResolution $unit,
		public readonly ?Money $total,
		public readonly ?Availability $availability,
		public readonly string $status,
		public readonly int $fee = 0,
		public readonly array $option_stock = [],
		public readonly array $parts = [],
		public readonly ?SubscriptionPlan $plan = null,
		public readonly ?Money $recurring = null,
	) {}

	/** Nothing to ship: a virtual row, or a bundle of virtual rows (ADR-071). */
	public function is_virtual(): bool {
		if ( $this->parts !== [] ) {
			foreach ( $this->parts as $part ) {
				if ( ! $part->variant->virtual ) {
					return false;
				}
			}
			return true;
		}
		return $this->variant?->virtual === true;
	}

	/**
	 * What shipping prices for this line. A bundle ships as its parts, the
	 * line total shared out by their regular prices (ADR-071).
	 *
	 * @return list<PackageLine>
	 */
	public function package_lines(): array {
		if ( $this->total === null || $this->product === null || $this->variant === null ) {
			return [];
		}
		if ( $this->parts === [] ) {
			return $this->variant->virtual ? [] : [ new PackageLine( $this->line->variant_id, $this->line->quantity, $this->weight_g(), $this->dimensions_mm(), $this->total, $this->shipping_class() ) ];
		}
		$weights = array_map( static fn ( BundlePart $p ): int => max( 0, (int) $p->variant->regular_price ) * $p->quantity, $this->parts );
		$sum     = array_sum( $weights );
		$left    = $this->total->amount;
		$lines   = [];
		$last    = count( $this->parts ) - 1;
		foreach ( $this->parts as $i => $part ) {
			$share = $i === $last ? $left : ( $sum > 0 ? intdiv( $this->total->amount * $weights[ $i ], $sum ) : intdiv( $this->total->amount, $last + 1 ) );
			$left -= $share;
			if ( $part->variant->virtual ) {
				continue;
			}
			$lines[] = new PackageLine(
				$part->variant->id,
				$this->line->quantity * $part->quantity,
				self::weight_of( $part->product, $part->variant ),
				self::dimensions_of( $part->product, $part->variant ),
				new Money( $share, $this->total->currency ),
				self::class_of( $part->product, $part->variant )
			);
		}
		return $lines;
	}

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

	/** The row that holds the stock for this line, 0 when stock is not managed; a bundle's parts hold it. */
	public function stock_id(): int {
		if ( $this->product === null || $this->variant === null || $this->parts !== [] ) {
			return 0;
		}
		$owner = $this->product->stock_owner( $this->variant );
		return $owner->manage_stock ? $owner->id : 0;
	}

	/**
	 * The shipping class slug (ADR-068); '' for none and for a class the
	 * store has since removed, so the line ships at the no-class cost.
	 */
	public function shipping_class(): string {
		return $this->product === null || $this->variant === null ? '' : self::class_of( $this->product, $this->variant );
	}

	/** Unit weight in grams; a variation without its own weight uses the product's. A bundle weighs what its parts do. */
	public function weight_g(): ?int {
		if ( $this->parts !== [] ) {
			$grams = null;
			foreach ( $this->parts as $part ) {
				$one = self::weight_of( $part->product, $part->variant );
				if ( $one !== null ) {
					$grams = (int) $grams + $one * $part->quantity;
				}
			}
			return $grams;
		}
		return self::weight_of( $this->product, $this->variant );
	}

	/** @return array{0:int,1:int,2:int}|null length, width, height in millimetres; null for a bundle, which ships as its parts */
	public function dimensions_mm(): ?array {
		return $this->parts !== [] ? null : self::dimensions_of( $this->product, $this->variant );
	}

	private static function class_of( Product $product, Variant $variant ): string {
		$slug = $product->shipping_class_of( $variant );
		if ( $slug === '' ) {
			return '';
		}
		foreach ( (array) Settings::get( 'shipping_classes' ) as $class ) {
			if ( is_array( $class ) && ( $class['slug'] ?? null ) === $slug ) {
				return $slug;
			}
		}
		return '';
	}

	private static function weight_of( ?Product $product, ?Variant $variant ): ?int {
		$value = $variant->weight ?? $product?->base->weight;
		return $value !== null && is_numeric( $value ) ? (int) round( (float) $value ) : null;
	}

	/** @return array{0:int,1:int,2:int}|null */
	private static function dimensions_of( ?Product $product, ?Variant $own ): ?array {
		$base = $product?->base;
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
