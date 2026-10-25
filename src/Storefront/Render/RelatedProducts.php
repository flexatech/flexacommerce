<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Storefront\ShopQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Products that share a category or a tag with the current one, newest
 * first, as cards with image, title, price and the list add-to-cart button.
 * The id list is cached until a post or a term changes.
 */
final class RelatedProducts {
	public const MAX = 12;

	/**
	 * @param \Closure(int):?Product $find  the storefront's published-product lookup
	 * @param \Closure(Product):string $card price and button of one card
	 */
	public function __construct(
		private readonly ProductRepository $products,
		private readonly \Closure $find,
		private readonly \Closure $card,
	) {}

	public function html( Product $product, int $count ): string {
		$count = max( 1, min( self::MAX, $count ) );
		return Component::wrap(
			'related_products',
			function () use ( $product, $count ): string {
				$ids = $this->ids( $product->id, $count );
				if ( $ids === [] ) {
					return '';
				}
				_prime_post_caches( $ids, false, true );
				$this->products->prime( $ids );
				$thumbs = array_filter( array_map( static fn ( int $id ): int => (int) get_post_thumbnail_id( $id ), $ids ) );
				if ( $thumbs !== [] ) {
					_prime_post_caches( array_values( $thumbs ), false, true );
				}

				$cards = '';
				foreach ( $ids as $id ) {
					$related = ( $this->find )( $id );
					if ( $related === null ) {
						continue;
					}
					$link   = (string) get_permalink( $id );
					$image  = get_the_post_thumbnail( $id, 'medium', [ 'loading' => 'lazy' ] );
					$cards .= sprintf(
						'<li class="fxc-related__item">%1$s<h3 class="fxc-related__name"><a href="%2$s">%3$s</a></h3>%4$s</li>',
						$image !== '' ? '<a class="fxc-related__image" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">' . $image . '</a>' : '',
						esc_url( $link ),
						esc_html( $related->title ),
						( $this->card )( $related )
					);
				}
				if ( $cards === '' ) {
					return '';
				}
				$heading = 'fxc-related-' . $product->id;
				return sprintf(
					'<section class="fxc-related" aria-labelledby="%1$s"><h2 id="%1$s" class="fxc-related__title">%2$s</h2><ul class="fxc-related__list">%3$s</ul></section>',
					esc_attr( $heading ),
					esc_html__( 'Related products', 'flexacommerce' ),
					$cards
				);
			},
			[ 'product_id' => $product->id ]
		);
	}

	/** @return list<int> */
	public function ids( int $product_id, int $count ): array {
		$key    = sprintf( 'related:%d:%d:%s:%s', $product_id, $count, wp_cache_get_last_changed( 'posts' ), wp_cache_get_last_changed( 'terms' ) );
		$cached = wp_cache_get( $key, 'flexacommerce' );
		if ( is_array( $cached ) ) {
			return array_values( array_map( 'intval', $cached ) );
		}

		$tax = [ 'relation' => 'OR' ];
		foreach ( [ PostTypes::CATEGORY, PostTypes::TAG ] as $taxonomy ) {
			// The main query has primed the term cache; wp_get_post_terms() would query.
			$terms = get_the_terms( $product_id, $taxonomy );
			if ( is_array( $terms ) && $terms !== [] ) {
				$tax[] = [
					'taxonomy'         => $taxonomy,
					'field'            => 'term_id',
					'terms'            => array_map( static fn ( \WP_Term $term ): int => $term->term_id, $terms ),
					'include_children' => false,
				];
			}
		}
		$ids = [];
		if ( count( $tax ) > 1 ) {
			$query = new \WP_Query(
				[
					'post_type'              => PostTypes::PRODUCT,
					'post_status'            => 'publish',
					'posts_per_page'         => $count,
					'post__not_in'           => [ $product_id ], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- one id, the current product.
					'has_password'           => false,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
					'orderby'                => [
						'date' => 'DESC',
						'ID'   => 'DESC',
					],
					'tax_query'              => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the relation itself.
					'meta_query'             => [ ShopQuery::visibility_clause( 'catalog' ) ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- visibility lives in postmeta (doc 03 §8).
				]
			);
			$ids   = array_values( array_map( 'intval', $query->posts ) );
		}
		wp_cache_set( $key, $ids, 'flexacommerce', HOUR_IN_SECONDS );
		return $ids;
	}
}
