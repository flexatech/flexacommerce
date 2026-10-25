<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;

defined( 'ABSPATH' ) || exit;

/**
 * Coerced product data for ProductService::save(). A null member means
 * "keep what is stored"; inside a row, only the fields present in the input
 * change (doc 03 §3).
 */
final class ProductInput {
	public const MAX_SKU_LENGTH = 100;

	private const DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
	private const DECIMAL  = '/^\d{1,9}(\.\d{1,3})?$/';

	/**
	 * @param list<array<string,mixed>>|null                                       $attributes raw definitions, validated by ProductService
	 * @param array<string,mixed>|null                                             $base       Variant property => value
	 * @param list<array{id:int,combination:?Combination,fields:array<string,mixed>}>|null $variations
	 * @param list<int>|null                                                       $gallery_ids
	 */
	public function __construct(
		public readonly ?string $type = null,
		public readonly ?array $attributes = null,
		public readonly ?array $base = null,
		public readonly ?array $variations = null,
		public readonly ?array $gallery_ids = null,
		public readonly ?string $visibility = null,
		public readonly ?bool $featured = null,
	) {}

	/**
	 * Reads the admin REST payload.
	 *
	 * @param array<array-key,mixed> $data
	 * @throws InvalidProduct
	 */
	public static function from_array( array $data ): self {
		$type = null;
		if ( array_key_exists( 'type', $data ) ) {
			$type = is_string( $data['type'] ) ? $data['type'] : '';
			if ( ! ProductTypes::is_valid( $type ) ) {
				throw new InvalidProduct( __( 'Unknown product type.', 'flexacommerce' ), 'type' );
			}
		}

		$attributes = null;
		if ( array_key_exists( 'attributes', $data ) ) {
			$attributes = [];
			foreach ( self::list_of( $data['attributes'], 'attributes' ) as $entry ) {
				$attributes[] = is_array( $entry ) ? $entry : [];
			}
		}

		$base = array_key_exists( 'base', $data )
			? self::fields( self::map_of( $data['base'], 'base' ), 'base' )
			: null;

		$variations = null;
		if ( array_key_exists( 'variations', $data ) ) {
			$variations = [];
			foreach ( self::list_of( $data['variations'], 'variations' ) as $i => $entry ) {
				$path         = 'variations.' . $i;
				$entry        = self::map_of( $entry, $path );
				$variations[] = [
					'id'          => isset( $entry['id'] ) ? max( 0, (int) $entry['id'] ) : 0,
					'combination' => array_key_exists( 'attributes', $entry ) ? self::combination( $entry['attributes'], $path . '.attributes' ) : null,
					'fields'      => self::fields( $entry, $path ),
				];
			}
		}

		$gallery = null;
		if ( array_key_exists( 'gallery_ids', $data ) ) {
			$ids     = array_map( 'intval', self::list_of( $data['gallery_ids'], 'gallery_ids' ) );
			$gallery = array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
		}

		$visibility = null;
		if ( array_key_exists( 'visibility', $data ) ) {
			$visibility = is_string( $data['visibility'] ) ? $data['visibility'] : '';
			if ( ! in_array( $visibility, Product::VISIBILITIES, true ) ) {
				throw new InvalidProduct( __( 'Unknown catalog visibility.', 'flexacommerce' ), 'visibility' );
			}
		}

		return new self(
			type: $type,
			attributes: $attributes,
			base: $base,
			variations: $variations,
			gallery_ids: $gallery,
			visibility: $visibility,
			featured: array_key_exists( 'featured', $data ) ? self::to_bool( $data['featured'] ) : null,
		);
	}

	/**
	 * Row fields present in `$data`, coerced to Variant property types.
	 *
	 * @param array<array-key,mixed> $data
	 * @return array<string,mixed>
	 * @throws InvalidProduct
	 */
	public static function fields( array $data, string $path ): array {
		$out = [];
		foreach ( [ 'enabled', 'manage_stock', 'stock_from_base' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = self::to_bool( $data[ $key ] );
			}
		}
		if ( array_key_exists( 'menu_order', $data ) ) {
			$out['menu_order'] = (int) $data['menu_order'];
		}
		if ( array_key_exists( 'sku', $data ) ) {
			$out['sku'] = self::sku( $data['sku'], $path . '.sku' );
		}
		foreach ( [ 'regular_price', 'sale_price', 'low_stock_threshold', 'image_id' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = self::non_negative_int( $data[ $key ], $path . '.' . $key );
			}
		}
		if ( array_key_exists( 'image_id', $out ) && $out['image_id'] === 0 ) {
			$out['image_id'] = null;
		}
		if ( array_key_exists( 'stock_quantity', $data ) ) {
			$out['stock_quantity'] = self::int_or_null( $data['stock_quantity'], $path . '.stock_quantity' );
		}
		foreach ( [ 'sale_from', 'sale_to' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = self::datetime( $data[ $key ], $path . '.' . $key );
			}
		}
		foreach ( [ 'weight', 'length', 'width', 'height' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = self::decimal( $data[ $key ], $path . '.' . $key );
			}
		}
		if ( array_key_exists( 'backorders', $data ) ) {
			$out['backorders'] = self::one_of( $data['backorders'], Variant::BACKORDERS, $path . '.backorders' );
		}
		if ( array_key_exists( 'stock_status', $data ) ) {
			$out['stock_status'] = self::one_of( $data['stock_status'], Variant::STOCK_STATUSES, $path . '.stock_status' );
		}
		if ( array_key_exists( 'tax_class', $data ) ) {
			$out['tax_class'] = is_scalar( $data['tax_class'] ) ? substr( sanitize_title( (string) $data['tax_class'] ), 0, 50 ) : '';
		}
		if ( array_key_exists( 'tax_status', $data ) ) {
			$out['tax_status'] = self::one_of( $data['tax_status'], Variant::TAX_STATUSES, $path . '.tax_status' );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$text               = is_scalar( $data['description'] ) ? trim( wp_kses_post( (string) $data['description'] ) ) : '';
			$out['description'] = $text === '' ? null : $text;
		}
		return $out;
	}

	/** Trimmed, NULL when empty, at most 100 characters (doc 03 §3.2). */
	public static function sku( mixed $value, string $path = 'sku' ): ?string {
		if ( $value === null ) {
			return null;
		}
		if ( ! is_scalar( $value ) ) {
			throw new InvalidProduct( __( 'The SKU must be text.', 'flexacommerce' ), $path );
		}
		$sku = trim( sanitize_text_field( (string) $value ) );
		if ( $sku === '' ) {
			return null;
		}
		if ( mb_strlen( $sku ) > self::MAX_SKU_LENGTH ) {
			throw new InvalidProduct( __( 'The SKU can be at most 100 characters long.', 'flexacommerce' ), $path );
		}
		return $sku;
	}

	private static function combination( mixed $value, string $path ): Combination {
		if ( $value === null || $value === [] ) {
			return Combination::empty();
		}
		if ( ! is_array( $value ) ) {
			throw new InvalidProduct( __( 'Variation attributes must be an object.', 'flexacommerce' ), $path );
		}
		$pairs = [];
		foreach ( $value as $key => $item ) {
			$pairs[ (string) $key ] = is_scalar( $item ) ? (string) $item : '';
		}
		try {
			return Combination::from_array( $pairs );
		} catch ( \InvalidArgumentException ) {
			throw new InvalidProduct( __( 'Variation attributes are not valid.', 'flexacommerce' ), $path );
		}
	}

	private static function non_negative_int( mixed $value, string $path ): ?int {
		$int = self::int_or_null( $value, $path );
		if ( $int !== null && $int < 0 ) {
			throw new InvalidProduct( __( 'The value cannot be negative.', 'flexacommerce' ), $path );
		}
		return $int;
	}

	private static function int_or_null( mixed $value, string $path ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^-?\d{1,18}$/', $value ) === 1 ) {
			return (int) $value;
		}
		throw new InvalidProduct( __( 'A whole number is required.', 'flexacommerce' ), $path );
	}

	private static function datetime( mixed $value, string $path ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		if ( ! is_string( $value ) || preg_match( self::DATETIME, $value ) !== 1 ) {
			throw new InvalidProduct( __( 'Dates must use the format YYYY-MM-DD HH:MM:SS (UTC).', 'flexacommerce' ), $path );
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( $parsed === false || $parsed->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new InvalidProduct( __( 'The date does not exist.', 'flexacommerce' ), $path );
		}
		return $value;
	}

	/** Canonical grams or millimetres, up to three decimals. */
	private static function decimal( mixed $value, string $path ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$string = is_int( $value ) ? (string) $value : ( is_string( $value ) ? trim( $value ) : null );
		if ( is_float( $value ) ) {
			$string = rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
		}
		if ( $string === null || preg_match( self::DECIMAL, $string ) !== 1 ) {
			throw new InvalidProduct( __( 'Enter a positive number with at most three decimals.', 'flexacommerce' ), $path );
		}
		return $string;
	}

	/** @param list<string> $allowed */
	private static function one_of( mixed $value, array $allowed, string $path ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new InvalidProduct( __( 'The value is not one of the allowed options.', 'flexacommerce' ), $path );
		}
		return $value;
	}

	/** @return list<mixed> */
	private static function list_of( mixed $value, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new InvalidProduct( __( 'A list is required.', 'flexacommerce' ), $path );
		}
		return $value;
	}

	/** @return array<array-key,mixed> */
	private static function map_of( mixed $value, string $path ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidProduct( __( 'An object is required.', 'flexacommerce' ), $path );
		}
		return $value;
	}

	private static function to_bool( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ '1', 'true', 'yes', 'on' ], true );
		}
		return (bool) $value;
	}
}
