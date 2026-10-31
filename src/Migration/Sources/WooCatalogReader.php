<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;

defined( 'ABSPATH' ) || exit;

/** Attributes, terms and products from WooCommerce CRUD (doc 05 §5.1, §5.2). */
final class WooCatalogReader {
	/** Post meta the product mapping covers; everything else goes to `extra.meta`. */
	private const MAPPED_META = [
		'_edit_lock',
		'_edit_last',
		'_thumbnail_id',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
		'_product_attributes',
		'_product_image_gallery',
		'_price',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_sku',
		'_manage_stock',
		'_stock',
		'_stock_status',
		'_backorders',
		'_low_stock_amount',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_tax_status',
		'_tax_class',
		'_virtual',
		'_downloadable',
		'_downloadable_files',
		'_download_limit',
		'_download_expiry',
		'_variation_description',
		'_wc_average_rating',
		'_wc_rating_count',
		'_wc_review_count',
		'_product_version',
		'_transient_wc_count_comments',
		'total_sales',
		'_fxc_source_id',
	];

	/** Term meta the term mapping covers. */
	private const MAPPED_TERM_META = [ 'order', 'thumbnail_id', 'product_count_product_cat', 'product_count_product_tag' ];

	private ?string $currency = null;

	public function attribute( int $id ): ?Record {
		$attribute = function_exists( 'wc_get_attribute' ) ? wc_get_attribute( $id ) : null;
		if ( $attribute === null ) {
			return null;
		}
		return new Record(
			Record::ATTRIBUTE,
			Ref::make( 'wc', Record::ATTRIBUTE, $id ),
			[
				'name'     => (string) $attribute->name,
				'slug'     => self::strip_pa( (string) $attribute->slug ),
				'orderby'  => (string) $attribute->order_by,
				'archives' => (bool) $attribute->has_archives,
			]
		);
	}

	public function term( int $id ): ?Record {
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$taxonomy = $term->taxonomy;
		$depends  = [];
		$kind     = match ( true ) {
			$taxonomy === 'product_cat' => 'category',
			$taxonomy === 'product_tag' => 'tag',
			str_starts_with( $taxonomy, 'pa_' ) => 'attribute_value',
			default => null,
		};
		if ( $kind === null ) {
			return null;
		}
		$attribute = null;
		if ( $kind === 'attribute_value' ) {
			$attribute_id = (int) wc_attribute_taxonomy_id_by_name( $taxonomy );
			if ( $attribute_id === 0 ) {
				throw new Rejected( 'unknown_attribute', sprintf( 'Term %d belongs to "%s", which is not a registered attribute.', $id, $taxonomy ) );
			}
			$attribute = Ref::make( 'wc', Record::ATTRIBUTE, $attribute_id );
			$depends[] = $attribute;
		}
		$parent = null;
		if ( $kind === 'category' && (int) $term->parent > 0 ) {
			$parent    = Ref::make( 'wc', Record::TERM, (int) $term->parent, $taxonomy );
			$depends[] = $parent;
		}
		$order     = get_term_meta( $id, 'order', true );
		$thumbnail = (int) get_term_meta( $id, 'thumbnail_id', true );
		return new Record(
			Record::TERM,
			Ref::make( 'wc', Record::TERM, $id, $taxonomy ),
			[
				'kind'        => $kind,
				'attribute'   => $attribute,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $parent,
				'order'       => is_numeric( $order ) ? (int) $order : null,
				'image'       => $thumbnail > 0 ? Ref::make( 'wp', 'attachment', $thumbnail ) : null,
				'extra'       => self::extra( (array) get_term_meta( $id ), self::MAPPED_TERM_META ),
			],
			$depends
		);
	}

	public function product( int $id ): ?Record {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product || $product->is_type( 'variation' ) || in_array( $product->get_status( 'edit' ), [ 'trash', 'auto-draft' ], true ) ) {
			return null;
		}
		$type = $product->get_type();
		if ( ! in_array( $type, [ 'simple', 'variable' ], true ) ) {
			throw new Rejected(
				'unsupported_type',
				sprintf( 'Product %d is a %s product; only simple and variable products are imported.', $id, $type ),
				[
					'type'      => $type,
					'edit_link' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
				]
			);
		}
		$post    = get_post( $id );
		$depends = [];

		$attributes = [];
		$keys       = [];
		foreach ( array_values( $product->get_attributes( 'edit' ) ) as $i => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$entry = $this->product_attribute( $attribute, $i, $depends );
			if ( $entry === null ) {
				continue;
			}
			$keys[ $entry['wc_key'] ] = $entry;
			unset( $entry['wc_key'] );
			$attributes[] = $entry;
		}

		$categories = $this->term_refs( $product->get_category_ids( 'edit' ), 'product_cat' );
		$tags       = $this->term_refs( $product->get_tag_ids( 'edit' ), 'product_tag' );
		array_push( $depends, ...$categories, ...$tags );

		$modified = Dates::from_db( $post instanceof \WP_Post ? $post->post_modified_gmt : null );
		$variants = [];
		if ( $product instanceof \WC_Product_Variable ) {
			$children = [];
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof \WC_Product_Variation ) {
					$children[] = $child;
				}
			}
			usort( $children, static fn ( \WC_Product_Variation $a, \WC_Product_Variation $b ): int => [ $a->get_menu_order( 'edit' ), $a->get_id() ] <=> [ $b->get_menu_order( 'edit' ), $b->get_id() ] );
			foreach ( $children as $child ) {
				$variants[] = $this->variation( $child, $keys );
				$changed    = Dates::from_wc( $child->get_date_modified( 'edit' ) );
				if ( $changed !== null && ( $modified === null || $changed > $modified ) ) {
					$modified = $changed;
				}
			}
		}

		$image   = (int) $product->get_image_id( 'edit' );
		$gallery = array_map( static fn ( $attachment ): string => Ref::make( 'wp', 'attachment', (int) $attachment ), $product->get_gallery_image_ids( 'edit' ) );
		$base    = $this->row( $product, false );
		unset( $base['image'], $base['description'], $base['enabled'], $base['menu_order'] );
		return new Record(
			Record::PRODUCT,
			Ref::make( 'wc', Record::PRODUCT, $id ),
			[
				'kind'           => $type,
				'status'         => $product->get_status( 'edit' ),
				'slug'           => $product->get_slug( 'edit' ),
				'title'          => $product->get_name( 'edit' ),
				'content'        => $product->get_description( 'edit' ),
				'excerpt'        => $product->get_short_description( 'edit' ),
				'author'         => $post instanceof \WP_Post ? (int) $post->post_author : 0,
				'comment_status' => $post instanceof \WP_Post ? $post->comment_status : 'open',
				'created_at'     => Dates::from_db( $post instanceof \WP_Post ? $post->post_date_gmt : null ),
				'modified_at'    => $modified,
				'menu_order'     => $product->get_menu_order( 'edit' ),
				'visibility'     => $product->get_catalog_visibility( 'edit' ),
				'featured'       => $product->get_featured( 'edit' ),
				'virtual'        => $product->get_virtual( 'edit' ),
				'downloadable'   => $product->get_downloadable( 'edit' ),
				'image'          => $image > 0 ? Ref::make( 'wp', 'attachment', $image ) : null,
				'gallery'        => $gallery,
				'categories'     => $categories,
				'tags'           => $tags,
				'attributes'     => $attributes,
				'base'           => $base,
				'variants'       => $variants,
				'extra'          => self::extra( self::meta_of( $product ), self::MAPPED_META ),
			],
			array_values( array_unique( $depends ) )
		);
	}

	/** Store currency, read once per batch. */
	public function currency(): string {
		return $this->currency ??= function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
	}

	/** @return array{amount:string,currency:string}|null */
	public function money( mixed $value ): ?array {
		$amount = Decimal::clean( $value );
		return $amount === null ? null : [
			'amount'   => $amount,
			'currency' => $this->currency(),
		];
	}

	public static function weight_factor(): string {
		$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		return Decimal::GRAMS[ $unit === 'lbs' ? 'lb' : $unit ] ?? '1000';
	}

	public static function dimension_factor(): string {
		return Decimal::MILLIMETRES[ (string) get_option( 'woocommerce_dimension_unit', 'cm' ) ] ?? '10';
	}

	public static function strip_pa( string $taxonomy ): string {
		return str_starts_with( $taxonomy, 'pa_' ) ? substr( $taxonomy, 3 ) : $taxonomy;
	}

	/**
	 * Unknown meta as JSON values. Objects cannot be expressed and are listed
	 * in `dropped` so the target can warn about them.
	 *
	 * @param array<array-key,mixed> $meta key => list of values, as get_*_meta() returns them
	 * @param list<string>           $mapped
	 * @return array{source:string,meta:array<string,mixed>,dropped:list<string>}
	 */
	public static function extra( array $meta, array $mapped ): array {
		$out     = [];
		$dropped = [];
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( in_array( $key, $mapped, true ) || str_starts_with( $key, 'attribute_' ) || str_starts_with( $key, '_fxc_' ) ) {
				continue;
			}
			$list = [];
			foreach ( is_array( $values ) ? $values : [ $values ] as $value ) {
				if ( self::has_object( $value ) ) {
					$dropped[] = $key;
					continue 2;
				}
				$list[] = $value;
			}
			$out[ $key ] = count( $list ) === 1 ? $list[0] : $list;
		}
		ksort( $out, SORT_STRING );
		return [
			'source'  => 'woocommerce',
			'meta'    => $out,
			'dropped' => $dropped,
		];
	}

	/**
	 * @param list<string> $depends
	 * @return array<string,mixed>|null
	 */
	private function product_attribute( \WC_Product_Attribute $attribute, int $index, array &$depends ): ?array {
		$name = $attribute->get_name();
		if ( $attribute->is_taxonomy() && $attribute->get_id() > 0 ) {
			$key       = Ref::make( 'wc', Record::ATTRIBUTE, $attribute->get_id() );
			$depends[] = $key;
			$values    = [];
			foreach ( $attribute->get_options() as $term_id ) {
				$values[] = Ref::make( 'wc', Record::TERM, (int) $term_id, $name );
			}
			array_push( $depends, ...$values );
			return [
				'key'       => $key,
				'name'      => wc_attribute_label( $name ),
				'global'    => true,
				'values'    => $values,
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
				'position'  => $index,
				'wc_key'    => sanitize_title( $name ),
			];
		}
		$values = array_values( array_map( 'strval', $attribute->get_options() ) );
		if ( trim( $name ) === '' ) {
			return null;
		}
		return [
			'key'       => 'local:' . sanitize_title( $name ),
			'name'      => $name,
			'global'    => false,
			'values'    => $values,
			'visible'   => $attribute->get_visible(),
			'variation' => $attribute->get_variation(),
			'position'  => $index,
			'wc_key'    => sanitize_title( $name ),
		];
	}

	/**
	 * @param array<string,array<string,mixed>> $keys parent attributes by WooCommerce key
	 * @return array<string,mixed>
	 */
	private function variation( \WC_Product_Variation $variation, array $keys ): array {
		$combination = [];
		foreach ( $keys as $wc_key => $attribute ) {
			if ( empty( $attribute['variation'] ) ) {
				continue;
			}
			$raw   = (string) ( $variation->get_attributes( 'edit' )[ $wc_key ] ?? '' );
			$value = '*';
			if ( $raw !== '' && $attribute['global'] ) {
				$taxonomy = wc_attribute_taxonomy_name_by_id( Ref::int_id( (string) $attribute['key'] ) );
				$term     = get_term_by( 'slug', $raw, $taxonomy );
				$value    = $term instanceof \WP_Term ? Ref::make( 'wc', Record::TERM, (int) $term->term_id, $taxonomy ) : $raw;
			} elseif ( $raw !== '' ) {
				$value = $raw;
				foreach ( (array) $attribute['values'] as $option ) {
					if ( sanitize_title( (string) $option ) === sanitize_title( $raw ) ) {
						$value = (string) $option;
						break;
					}
				}
			}
			$combination[ (string) $attribute['key'] ] = $value;
		}
		ksort( $combination, SORT_STRING );
		return [ 'id' => Ref::make( 'wc', 'variant', $variation->get_id() ) ] + $this->row( $variation, true ) + [
			'combination' => $combination,
			'extra'       => self::extra( self::meta_of( $variation ), self::MAPPED_META ),
		];
	}

	/** @return array<string,mixed> */
	private function row( \WC_Product $product, bool $variation ): array {
		$own_stock = $product->get_manage_stock( 'edit' ) === true;
		$quantity  = $product->get_stock_quantity( 'edit' );
		if ( $quantity !== null && (float) $quantity === floor( (float) $quantity ) ) {
			$quantity = (int) $quantity;
		}
		$threshold = $product->get_low_stock_amount( 'edit' );
		$image     = (int) $product->get_image_id( 'edit' );
		$weight    = self::weight_factor();
		$dimension = self::dimension_factor();
		return [
			'enabled'             => $variation ? $product->get_status( 'edit' ) === 'publish' : true,
			'menu_order'          => $product->get_menu_order( 'edit' ),
			'sku'                 => $product->get_sku( 'edit' ) !== '' ? $product->get_sku( 'edit' ) : null,
			'regular_price'       => $this->money( $product->get_regular_price( 'edit' ) ),
			'sale_price'          => $this->money( $product->get_sale_price( 'edit' ) ),
			'sale_from'           => Dates::from_wc( $product->get_date_on_sale_from( 'edit' ) ),
			// WooCommerce ends a sale on the last second it applies; FlexaCommerce on the first second it does not.
			'sale_to'             => Dates::shift( Dates::from_wc( $product->get_date_on_sale_to( 'edit' ) ), 1 ),
			'manage_stock'        => $own_stock,
			'stock_from_base'     => $variation && ! $own_stock && $product->get_stock_managed_by_id() !== $product->get_id(),
			'stock_quantity'      => $own_stock ? $quantity : null,
			'backorders'          => $product->get_backorders( 'edit' ),
			'stock_status'        => $product->get_stock_status( 'edit' ),
			'low_stock_threshold' => $threshold === '' ? null : (int) $threshold,
			'weight_g'            => Decimal::to_base( Decimal::clean( $product->get_weight( 'edit' ) ), $weight ),
			'length_mm'           => Decimal::to_base( Decimal::clean( $product->get_length( 'edit' ) ), $dimension ),
			'width_mm'            => Decimal::to_base( Decimal::clean( $product->get_width( 'edit' ) ), $dimension ),
			'height_mm'           => Decimal::to_base( Decimal::clean( $product->get_height( 'edit' ) ), $dimension ),
			'tax_class'           => $product->get_tax_class( 'edit' ),
			'tax_status'          => $product->get_tax_status( 'edit' ),
			'image'               => $image > 0 ? Ref::make( 'wp', 'attachment', $image ) : null,
			'description'         => $variation ? $product->get_description( 'edit' ) : null,
			'virtual'             => $product->get_virtual( 'edit' ),
			'downloadable'        => $product->get_downloadable( 'edit' ),
			'downloads'           => self::downloads( $product ),
		];
	}

	/**
	 * The files a product sells (ADR-058); WooCommerce uses -1 for no limit.
	 *
	 * @return array{files:list<array{id:string,name:string,file:string}>,limit:?int,expiry_days:?int}
	 */
	private static function downloads( \WC_Product $product ): array {
		$files = [];
		foreach ( $product->get_downloads( 'edit' ) as $id => $download ) {
			$files[] = [
				'id'   => (string) $id,
				'name' => (string) $download->get_name(),
				'file' => (string) $download->get_file(),
			];
		}
		$limit  = (int) $product->get_download_limit( 'edit' );
		$expiry = (int) $product->get_download_expiry( 'edit' );
		return [
			'files'       => $files,
			'limit'       => $limit > 0 ? $limit : null,
			'expiry_days' => $expiry > 0 ? $expiry : null,
		];
	}

	/**
	 * @param array<int|string> $ids
	 * @return list<string>
	 */
	private function term_refs( array $ids, string $taxonomy ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$out[] = Ref::make( 'wc', Record::TERM, (int) $id, $taxonomy );
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Meta WooCommerce does not handle itself, grouped by key.
	 *
	 * @return array<string,list<mixed>>
	 */
	public static function meta_of( \WC_Data $object ): array {
		$out = [];
		foreach ( $object->get_meta_data() as $meta ) {
			$data                           = $meta->get_data();
			$out[ (string) $data['key'] ][] = $data['value'];
		}
		return $out;
	}

	private static function has_object( mixed $value ): bool {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_object( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
