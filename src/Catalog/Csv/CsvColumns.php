<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog\Csv;

use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The columns of the product CSV (ADR-059). Keys and English headers are the
 * ones WooCommerce uses, so a file exported from either store imports into
 * the other. Numbered columns use the keys `attribute:N:name|value|visible|
 * global|default` and `download:N:id|name|url`; custom fields use `meta:KEY`.
 */
final class CsvColumns {
	/** Columns WooCommerce has and FlexaCommerce does not store. */
	public const UNSUPPORTED = [
		'global_unique_id'  => 'GTIN, UPC, EAN, or ISBN',
		'sold_individually' => 'Sold individually?',
		'purchase_note'     => 'Purchase note',
		'shipping_class_id' => 'Shipping class',
		'grouped_products'  => 'Grouped products',
		'upsell_ids'        => 'Upsells',
		'cross_sell_ids'    => 'Cross-sells',
		'product_url'       => 'External URL',
		'button_text'       => 'Button text',
		'brand_ids'         => 'Brands',
	];

	/** Numbered attribute parts, key => English header suffix. */
	private const ATTRIBUTE_PARTS = [
		'name'    => 'name',
		'value'   => 'value(s)',
		'visible' => 'visible',
		'global'  => 'global',
		'default' => 'default',
	];

	private const DOWNLOAD_PARTS = [
		'id'   => 'ID',
		'name' => 'name',
		'url'  => 'URL',
	];

	/**
	 * The fixed columns in export order, key => English header. Weight and
	 * dimensions name the store unit, as in "Weight (kg)".
	 *
	 * @return array<string,string>
	 */
	public static function fixed(): array {
		$weight    = Settings::string( 'weight_unit' );
		$dimension = Settings::string( 'dimension_unit' );
		return [
			'id'                 => 'ID',
			'type'               => 'Type',
			'sku'                => 'SKU',
			'name'               => 'Name',
			'published'          => 'Published',
			'featured'           => 'Is featured?',
			'catalog_visibility' => 'Visibility in catalog',
			'short_description'  => 'Short description',
			'description'        => 'Description',
			'date_on_sale_from'  => 'Date sale price starts',
			'date_on_sale_to'    => 'Date sale price ends',
			'tax_status'         => 'Tax status',
			'tax_class'          => 'Tax class',
			'stock_status'       => 'In stock?',
			'stock_quantity'     => 'Stock',
			'low_stock_amount'   => 'Low stock amount',
			'backorders'         => 'Backorders allowed?',
			'weight'             => 'Weight (' . $weight . ')',
			'length'             => 'Length (' . $dimension . ')',
			'width'              => 'Width (' . $dimension . ')',
			'height'             => 'Height (' . $dimension . ')',
			'reviews_allowed'    => 'Allow customer reviews?',
			'sale_price'         => 'Sale price',
			'regular_price'      => 'Regular price',
			'category_ids'       => 'Categories',
			'tag_ids'            => 'Tags',
			'images'             => 'Images',
			'download_limit'     => 'Download limit',
			'download_expiry'    => 'Download expiry days',
			'parent_id'          => 'Parent',
			'menu_order'         => 'Position',
		];
	}

	/**
	 * Fields the import screen offers for a column, key => label in the
	 * admin language.
	 *
	 * @return array<string,string>
	 */
	public static function choices(): array {
		return [
			'id'                 => __( 'ID', 'flexacommerce' ),
			'type'               => __( 'Type', 'flexacommerce' ),
			'sku'                => __( 'SKU', 'flexacommerce' ),
			'name'               => __( 'Name', 'flexacommerce' ),
			'published'          => __( 'Published', 'flexacommerce' ),
			'featured'           => __( 'Featured', 'flexacommerce' ),
			'catalog_visibility' => __( 'Catalog visibility', 'flexacommerce' ),
			'short_description'  => __( 'Short description', 'flexacommerce' ),
			'description'        => __( 'Description', 'flexacommerce' ),
			'date_on_sale_from'  => __( 'Sale starts', 'flexacommerce' ),
			'date_on_sale_to'    => __( 'Sale ends', 'flexacommerce' ),
			'tax_status'         => __( 'Tax status', 'flexacommerce' ),
			'tax_class'          => __( 'Tax class', 'flexacommerce' ),
			'stock_status'       => __( 'Stock status', 'flexacommerce' ),
			'stock_quantity'     => __( 'Stock quantity', 'flexacommerce' ),
			'low_stock_amount'   => __( 'Low stock threshold', 'flexacommerce' ),
			'backorders'         => __( 'Backorders', 'flexacommerce' ),
			'weight'             => __( 'Weight', 'flexacommerce' ),
			'length'             => __( 'Length', 'flexacommerce' ),
			'width'              => __( 'Width', 'flexacommerce' ),
			'height'             => __( 'Height', 'flexacommerce' ),
			'reviews_allowed'    => __( 'Allow reviews', 'flexacommerce' ),
			'sale_price'         => __( 'Sale price', 'flexacommerce' ),
			'regular_price'      => __( 'Regular price', 'flexacommerce' ),
			'category_ids'       => __( 'Categories', 'flexacommerce' ),
			'tag_ids'            => __( 'Tags', 'flexacommerce' ),
			'images'             => __( 'Images', 'flexacommerce' ),
			'download_limit'     => __( 'Download limit', 'flexacommerce' ),
			'download_expiry'    => __( 'Download expiry days', 'flexacommerce' ),
			'parent_id'          => __( 'Parent', 'flexacommerce' ),
			'menu_order'         => __( 'Position', 'flexacommerce' ),
		];
	}

	/** The English header of a numbered or custom-field key. */
	public static function header( string $key ): string {
		$fixed = self::fixed();
		if ( isset( $fixed[ $key ] ) ) {
			return $fixed[ $key ];
		}
		$parts = explode( ':', $key, 3 );
		if ( $parts[0] === 'meta' && isset( $parts[1] ) ) {
			return 'Meta: ' . substr( $key, 5 );
		}
		if ( $parts[0] === 'attribute' && isset( $parts[2], self::ATTRIBUTE_PARTS[ $parts[2] ] ) ) {
			return 'Attribute ' . $parts[1] . ' ' . self::ATTRIBUTE_PARTS[ $parts[2] ];
		}
		if ( $parts[0] === 'download' && isset( $parts[2], self::DOWNLOAD_PARTS[ $parts[2] ] ) ) {
			return 'Download ' . $parts[1] . ' ' . self::DOWNLOAD_PARTS[ $parts[2] ];
		}
		return $key;
	}

	/** @return list<string> the keys of attribute `$n`, in export order */
	public static function attribute_keys( int $n ): array {
		return array_map( static fn ( string $part ): string => 'attribute:' . $n . ':' . $part, array_keys( self::ATTRIBUTE_PARTS ) );
	}

	/** @return list<string> */
	public static function download_keys( int $n ): array {
		return array_map( static fn ( string $part ): string => 'download:' . $n . ':' . $part, array_keys( self::DOWNLOAD_PARTS ) );
	}

	/**
	 * The key a header stands for: an English or translated header, a key
	 * itself, or a numbered column. Null when the column is unknown;
	 * `unsupported:KEY` for a WooCommerce column FlexaCommerce has no field for.
	 */
	public static function match( string $header ): ?string {
		$header = trim( CsvValues::strip_bom( $header ) );
		$lower  = mb_strtolower( $header );
		if ( $lower === '' ) {
			return null;
		}
		if ( preg_match( '/^meta:\s*(.+)$/i', $header, $m ) === 1 ) {
			return 'meta:' . trim( $m[1] );
		}
		if ( preg_match( '/^attribute (\d{1,3}) (name|value\(s\)|values?|visible|global|default)$/', $lower, $m ) === 1 ) {
			$part = str_starts_with( $m[2], 'value' ) ? 'value' : $m[2];
			return (int) $m[1] > 0 ? 'attribute:' . (int) $m[1] . ':' . $part : null;
		}
		if ( preg_match( '/^download (\d{1,3}) (id|name|url)$/', $lower, $m ) === 1 ) {
			return (int) $m[1] > 0 ? 'download:' . (int) $m[1] . ':' . $m[2] : null;
		}
		if ( preg_match( '/^(weight|length|width|height)(\s*\([a-z]+\))?$/', $lower, $m ) === 1 ) {
			return $m[1];
		}

		$names = [];
		foreach ( self::fixed() as $key => $english ) {
			$names[ mb_strtolower( $english ) ] = $key;
			$names[ $key ]                      = $key;
		}
		foreach ( self::choices() as $key => $label ) {
			$names[ mb_strtolower( $label ) ] ??= $key;
		}
		foreach ( self::UNSUPPORTED as $key => $english ) {
			$names[ mb_strtolower( $english ) ] = 'unsupported:' . $key;
			$names[ $key ]                      = 'unsupported:' . $key;
		}
		return $names[ $lower ] ?? null;
	}

	/** True for a key an import can map a column to. */
	public static function is_key( string $key ): bool {
		return isset( self::fixed()[ $key ] )
			|| preg_match( '/^attribute:[1-9]\d{0,2}:(name|value|visible|global|default)$/', $key ) === 1
			|| preg_match( '/^download:[1-9]\d{0,2}:(id|name|url)$/', $key ) === 1
			|| preg_match( '/^meta:[^\s].{0,254}$/', $key ) === 1;
	}

	/** How the import screen names a key. */
	public static function label( string $key ): string {
		$choices = self::choices();
		if ( isset( $choices[ $key ] ) ) {
			return $choices[ $key ];
		}
		if ( str_starts_with( $key, 'meta:' ) ) {
			/* translators: %s: custom field key */
			return sprintf( __( 'Custom field: %s', 'flexacommerce' ), substr( $key, 5 ) );
		}
		if ( str_starts_with( $key, 'unsupported:' ) ) {
			return __( 'Not imported (FlexaCommerce has no such field)', 'flexacommerce' );
		}
		return self::header( $key );
	}

	/**
	 * The unit a weight or dimension header names, as in "Weight (lb)", or
	 * null when it names none or one the store does not know.
	 *
	 * @param array<string,float> $factors unit => base units
	 */
	public static function unit( string $header, array $factors ): ?string {
		if ( preg_match( '/\(\s*([a-z]+)\s*\)\s*$/i', $header, $m ) !== 1 ) {
			return null;
		}
		$unit = strtolower( $m[1] );
		$unit = $unit === 'lbs' ? 'lb' : $unit;
		return isset( $factors[ $unit ] ) ? $unit : null;
	}
}
