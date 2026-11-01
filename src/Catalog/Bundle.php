<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;

defined( 'ABSPATH' ) || exit;

/**
 * What a bundle product holds (ADR-071): rows of other products, each with
 * a quantity, and how its price is set. `fixed` uses the bundle's own
 * prices; `sum` adds up the parts; `discount` takes `discount` percent off
 * that sum. The bundle keeps no stock: each sale takes the parts' stock.
 */
final class Bundle {
	public const FIXED     = 'fixed';
	public const SUM       = 'sum';
	public const DISCOUNT  = 'discount';
	public const MODES     = [ self::FIXED, self::SUM, self::DISCOUNT ];
	public const MAX_ITEMS = 20;
	public const MAX_QTY   = 999;

	/** @param list<BundleItem> $items in the order the shop lists them */
	public function __construct(
		public readonly string $mode = self::FIXED,
		public readonly int $discount = 0,
		public readonly array $items = [],
	) {}

	/** Whether the price comes from the parts, so the bundle's own price fields are not used. */
	public function is_computed(): bool {
		return $this->mode !== self::FIXED;
	}

	/** The parts' sum less the discount, rounded half up. */
	public function price_of( int $sum ): int {
		return $this->mode === self::DISCOUNT ? intdiv( $sum * ( 100 - $this->discount ) + 50, 100 ) : $sum;
	}

	/** @return list<int> */
	public function variant_ids(): array {
		return array_map( static fn ( BundleItem $item ): int => $item->variant_id, $this->items );
	}

	/** @return list<int> */
	public function product_ids(): array {
		return array_values( array_unique( array_map( static fn ( BundleItem $item ): int => $item->product_id, $this->items ) ) );
	}

	public static function from_meta( string $json ): self {
		if ( $json === '' ) {
			return new self();
		}
		try {
			$data = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return new self();
		}
		if ( ! is_array( $data ) ) {
			return new self();
		}
		$items = [];
		foreach ( is_array( $data['items'] ?? null ) ? $data['items'] : [] as $item ) {
			if ( is_array( $item ) && (int) ( $item['variant_id'] ?? 0 ) > 0 && (int) ( $item['product_id'] ?? 0 ) > 0 ) {
				$items[] = new BundleItem( (int) $item['product_id'], (int) $item['variant_id'], max( 1, min( self::MAX_QTY, (int) ( $item['quantity'] ?? 1 ) ) ) );
			}
		}
		$mode = in_array( $data['mode'] ?? null, self::MODES, true ) ? (string) $data['mode'] : self::FIXED;
		return new self( $mode, $mode === self::DISCOUNT ? max( 0, min( 99, (int) ( $data['discount'] ?? 0 ) ) ) : 0, array_slice( $items, 0, self::MAX_ITEMS ) );
	}

	public function to_meta(): string {
		return (string) wp_json_encode( $this->to_array() );
	}

	/** @return array{mode:string,discount:int,items:list<array{product_id:int,variant_id:int,quantity:int}>} */
	public function to_array(): array {
		return [
			'mode'     => $this->mode,
			'discount' => $this->discount,
			'items'    => array_map( static fn ( BundleItem $item ): array => $item->to_array(), $this->items ),
		];
	}

	/**
	 * Reads the admin REST payload; the rows are checked by ProductService.
	 * An item names a variant row (`variant_id`), or a simple product
	 * (`product_id` alone).
	 *
	 * @throws InvalidProduct
	 */
	public static function from_input( mixed $raw ): self {
		if ( ! is_array( $raw ) ) {
			throw new InvalidProduct( __( 'The bundle must be an object.', 'flexacommerce' ), 'bundle' );
		}
		$mode = $raw['mode'] ?? self::FIXED;
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new InvalidProduct( __( 'Unknown bundle price mode.', 'flexacommerce' ), 'bundle.mode' );
		}
		$discount = 0;
		if ( $mode === self::DISCOUNT ) {
			$discount = is_numeric( $raw['discount'] ?? null ) ? (int) $raw['discount'] : -1;
			if ( $discount < 1 || $discount > 99 ) {
				throw new InvalidProduct( __( 'Enter a discount from 1 to 99 percent.', 'flexacommerce' ), 'bundle.discount' );
			}
		}
		$items = [];
		$list  = $raw['items'] ?? [];
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			throw new InvalidProduct( __( 'The bundle items must be a list.', 'flexacommerce' ), 'bundle.items' );
		}
		if ( count( $list ) > self::MAX_ITEMS ) {
			throw new InvalidProduct( __( 'A bundle can hold at most 20 items.', 'flexacommerce' ), 'bundle.items' );
		}
		foreach ( $list as $i => $item ) {
			$item     = is_array( $item ) ? $item : [];
			$quantity = is_numeric( $item['quantity'] ?? 1 ) ? (int) ( $item['quantity'] ?? 1 ) : 0;
			if ( $quantity < 1 || $quantity > self::MAX_QTY ) {
				throw new InvalidProduct( __( 'Enter a quantity from 1 to 999.', 'flexacommerce' ), 'bundle.items.' . $i . '.quantity' );
			}
			$items[] = new BundleItem( max( 0, (int) ( $item['product_id'] ?? 0 ) ), max( 0, (int) ( $item['variant_id'] ?? 0 ) ), $quantity );
		}
		return new self( $mode, $discount, $items );
	}
}
