<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Product-level data: the post, its `_fxc_*` meta and its rows in
 * fxc_variants (through VariantRepository). Uses only the WordPress post
 * and meta APIs.
 */
final class ProductRepository {
	public const META_TYPE         = '_fxc_type';
	public const META_ATTRIBUTES   = '_fxc_attributes';
	public const META_GALLERY      = '_fxc_gallery_ids';
	public const META_VIDEO        = '_fxc_gallery_video';
	public const META_VISIBILITY   = '_fxc_visibility';
	public const META_FEATURED     = '_fxc_featured';
	public const META_PRICE_MIN    = '_fxc_price_min';
	public const META_PRICE_MAX    = '_fxc_price_max';
	public const META_STOCK_STATUS = '_fxc_stock_status';
	public const META_NEXT_CHANGE  = '_fxc_next_price_change';

	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
	private const JSON_MAX   = 65536;

	public function __construct( private readonly VariantRepository $variants ) {}

	/** Post + meta + variants; null when the post is missing or not a product. */
	public function find( int $product_id ): ?Product {
		$post = get_post( $product_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::PRODUCT ) {
			return null;
		}

		$rows       = $this->variants->for_product( $product_id );
		$base       = null;
		$variations = [];
		foreach ( $rows as $row ) {
			if ( $row->is_base && $base === null ) {
				$base = $row;
			} elseif ( ! $row->is_base ) {
				$variations[] = $row;
			}
		}

		$type = (string) get_post_meta( $product_id, self::META_TYPE, true );
		$type = ProductTypes::is_valid( $type ) ? $type : ProductTypes::SIMPLE;

		$visibility = (string) get_post_meta( $product_id, self::META_VISIBILITY, true );

		return new Product(
			id: $product_id,
			type: $type,
			status: $post->post_status,
			title: $post->post_title,
			attributes: $this->read_attributes( $product_id ),
			base: $base ?? Variant::base_for( $product_id ),
			variations: $variations,
			gallery_ids: self::id_list( (string) get_post_meta( $product_id, self::META_GALLERY, true ) ),
			visibility: in_array( $visibility, Product::VISIBILITIES, true ) ? $visibility : 'visible',
			featured: get_post_meta( $product_id, self::META_FEATURED, true ) === '1',
			video: ProductVideo::from_meta( (string) get_post_meta( $product_id, self::META_VIDEO, true ) ),
		);
	}

	/**
	 * Loads the variants of every product in one query (doc 02 §7.1).
	 *
	 * @param list<int> $product_ids
	 */
	public function prime( array $product_ids ): void {
		$this->variants->for_products( $product_ids );
	}

	/** Product-level meta only; rows are written by ProductService. */
	public function save_meta( Product $product ): void {
		$id = $product->id;
		update_post_meta( $id, self::META_TYPE, $product->type );

		$attributes = array_map( static fn ( AttributeDefinition $a ): array => $a->to_array(), $product->attributes );
		$json       = json_encode( $attributes, self::JSON_FLAGS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- THROW_ON_ERROR, wp_json_encode() swallows failures.
		if ( strlen( $json ) > self::JSON_MAX ) {
			throw new \LengthException( 'Attribute definitions exceed 64 KB.' );
		}
		// update_post_meta() unslashes its value; slash first so JSON escapes survive.
		update_post_meta( $id, self::META_ATTRIBUTES, wp_slash( $json ) );

		if ( $product->gallery_ids === [] ) {
			delete_post_meta( $id, self::META_GALLERY );
		} else {
			update_post_meta( $id, self::META_GALLERY, implode( ',', $product->gallery_ids ) );
		}
		if ( $product->video === null ) {
			delete_post_meta( $id, self::META_VIDEO );
		} else {
			update_post_meta( $id, self::META_VIDEO, wp_slash( $product->video->to_meta() ) );
		}
		update_post_meta( $id, self::META_VISIBILITY, $product->visibility );
		update_post_meta( $id, self::META_FEATURED, $product->featured ? '1' : '0' );

		$value_terms = [];
		foreach ( $product->attributes as $attribute ) {
			if ( $attribute->is_global() ) {
				$value_terms = array_merge( $value_terms, $attribute->value_term_ids );
			}
		}
		wp_set_object_terms( $id, array_values( array_unique( $value_terms ) ), PostTypes::ATTRIBUTE );
	}

	/**
	 * Recomputes `_fxc_price_min`/`_fxc_price_max` (active prices of enabled,
	 * priced, sellable rows), `_fxc_stock_status` and `_fxc_next_price_change`
	 * (doc 03 §3.3, §3.7).
	 */
	public function sync_denormalized( int $product_id, string $now ): void {
		$this->variants->flush( $product_id );
		$product = $this->find( $product_id );
		if ( $product === null ) {
			return;
		}

		$in_stock_prices = [];
		$all_prices      = [];
		$statuses        = [];
		$next_change     = null;

		foreach ( $product->sellable_rows() as $row ) {
			if ( ! $row->enabled ) {
				continue;
			}
			$status     = $product->stock_owner( $row )->computed_stock_status();
			$statuses[] = $status;

			$price = $row->active_price( $now );
			if ( $price === null ) {
				continue;
			}
			$all_prices[] = $price;
			if ( $status !== Variant::OUTOFSTOCK ) {
				$in_stock_prices[] = $price;
			}
			$change = $row->next_price_change( $now );
			if ( $change !== null && ( $next_change === null || $change < $next_change ) ) {
				$next_change = $change;
			}
		}

		$prices = $in_stock_prices !== [] ? $in_stock_prices : $all_prices;
		if ( $prices === [] ) {
			delete_post_meta( $product_id, self::META_PRICE_MIN );
			delete_post_meta( $product_id, self::META_PRICE_MAX );
		} else {
			update_post_meta( $product_id, self::META_PRICE_MIN, (string) min( $prices ) );
			update_post_meta( $product_id, self::META_PRICE_MAX, (string) max( $prices ) );
		}

		$aggregate = match ( true ) {
			in_array( Variant::INSTOCK, $statuses, true )     => Variant::INSTOCK,
			in_array( Variant::ONBACKORDER, $statuses, true ) => Variant::ONBACKORDER,
			default                                         => Variant::OUTOFSTOCK,
		};
		update_post_meta( $product_id, self::META_STOCK_STATUS, $aggregate );

		if ( $next_change === null ) {
			delete_post_meta( $product_id, self::META_NEXT_CHANGE );
		} else {
			update_post_meta( $product_id, self::META_NEXT_CHANGE, $next_change );
		}
	}

	/**
	 * Products whose sale schedule changed their price by `$now`.
	 *
	 * @return list<int>
	 */
	public function due_price_changes( string $now, int $limit = 200 ): array {
		$ids = get_posts(
			[
				'post_type'        => PostTypes::PRODUCT,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => $limit,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'meta_value',
				'order'            => 'ASC',
				'meta_key'         => self::META_NEXT_CHANGE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded hourly job.
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- as above.
					[
						'key'     => self::META_NEXT_CHANGE,
						'value'   => $now,
						'compare' => '<=',
						'type'    => 'DATETIME',
					],
				],
			]
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/** @return list<AttributeDefinition> */
	private function read_attributes( int $product_id ): array {
		$raw = get_post_meta( $product_id, self::META_ATTRIBUTES, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		try {
			$data = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return [];
		}
		$out = [];
		foreach ( is_array( $data ) ? $data : [] as $entry ) {
			$definition = is_array( $entry ) ? AttributeDefinition::from_array( $entry ) : null;
			if ( $definition !== null ) {
				$out[] = $definition;
			}
		}
		usort( $out, static fn ( AttributeDefinition $a, AttributeDefinition $b ): int => $a->position <=> $b->position );
		return $out;
	}

	/** @return list<int> */
	private static function id_list( string $csv ): array {
		$ids = array_map( 'intval', explode( ',', $csv ) );
		return array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
	}
}
