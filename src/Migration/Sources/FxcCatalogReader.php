<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Catalog\AttributeDefinition;
use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductService;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Exceptions\ProductNotFound;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Targets\FxcCatalogWriter;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Attributes, terms and products from FlexaCommerce, in the shapes
 * WooCatalogReader emits (doc 05 §5.1, §5.2). Weights and sizes are already
 * grams and millimetres.
 */
final class FxcCatalogReader {
	public function __construct(
		private readonly AttributeRepository $attributes,
		private readonly ProductService $products,
	) {}

	public function attribute( int $id ): ?Record {
		$term = $this->attributes->attribute( $id );
		if ( $term === null ) {
			return null;
		}
		$orderby = (string) get_term_meta( $id, FxcCatalogWriter::ORDERBY_META, true );
		return new Record(
			Record::ATTRIBUTE,
			Ref::make( 'fxc', Record::ATTRIBUTE, $id ),
			[
				'name'     => $term->name,
				'slug'     => $term->slug,
				'orderby'  => $orderby !== '' ? $orderby : 'menu_order',
				'archives' => get_term_meta( $id, FxcCatalogWriter::ARCHIVES_META, true ) === '1',
			]
		);
	}

	public function term( int $id ): ?Record {
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$taxonomy = $term->taxonomy;
		$kind     = match ( true ) {
			$taxonomy === PostTypes::CATEGORY => 'category',
			$taxonomy === PostTypes::TAG => 'tag',
			$taxonomy === PostTypes::ATTRIBUTE && (int) $term->parent > 0 => 'attribute_value',
			default => null,
		};
		if ( $kind === null ) {
			return null;
		}
		$depends   = [];
		$attribute = null;
		if ( $kind === 'attribute_value' ) {
			$attribute = Ref::make( 'fxc', Record::ATTRIBUTE, (int) $term->parent );
			$depends[] = $attribute;
		}
		$parent = null;
		if ( $kind === 'category' && (int) $term->parent > 0 ) {
			$parent    = Ref::make( 'fxc', Record::TERM, (int) $term->parent, $taxonomy );
			$depends[] = $parent;
		}
		$order     = get_term_meta( $id, AttributeRepository::POSITION_META, true );
		$thumbnail = (int) get_term_meta( $id, 'thumbnail_id', true );
		$import    = self::import_of( get_term_meta( $id, FxcCatalogWriter::IMPORT_META, true ) );
		return new Record(
			Record::TERM,
			Ref::make( 'fxc', Record::TERM, $id, $taxonomy ),
			[
				'kind'        => $kind,
				'attribute'   => $attribute,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $parent,
				'order'       => is_numeric( $order ) ? (int) $order : null,
				'image'       => $thumbnail > 0 ? Ref::make( 'wp', 'attachment', $thumbnail ) : null,
				'extra'       => self::extra( $import ),
			],
			$depends
		);
	}

	public function product( int $id ): ?Record {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::PRODUCT || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return null;
		}
		try {
			$product = $this->products->get( $id );
		} catch ( ProductNotFound ) {
			return null;
		}
		$depends    = [];
		$attributes = [];
		$axes       = [];
		foreach ( $product->attributes as $i => $definition ) {
			$entry                    = $this->product_attribute( $definition, $i, $depends );
			$axes[ $definition->key ] = [ $entry['key'], $definition ];
			$attributes[]             = $entry;
		}

		$categories = self::term_refs( $id, PostTypes::CATEGORY );
		$tags       = self::term_refs( $id, PostTypes::TAG );
		array_push( $depends, ...$categories, ...$tags );

		$import   = self::import_of( get_post_meta( $id, FxcCatalogWriter::IMPORT_META, true ) );
		$own_meta = is_array( $import['variants'] ?? null ) ? $import['variants'] : [];
		$modified = Dates::from_db( $post->post_modified_gmt );
		$variants = [];
		if ( $product->is_variable() ) {
			$rows = $product->variations;
			usort( $rows, static fn ( Variant $a, Variant $b ): int => [ $a->menu_order, $a->id ] <=> [ $b->menu_order, $b->id ] );
			foreach ( $rows as $variation ) {
				$meta       = $own_meta[ (string) $variation->id ] ?? [];
				$variants[] = [ 'id' => Ref::make( 'fxc', 'variant', $variation->id ) ] + $this->row( $variation, false ) + [
					'combination' => $this->combination( $variation->combination, $axes ),
					'extra'       => self::extra( [ 'meta' => is_array( $meta ) ? $meta : [] ] + $import ),
				];
				$changed    = Dates::from_db( $variation->updated_at );
				if ( $changed !== null && ( $modified === null || $changed > $modified ) ) {
					$modified = $changed;
				}
			}
		}
		$changed = Dates::from_db( $product->base->updated_at );
		if ( $changed !== null && ( $modified === null || $changed > $modified ) ) {
			$modified = $changed;
		}

		$image = (int) get_post_thumbnail_id( $id );
		$base  = $this->row( $product->base, true );
		unset( $base['image'], $base['description'], $base['enabled'], $base['menu_order'] );
		return new Record(
			Record::PRODUCT,
			Ref::make( 'fxc', Record::PRODUCT, $id ),
			[
				'kind'           => $product->type,
				'status'         => $post->post_status,
				'slug'           => $post->post_name,
				'title'          => $post->post_title,
				'content'        => $post->post_content,
				'excerpt'        => $post->post_excerpt,
				'author'         => (int) $post->post_author,
				'comment_status' => $post->comment_status,
				'created_at'     => Dates::from_db( $post->post_date_gmt ),
				'modified_at'    => $modified,
				'menu_order'     => (int) $post->menu_order,
				'visibility'     => $product->visibility,
				'featured'       => $product->featured,
				'virtual'        => false,
				'downloadable'   => false,
				'image'          => $image > 0 ? Ref::make( 'wp', 'attachment', $image ) : null,
				'gallery'        => array_map( static fn ( int $attachment ): string => Ref::make( 'wp', 'attachment', $attachment ), $product->gallery_ids ),
				'categories'     => $categories,
				'tags'           => $tags,
				'attributes'     => $attributes,
				'base'           => $base,
				'variants'       => $variants,
				'extra'          => self::extra( [ 'meta' => is_array( $import['meta'] ?? null ) ? $import['meta'] : [] ] + $import ),
			],
			array_values( array_unique( $depends ) )
		);
	}

	/**
	 * @param list<string> $depends
	 * @return array{key:string,name:string,global:bool,values:list<string>,visible:bool,variation:bool,position:int}
	 */
	private function product_attribute( AttributeDefinition $definition, int $index, array &$depends ): array {
		if ( $definition->is_global() ) {
			$key       = Ref::make( 'fxc', Record::ATTRIBUTE, $definition->attribute_term_id );
			$depends[] = $key;
			$values    = [];
			foreach ( $definition->value_term_ids as $term_id ) {
				$values[] = Ref::make( 'fxc', Record::TERM, $term_id, PostTypes::ATTRIBUTE );
			}
			array_push( $depends, ...$values );
			return [
				'key'       => $key,
				'name'      => $this->attributes->name( $definition->attribute_term_id ),
				'global'    => true,
				'values'    => $values,
				'visible'   => $definition->visible,
				'variation' => $definition->variation,
				'position'  => $index,
			];
		}
		return [
			'key'       => 'local:' . sanitize_title( $definition->name ),
			'name'      => $definition->name,
			'global'    => false,
			'values'    => $definition->values,
			'visible'   => $definition->visible,
			'variation' => $definition->variation,
			'position'  => $index,
		];
	}

	/**
	 * @param array<string,array{0:string,1:AttributeDefinition}> $axes FlexaCommerce key => [canonical key, definition]
	 * @return array<string,string>
	 */
	private function combination( Combination $combination, array $axes ): array {
		$out = [];
		foreach ( $combination->to_array() as $key => $value ) {
			if ( ! isset( $axes[ $key ] ) ) {
				continue;
			}
			[ $canonical, $definition ] = $axes[ $key ];
			if ( $value === Combination::WILDCARD ) {
				$out[ $canonical ] = Combination::WILDCARD;
				continue;
			}
			$out[ $canonical ] = $definition->is_global()
				? Ref::make( 'fxc', Record::TERM, (int) $value, PostTypes::ATTRIBUTE )
				: ( $definition->local_label( $value ) ?? $value );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** @return array<string,mixed> */
	private function row( Variant $row, bool $base ): array {
		$currency = Settings::string( 'currency' );
		$exponent = Currency::exponent( $currency );
		$money    = static fn ( ?int $amount ): ?array => $amount === null ? null : [
			'amount'   => Decimal::trim( Decimal::from_minor( $amount, $currency, $exponent ) ),
			'currency' => $currency,
		];
		$size     = static fn ( ?string $value ): ?string => $value === null ? null : Decimal::to_base( Decimal::clean( $value ), '1' );
		return [
			'enabled'             => $row->enabled,
			'menu_order'          => $row->menu_order,
			'sku'                 => $row->sku,
			'regular_price'       => $money( $row->regular_price ),
			'sale_price'          => $money( $row->sale_price ),
			'sale_from'           => Dates::from_db( $row->sale_from ),
			'sale_to'             => Dates::from_db( $row->sale_to ),
			'manage_stock'        => $row->manage_stock,
			'stock_from_base'     => ! $base && $row->stock_from_base,
			'stock_quantity'      => $row->manage_stock ? $row->stock_quantity : null,
			'backorders'          => $row->backorders,
			'stock_status'        => $row->stock_status,
			'low_stock_threshold' => $row->low_stock_threshold,
			'weight_g'            => $size( $row->weight ),
			'length_mm'           => $size( $row->length ),
			'width_mm'            => $size( $row->width ),
			'height_mm'           => $size( $row->height ),
			'tax_class'           => $row->tax_class,
			'tax_status'          => $row->tax_status,
			'image'               => $row->image_id !== null && $row->image_id > 0 ? Ref::make( 'wp', 'attachment', $row->image_id ) : null,
			'description'         => $base ? null : (string) $row->description,
		];
	}

	/** @return list<string> */
	private static function term_refs( int $post_id, string $taxonomy ): array {
		$ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		$out = [];
		foreach ( is_array( $ids ) ? $ids : [] as $term_id ) {
			$out[] = Ref::make( 'fxc', Record::TERM, (int) $term_id, $taxonomy );
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The `_fxc_import` meta an earlier import left behind.
	 *
	 * @return array<string,mixed>
	 */
	private static function import_of( mixed $raw ): array {
		$decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<string,mixed> $import
	 * @return array{source:string,meta:array<string,mixed>,dropped:list<string>}
	 */
	private static function extra( array $import ): array {
		$meta = is_array( $import['meta'] ?? null ) ? $import['meta'] : [];
		ksort( $meta, SORT_STRING );
		return [
			'source'  => (string) ( $import['source'] ?? 'flexacommerce' ),
			'meta'    => $meta,
			'dropped' => [],
		];
	}
}
