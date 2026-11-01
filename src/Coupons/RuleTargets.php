<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\ProductTypes;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Catalog\VariantRepository;

defined( 'ABSPATH' ) || exit;

/**
 * What the product and category pickers of the Coupons screen show: search
 * results by name or SKU, and the names of the ids a coupon already holds.
 * A row is a product (post id), a variation (variant id) or a category
 * (term id); the type keeps the two id spaces apart.
 *
 * @phpstan-type Target array{type:string,id:int,label:string,sku:string}
 */
final class RuleTargets {
	public const PRODUCT  = 'product';
	public const VARIANT  = 'variant';
	public const CATEGORY = 'category';
	public const PART     = 'part';

	/** Products and categories one search returns. */
	public const LIMIT = 20;

	/** Rows one search returns, variations included. */
	public const MAX_ROWS = 60;

	private const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

	public function __construct(
		private readonly ProductRepository $products,
		private readonly VariantRepository $variants,
	) {}

	/**
	 * Products whose title or SKU matches, each followed by its variations.
	 * A product found by the SKU of one variation lists that variation only.
	 *
	 * @return list<Target>
	 */
	public function search_products( string $search ): array {
		$out = [];
		foreach ( $this->found( $search ) as $product_id => $which ) {
			$product = $this->products->find( $product_id );
			// Grouped and external products have nothing to sell here (ADR-062).
			if ( $product === null || $product->status === 'trash' || ! $product->is_sold() ) {
				continue;
			}
			$out[] = self::product_row( $product );
			foreach ( $product->is_variable() ? $product->variations : [] as $variant ) {
				if ( $which === true || in_array( $variant->id, $which, true ) ) {
					$out[] = $this->variant_row( $product, $variant );
				}
			}
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return array_slice( $out, 0, self::MAX_ROWS );
	}

	/**
	 * What a bundle can hold (ADR-071): simple products and the variations
	 * of variable ones, as rows of the variant table. A simple product's row
	 * is its base row. Gift cards and variations with an “Any” value are
	 * left out.
	 *
	 * @return list<array{type:string,id:int,label:string,sku:string,product_id:int}>
	 */
	public function search_parts( string $search ): array {
		$out = [];
		foreach ( $this->found( $search ) as $product_id => $which ) {
			$product = $this->products->find( $product_id );
			if ( $product === null || $product->status === 'trash' || ! in_array( $product->type, [ ProductTypes::SIMPLE, ProductTypes::VARIABLE ], true ) ) {
				continue;
			}
			$rows = $product->is_variable() ? $product->variations : [ $product->base ];
			foreach ( $rows as $variant ) {
				if ( $variant->gift_card || $variant->combination->wildcard_count() > 0 || ( $which !== true && ! in_array( $variant->id, $which, true ) ) ) {
					continue;
				}
				$row   = $variant->is_base ? [ 'type' => self::VARIANT ] + self::product_row( $product ) : $this->variant_row( $product, $variant );
				$out[] = [
					'id'         => $variant->id,
					'sku'        => (string) ( $variant->sku ?? $product->base->sku ),
					'product_id' => $product->id,
				] + $row;
			}
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return array_slice( $out, 0, self::MAX_ROWS );
	}

	/**
	 * The rows of a bundle's items, by variant id (ADR-071); rows that no
	 * longer exist are left out.
	 *
	 * @param list<int> $variant_ids
	 * @return array<int,array{type:string,id:int,label:string,sku:string,product_id:int}>
	 */
	public function lookup_parts( array $variant_ids ): array {
		$owners = $this->variants->owners( $variant_ids );
		$this->products->prime( array_values( array_unique( $owners ) ) );
		$out = [];
		foreach ( $variant_ids as $id ) {
			$product = isset( $owners[ $id ] ) ? $this->products->find( $owners[ $id ] ) : null;
			foreach ( $product?->rows() ?? [] as $variant ) {
				if ( $variant->id === $id && $product !== null ) {
					$row        = $variant->is_base ? [ 'type' => self::VARIANT ] + self::product_row( $product ) : $this->variant_row( $product, $variant );
					$out[ $id ] = [
						'id'         => $id,
						'sku'        => (string) ( $variant->sku ?? $product->base->sku ),
						'product_id' => $product->id,
					] + $row;
				}
			}
		}
		return $out;
	}

	/**
	 * Products whose title or SKU matches: product id => every row (true)
	 * or the variant ids whose SKU matched.
	 *
	 * @return array<int,true|list<int>>
	 */
	private function found( string $search ): array {
		$search = trim( $search );
		$query  = new \WP_Query(
			[
				'post_type'              => PostTypes::PRODUCT,
				'post_status'            => self::STATUSES,
				'posts_per_page'         => self::LIMIT,
				'orderby'                => $search === '' ? 'date' : 'relevance',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				's'                      => $search,
			]
		);
		/** @var array<int,true|list<int>> $wanted product id => every variation (true) or these variant ids */
		$wanted = [];
		foreach ( $query->posts as $id ) {
			$wanted[ (int) ( $id instanceof \WP_Post ? $id->ID : $id ) ] = true;
		}
		foreach ( $this->variants->search_sku( $search, self::LIMIT ) as $row ) {
			if ( ( $wanted[ $row['product_id'] ] ?? null ) === true ) {
				continue;
			}
			if ( $row['is_base'] ) {
				$wanted[ $row['product_id'] ] = true;
			} else {
				$wanted[ $row['product_id'] ]   = (array) ( $wanted[ $row['product_id'] ] ?? [] );
				$wanted[ $row['product_id'] ][] = $row['id'];
			}
		}

		$this->products->prime( array_keys( $wanted ) );
		return $wanted;
	}

	/** @return list<Target> */
	public function search_categories( string $search ): array {
		$terms = get_terms(
			[
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => false,
				'number'     => self::LIMIT,
				'search'     => trim( $search ),
				'orderby'    => 'name',
			]
		);
		$out   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$out[] = self::category_row( $term );
		}
		return $out;
	}

	/**
	 * The rows of ids a coupon holds, in the order given. Ids that no longer
	 * exist are left out, so the screen can show them as removed.
	 *
	 * @param list<int> $product_ids
	 * @param list<int> $variant_ids
	 * @param list<int> $category_ids
	 * @return list<Target>
	 */
	public function lookup( array $product_ids, array $variant_ids, array $category_ids ): array {
		$owners = $this->variants->owners( $variant_ids );
		$this->products->prime( array_values( array_unique( [ ...$product_ids, ...array_values( $owners ) ] ) ) );

		$out = [];
		foreach ( $product_ids as $id ) {
			$product = $this->products->find( $id );
			if ( $product !== null ) {
				$out[] = self::product_row( $product );
			}
		}
		foreach ( $variant_ids as $id ) {
			$product = isset( $owners[ $id ] ) ? $this->products->find( $owners[ $id ] ) : null;
			if ( $product === null ) {
				continue;
			}
			foreach ( $product->variations as $variant ) {
				if ( $variant->id === $id ) {
					$out[] = $this->variant_row( $product, $variant );
				}
			}
		}
		if ( $category_ids !== [] ) {
			_prime_term_caches( $category_ids, false );
			foreach ( $category_ids as $id ) {
				$term = get_term( $id, PostTypes::CATEGORY );
				if ( $term instanceof \WP_Term ) {
					$out[] = self::category_row( $term );
				}
			}
		}
		return $out;
	}

	/** @return Target */
	private static function product_row( Product $product ): array {
		return [
			'type'  => self::PRODUCT,
			'id'    => $product->id,
			'label' => self::title( $product ),
			'sku'   => (string) $product->base->sku,
		];
	}

	/** @return Target */
	private function variant_row( Product $product, Variant $variant ): array {
		$values = [];
		foreach ( $product->variation_attributes() as $definition ) {
			$value = $variant->combination->get( $definition->key );
			if ( $value === null ) {
				continue;
			}
			if ( $value === Combination::WILDCARD ) {
				$name = $definition->is_global() ? self::term_name( $definition->attribute_term_id ) : $definition->name;
				/* translators: %s: attribute name, e.g. Size */
				$values[] = sprintf( __( 'Any %s', 'flexacommerce' ), $name );
			} elseif ( $definition->is_global() ) {
				$values[] = self::term_name( (int) $value );
			} else {
				$values[] = $definition->local_label( $value ) ?? $value;
			}
		}
		$values = array_filter( $values, static fn ( string $v ): bool => $v !== '' );
		return [
			'type'  => self::VARIANT,
			'id'    => $variant->id,
			'label' => self::title( $product ) . ' – ' . ( $values === [] ? '#' . $variant->id : implode( ', ', $values ) ),
			'sku'   => (string) $variant->sku,
		];
	}

	/** @return Target */
	private static function category_row( \WP_Term $term ): array {
		$names = [];
		foreach ( array_reverse( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) ) as $parent ) {
			$names[] = self::term_name( (int) $parent );
		}
		$names[] = $term->name;
		return [
			'type'  => self::CATEGORY,
			'id'    => $term->term_id,
			'label' => html_entity_decode( implode( ' › ', array_filter( $names ) ), ENT_QUOTES ),
			'sku'   => '',
		];
	}

	private static function title( Product $product ): string {
		$title = html_entity_decode( $product->title, ENT_QUOTES );
		/* translators: %d: product id */
		return $title !== '' ? $title : sprintf( __( 'Product #%d', 'flexacommerce' ), $product->id );
	}

	private static function term_name( int $term_id ): string {
		$term = get_term( $term_id );
		return $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES ) : '';
	}
}
