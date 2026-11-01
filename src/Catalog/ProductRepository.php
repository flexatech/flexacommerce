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
	public const META_CHILDREN     = '_fxc_children';
	public const META_GROUPED_IN   = '_fxc_grouped_in';
	public const META_PRODUCT_URL  = '_fxc_product_url';
	public const META_BUTTON_TEXT  = '_fxc_button_text';
	public const META_BUNDLE       = '_fxc_bundle';
	public const META_BUNDLED_IN   = '_fxc_bundled_in';

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
			children: $type === ProductTypes::GROUPED ? self::id_list( (string) get_post_meta( $product_id, self::META_CHILDREN, true ) ) : [],
			product_url: $type === ProductTypes::EXTERNAL ? (string) get_post_meta( $product_id, self::META_PRODUCT_URL, true ) : '',
			button_text: $type === ProductTypes::EXTERNAL ? (string) get_post_meta( $product_id, self::META_BUTTON_TEXT, true ) : '',
			bundle: $type === ProductTypes::BUNDLE ? Bundle::from_meta( (string) get_post_meta( $product_id, self::META_BUNDLE, true ) ) : null,
		);
	}

	/**
	 * The grouped products that list this one (ADR-062), kept on the child
	 * so a price or stock change finds its groups without a query.
	 *
	 * @return list<int>
	 */
	public function groups_of( int $product_id ): array {
		return self::id_list( (string) get_post_meta( $product_id, self::META_GROUPED_IN, true ) );
	}

	/**
	 * The bundles that hold a row of this product (ADR-071), kept on the
	 * part so a price or stock change finds its bundles without a query.
	 *
	 * @return list<int>
	 */
	public function bundles_of( int $product_id ): array {
		return self::id_list( (string) get_post_meta( $product_id, self::META_BUNDLED_IN, true ) );
	}

	/**
	 * The parts of a bundle read against the catalog, in its order; null
	 * when one of them cannot be sold now (missing, not published, disabled
	 * or without a price), so the bundle cannot be sold either. Empty for a
	 * bundle that holds nothing.
	 *
	 * @return list<BundlePart>|null
	 */
	public function parts_of( Product $bundle ): ?array {
		$items = $bundle->bundle->items ?? [];
		if ( $items === [] ) {
			return [];
		}
		$ids = array_values( array_unique( array_map( static fn ( BundleItem $item ): int => $item->product_id, $items ) ) );
		_prime_post_caches( $ids, false, true );
		$this->prime( $ids );
		$parts = [];
		foreach ( $items as $item ) {
			$product = $this->find( $item->product_id );
			$variant = $product?->sellable( $item->variant_id );
			if ( $product === null || $variant === null || $product->status !== 'publish' || ( $product->type !== ProductTypes::SIMPLE && $product->type !== ProductTypes::VARIABLE )
				|| ! $variant->enabled || $variant->regular_price === null || $product->is_gift_card() ) {
				return null;
			}
			$parts[] = new BundlePart( $product, $variant, $item->quantity );
		}
		return $parts;
	}

	/**
	 * The published products a grouped product lists, in its order; their
	 * posts and rows are loaded together.
	 *
	 * @return list<Product>
	 */
	public function children_of( Product $group ): array {
		if ( $group->children === [] ) {
			return [];
		}
		_prime_post_caches( $group->children, false, true );
		$this->prime( $group->children );
		$out = [];
		foreach ( $group->children as $id ) {
			$child = $this->find( $id );
			if ( $child !== null && $child->status === 'publish' && ! $child->is_grouped() ) {
				$out[] = $child;
			}
		}
		return $out;
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
		$this->save_type_meta( $product );

		$value_terms = [];
		foreach ( $product->attributes as $attribute ) {
			if ( $attribute->is_global() ) {
				$value_terms = array_merge( $value_terms, $attribute->value_term_ids );
			}
		}
		wp_set_object_terms( $id, array_values( array_unique( $value_terms ) ), PostTypes::ATTRIBUTE );
	}

	/** The children of a grouped product, the link of an external one; other types drop both. */
	private function save_type_meta( Product $product ): void {
		$id     = $product->id;
		$before = self::id_list( (string) get_post_meta( $id, self::META_CHILDREN, true ) );
		$after  = $product->is_grouped() ? $product->children : [];
		if ( $after === [] ) {
			delete_post_meta( $id, self::META_CHILDREN );
		} else {
			update_post_meta( $id, self::META_CHILDREN, implode( ',', $after ) );
		}
		foreach ( array_diff( $before, $after ) as $child ) {
			$this->link_group( $child, $id, false );
		}
		foreach ( array_diff( $after, $before ) as $child ) {
			$this->link_group( $child, $id, true );
		}

		$before = self::id_list( implode( ',', Bundle::from_meta( (string) get_post_meta( $id, self::META_BUNDLE, true ) )->product_ids() ) );
		$after  = $product->is_bundle() && $product->bundle !== null ? $product->bundle->product_ids() : [];
		if ( $product->is_bundle() && $product->bundle !== null ) {
			update_post_meta( $id, self::META_BUNDLE, wp_slash( $product->bundle->to_meta() ) );
		} else {
			delete_post_meta( $id, self::META_BUNDLE );
		}
		foreach ( array_diff( $before, $after ) as $part ) {
			$this->link( $part, $id, false, self::META_BUNDLED_IN );
		}
		foreach ( array_diff( $after, $before ) as $part ) {
			$this->link( $part, $id, true, self::META_BUNDLED_IN );
		}

		foreach ( [
			self::META_PRODUCT_URL => $product->product_url,
			self::META_BUTTON_TEXT => $product->button_text,
		] as $key => $value ) {
			if ( $product->is_external() && $value !== '' ) {
				update_post_meta( $id, $key, wp_slash( $value ) );
			} else {
				delete_post_meta( $id, $key );
			}
		}
	}

	/** Adds or removes `$group` in the child's list of groups. */
	public function link_group( int $child, int $group, bool $add ): void {
		$this->link( $child, $group, $add, self::META_GROUPED_IN );
	}

	/** Adds or removes `$parent` in the reverse list `$key` kept on `$child`. */
	private function link( int $child, int $parent, bool $add, string $key ): void {
		$parents = self::id_list( (string) get_post_meta( $child, $key, true ) );
		$next    = $add ? array_values( array_unique( [ ...$parents, $parent ] ) ) : array_values( array_diff( $parents, [ $parent ] ) );
		if ( $next === $parents ) {
			return;
		}
		if ( $next === [] ) {
			delete_post_meta( $child, $key );
		} else {
			update_post_meta( $child, $key, implode( ',', $next ) );
		}
	}

	/**
	 * Takes a deleted product out of the groups that listed it, and out of
	 * the lists of groups of its own children.
	 */
	public function unlink( int $product_id, string $now ): void {
		foreach ( $this->groups_of( $product_id ) as $group ) {
			$children = self::id_list( (string) get_post_meta( $group, self::META_CHILDREN, true ) );
			$left     = array_values( array_diff( $children, [ $product_id ] ) );
			if ( $left === [] ) {
				delete_post_meta( $group, self::META_CHILDREN );
			} else {
				update_post_meta( $group, self::META_CHILDREN, implode( ',', $left ) );
			}
			$this->sync_denormalized( $group, $now );
		}
		foreach ( self::id_list( (string) get_post_meta( $product_id, self::META_CHILDREN, true ) ) as $child ) {
			$this->link_group( $child, $product_id, false );
		}
		// A bundle keeps the item of a deleted part, so it cannot be sold until the merchant edits it (ADR-071).
		foreach ( $this->bundles_of( $product_id ) as $bundle ) {
			$this->sync_denormalized( $bundle, $now );
		}
		foreach ( Bundle::from_meta( (string) get_post_meta( $product_id, self::META_BUNDLE, true ) )->product_ids() as $part ) {
			$this->link( $part, $product_id, false, self::META_BUNDLED_IN );
		}
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
		if ( $product->is_grouped() ) {
			$this->sync_group( $product );
			return;
		}
		$bundle_change = null;
		if ( $product->is_bundle() ) {
			[ $product, $bundle_change ] = $this->sync_bundle( $product, $now );
		}

		$in_stock_prices = [];
		$all_prices      = [];
		$statuses        = [];
		$next_change     = null;

		foreach ( $product->sellable_rows() as $row ) {
			if ( ! $row->enabled ) {
				continue;
			}
			// An external product is sold elsewhere: its own stock does not count.
			$status     = $product->is_external() ? Variant::INSTOCK : $product->stock_owner( $row )->computed_stock_status();
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

		if ( $product->is_bundle() && $product->bundle?->is_computed() === true ) {
			$next_change = $bundle_change;
		}
		if ( $next_change === null ) {
			delete_post_meta( $product_id, self::META_NEXT_CHANGE );
		} else {
			update_post_meta( $product_id, self::META_NEXT_CHANGE, $next_change );
		}

		foreach ( [ ...$this->groups_of( $product_id ), ...$this->bundles_of( $product_id ) ] as $group ) {
			$this->sync_denormalized( $group, $now );
		}
	}

	/**
	 * Writes on a bundle's base row what its parts make it (ADR-071): the
	 * stock status, and with a computed price the regular price (the sum of
	 * the parts' regular prices) and the sale price (their active prices,
	 * less the discount). Returns the product read again and the next time
	 * a part's price changes.
	 *
	 * @return array{0:Product,1:?string}
	 */
	private function sync_bundle( Product $product, string $now ): array {
		$bundle = $product->bundle ?? new Bundle();
		$parts  = $this->parts_of( $product );
		$status = $parts === null || $parts === [] ? Variant::OUTOFSTOCK : self::bundle_status( $parts );

		$changes = [ 'stock_status' => $status ];
		$next    = null;
		if ( $bundle->is_computed() ) {
			$regular = null;
			$active  = null;
			if ( $parts !== null && $parts !== [] ) {
				$regular = 0;
				$active  = 0;
				foreach ( $parts as $part ) {
					$regular += (int) $part->variant->regular_price * $part->quantity;
					$active  += (int) $part->variant->active_price( $now ) * $part->quantity;
					$change   = $part->variant->next_price_change( $now );
					if ( $change !== null && ( $next === null || $change < $next ) ) {
						$next = $change;
					}
				}
				$active = $bundle->price_of( $active );
			}
			$changes += [
				'regular_price' => $regular,
				'sale_price'    => $active !== null && $active < (int) $regular ? $active : null,
				'sale_from'     => null,
				'sale_to'       => null,
			];
		}

		$base = $product->base;
		$row  = $base->with( $changes );
		if ( $base->id > 0 && $row->to_row() !== $base->to_row() ) {
			$this->variants->update( $row );
			$this->variants->flush( $product->id );
			$product = $this->find( $product->id ) ?? $product;
		}
		return [ $product, $next ];
	}

	/**
	 * Out of stock when a part cannot give the units one bundle takes, on
	 * backorder when one gives them on backorder.
	 *
	 * @param list<BundlePart> $parts
	 */
	private static function bundle_status( array $parts ): string {
		$status = Variant::INSTOCK;
		foreach ( $parts as $part ) {
			$owner = $part->owner();
			$own   = $owner->computed_stock_status();
			if ( $owner->manage_stock && (int) $owner->stock_quantity < $part->quantity ) {
				$own = $owner->backorders === 'no' ? Variant::OUTOFSTOCK : Variant::ONBACKORDER;
			}
			if ( $own === Variant::OUTOFSTOCK ) {
				return Variant::OUTOFSTOCK;
			}
			if ( $own === Variant::ONBACKORDER ) {
				$status = Variant::ONBACKORDER;
			}
		}
		return $status;
	}

	/**
	 * A grouped product's range is that of its published children, and it
	 * is in stock while one of them is; it has no sale of its own.
	 */
	private function sync_group( Product $product ): void {
		$children = $product->children;
		if ( $children !== [] ) {
			_prime_post_caches( $children, false, true );
		}
		$low      = [];
		$high     = [];
		$statuses = [];
		foreach ( $children as $child ) {
			if ( get_post_status( $child ) !== 'publish' || get_post_type( $child ) !== PostTypes::PRODUCT ) {
				continue;
			}
			$statuses[] = (string) get_post_meta( $child, self::META_STOCK_STATUS, true );
			$min        = get_post_meta( $child, self::META_PRICE_MIN, true );
			$max        = get_post_meta( $child, self::META_PRICE_MAX, true );
			if ( is_numeric( $min ) && is_numeric( $max ) ) {
				$low[]  = (int) $min;
				$high[] = (int) $max;
			}
		}
		if ( $low === [] ) {
			delete_post_meta( $product->id, self::META_PRICE_MIN );
			delete_post_meta( $product->id, self::META_PRICE_MAX );
		} else {
			update_post_meta( $product->id, self::META_PRICE_MIN, (string) min( $low ) );
			update_post_meta( $product->id, self::META_PRICE_MAX, (string) max( $high ) );
		}
		$aggregate = match ( true ) {
			in_array( Variant::INSTOCK, $statuses, true )     => Variant::INSTOCK,
			in_array( Variant::ONBACKORDER, $statuses, true ) => Variant::ONBACKORDER,
			default                                         => Variant::OUTOFSTOCK,
		};
		update_post_meta( $product->id, self::META_STOCK_STATUS, $aggregate );
		delete_post_meta( $product->id, self::META_NEXT_CHANGE );
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
