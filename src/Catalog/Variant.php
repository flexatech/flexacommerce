<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * One row of fxc_variants: a product's base row or one of its variations
 * (doc 03 §3, §4.1). Prices are integers in the currency's minor unit;
 * weight and dimensions are decimal strings in grams and millimetres.
 */
final class Variant {
	public const INSTOCK     = 'instock';
	public const OUTOFSTOCK  = 'outofstock';
	public const ONBACKORDER = 'onbackorder';

	public const STOCK_STATUSES = [ self::INSTOCK, self::OUTOFSTOCK, self::ONBACKORDER ];
	public const BACKORDERS     = [ 'no', 'notify', 'yes' ];

	/** @param array<string,mixed> $meta */
	public function __construct(
		public readonly int $id,
		public readonly int $product_id,
		public readonly bool $is_base,
		public readonly Combination $combination,
		public readonly bool $enabled = true,
		public readonly int $menu_order = 0,
		public readonly ?string $sku = null,
		public readonly ?int $regular_price = null,
		public readonly ?int $sale_price = null,
		public readonly ?string $sale_from = null,
		public readonly ?string $sale_to = null,
		public readonly bool $manage_stock = false,
		public readonly bool $stock_from_base = false,
		public readonly ?int $stock_quantity = null,
		public readonly string $backorders = 'no',
		public readonly ?int $low_stock_threshold = null,
		public readonly string $stock_status = self::INSTOCK,
		public readonly ?string $weight = null,
		public readonly ?string $length = null,
		public readonly ?string $width = null,
		public readonly ?string $height = null,
		public readonly ?int $image_id = null,
		public readonly string $tax_class = '',
		public readonly ?string $description = null,
		public readonly array $meta = [],
		public readonly string $created_at = '',
		public readonly string $updated_at = '',
	) {}

	public static function base_for( int $product_id ): self {
		return new self( id: 0, product_id: $product_id, is_base: true, combination: Combination::empty() );
	}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			product_id: (int) $row['product_id'],
			is_base: (bool) (int) $row['is_base'],
			combination: Combination::from_json( isset( $row['attributes'] ) ? (string) $row['attributes'] : null ),
			enabled: (bool) (int) ( $row['enabled'] ?? 1 ),
			menu_order: (int) ( $row['menu_order'] ?? 0 ),
			sku: self::nullable_string( $row['sku'] ?? null ),
			regular_price: self::nullable_int( $row['regular_price'] ?? null ),
			sale_price: self::nullable_int( $row['sale_price'] ?? null ),
			sale_from: self::nullable_string( $row['sale_from'] ?? null ),
			sale_to: self::nullable_string( $row['sale_to'] ?? null ),
			manage_stock: (bool) (int) ( $row['manage_stock'] ?? 0 ),
			stock_from_base: (bool) (int) ( $row['stock_from_base'] ?? 0 ),
			stock_quantity: self::nullable_int( $row['stock_quantity'] ?? null ),
			backorders: (string) ( $row['backorders'] ?? 'no' ),
			low_stock_threshold: self::nullable_int( $row['low_stock_threshold'] ?? null ),
			stock_status: (string) ( $row['stock_status'] ?? self::INSTOCK ),
			weight: self::nullable_string( $row['weight'] ?? null ),
			length: self::nullable_string( $row['length'] ?? null ),
			width: self::nullable_string( $row['width'] ?? null ),
			height: self::nullable_string( $row['height'] ?? null ),
			image_id: self::nullable_int( $row['image_id'] ?? null ),
			tax_class: (string) ( $row['tax_class'] ?? '' ),
			description: self::nullable_string( $row['description'] ?? null ),
			meta: self::decode_meta( $row['meta'] ?? null ),
			created_at: (string) ( $row['created_at'] ?? '' ),
			updated_at: (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Column values for an insert or update, without id and timestamps.
	 *
	 * @return array<string,int|string|null>
	 */
	public function to_row(): array {
		return [
			'product_id'          => $this->product_id,
			'is_base'             => $this->is_base ? 1 : 0,
			'combination_hash'    => $this->combination->hash(),
			'attributes'          => $this->combination->to_json(),
			'enabled'             => $this->enabled ? 1 : 0,
			'menu_order'          => $this->menu_order,
			'sku'                 => $this->sku,
			'regular_price'       => $this->regular_price,
			'sale_price'          => $this->sale_price,
			'sale_from'           => $this->sale_from,
			'sale_to'             => $this->sale_to,
			'manage_stock'        => $this->manage_stock ? 1 : 0,
			'stock_from_base'     => $this->stock_from_base ? 1 : 0,
			'stock_quantity'      => $this->stock_quantity,
			'backorders'          => $this->backorders,
			'low_stock_threshold' => $this->low_stock_threshold,
			'stock_status'        => $this->stock_status,
			'weight'              => $this->weight,
			'length'              => $this->length,
			'width'               => $this->width,
			'height'              => $this->height,
			'image_id'            => $this->image_id,
			'tax_class'           => $this->tax_class,
			'description'         => $this->description,
			'meta'                => $this->meta === [] ? null : json_encode( $this->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- THROW_ON_ERROR, wp_json_encode() swallows failures.
		];
	}

	/** @param array<string,mixed> $changes property => new value */
	public function with( array $changes ): self {
		$props = get_object_vars( $this );
		foreach ( $changes as $key => $value ) {
			if ( array_key_exists( $key, $props ) ) {
				$props[ $key ] = $value;
			}
		}
		return new self( ...$props );
	}

	/**
	 * Price before the `flexacommerce.price.unit` filter, which Pricing
	 * applies (doc 03 §3.3). `$now` is a UTC `Y-m-d H:i:s` string.
	 */
	public function active_price( string $now ): ?int {
		if ( $this->regular_price === null ) {
			return null;
		}
		return $this->is_on_sale( $now ) ? (int) $this->sale_price : $this->regular_price;
	}

	public function is_on_sale( string $now ): bool {
		return $this->regular_price !== null
			&& $this->sale_price !== null
			&& $this->sale_price < $this->regular_price
			&& ( $this->sale_from === null || $this->sale_from <= $now )
			&& ( $this->sale_to === null || $now < $this->sale_to );
	}

	/** The next moment the active price changes because of the sale schedule. */
	public function next_price_change( string $now ): ?string {
		if ( $this->sale_price === null || $this->regular_price === null ) {
			return null;
		}
		foreach ( [ $this->sale_from, $this->sale_to ] as $moment ) {
			if ( $moment !== null && $moment > $now ) {
				return $moment;
			}
		}
		return null;
	}

	/** Stock status implied by quantity and backorders (doc 03 §3.4). */
	public function computed_stock_status(): string {
		if ( ! $this->manage_stock ) {
			return in_array( $this->stock_status, self::STOCK_STATUSES, true ) ? $this->stock_status : self::INSTOCK;
		}
		if ( (int) $this->stock_quantity > 0 ) {
			return self::INSTOCK;
		}
		return $this->backorders === 'no' ? self::OUTOFSTOCK : self::ONBACKORDER;
	}

	public function has_stock_on_hand(): bool {
		return $this->manage_stock && (int) $this->stock_quantity > 0;
	}

	/** @return array<string,mixed> JS-friendly shape for the admin REST API. */
	public function to_array(): array {
		return [
			'id'                  => $this->id,
			'product_id'          => $this->product_id,
			'is_base'             => $this->is_base,
			'attributes'          => (object) $this->combination->to_array(),
			'enabled'             => $this->enabled,
			'menu_order'          => $this->menu_order,
			'sku'                 => $this->sku,
			'regular_price'       => $this->regular_price,
			'sale_price'          => $this->sale_price,
			'sale_from'           => $this->sale_from,
			'sale_to'             => $this->sale_to,
			'manage_stock'        => $this->manage_stock,
			'stock_from_base'     => $this->stock_from_base,
			'stock_quantity'      => $this->stock_quantity,
			'backorders'          => $this->backorders,
			'low_stock_threshold' => $this->low_stock_threshold,
			'stock_status'        => $this->stock_status,
			'weight'              => $this->weight,
			'length'              => $this->length,
			'width'               => $this->width,
			'height'              => $this->height,
			'image_id'            => $this->image_id,
			'tax_class'           => $this->tax_class,
			'description'         => $this->description,
		];
	}

	private static function nullable_int( mixed $value ): ?int {
		return $value === null || $value === '' ? null : (int) $value;
	}

	private static function nullable_string( mixed $value ): ?string {
		return $value === null || $value === '' ? null : (string) $value;
	}

	/** @return array<string,mixed> */
	private static function decode_meta( mixed $json ): array {
		if ( ! is_string( $json ) || $json === '' ) {
			return [];
		}
		try {
			$data = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return [];
		}
		return is_array( $data ) ? $data : [];
	}
}
