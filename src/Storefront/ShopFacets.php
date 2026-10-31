<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Counts beside the shop filters (ADR-055). A group counts among the
 * products that match every other filter, so a value shows what picking
 * it adds; a group's own values are OR-ed, so they do not narrow it.
 *
 * The product set is the listing the filter form sits on (the shop, a
 * category, a search), or the whole catalog elsewhere. WP_Query writes its
 * SQL and is stopped before it runs; ShopFacetRepository counts inside it.
 * Results stay in the object cache until a product or a term changes.
 */
final class ShopFacets {
	private const GROUP = 'flexacommerce_facets';

	/** @var array<string,string> group => product id SQL */
	private array $sql = [];

	public function __construct( private readonly ShopFacetRepository $repository = new ShopFacetRepository() ) {}

	/**
	 * Products per value of a global attribute.
	 *
	 * @return array<int,int> value term id => products
	 */
	public function attribute( \WP_Term $attribute ): array {
		$sql = $this->products( $attribute->slug );
		return $this->remember( 'a' . $attribute->term_id, $sql, fn (): array => $this->repository->attribute_counts( $sql, PostTypes::ATTRIBUTE, $attribute->term_id ) );
	}

	/**
	 * Products per category, each counting the products of its children.
	 *
	 * @param array<int,int> $parents term id => parent term id, for every category
	 * @return array<int,int> term id => products
	 */
	public function categories( array $parents ): array {
		$sql   = $this->products( 'cat' );
		$pairs = $this->remember( 'c', $sql, fn (): array => $this->repository->term_pairs( $sql, PostTypes::CATEGORY ) );
		$sets  = [];
		foreach ( $pairs as [ $product, $term ] ) {
			// Walk up to the root; the guard stops a broken parent loop.
			for ( $guard = 0; $term > 0 && $guard < 20; ++$guard ) {
				$sets[ $term ][ $product ] = true;
				$term                      = $parents[ $term ] ?? 0;
			}
		}
		return array_map( 'count', $sets );
	}

	/** @return array<string,int> stock status => products */
	public function stock(): array {
		$sql = $this->products( ShopQuery::STOCK_FILTER );
		return $this->remember( 's', $sql, fn (): array => $this->repository->stock_counts( $sql ) );
	}

	/**
	 * Lowest and highest stored price among the products, minor units.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public function prices(): ?array {
		$sql = $this->products( 'price' );
		return $this->remember( 'p', $sql, fn (): ?array => $this->repository->price_bounds( $sql ) );
	}

	/**
	 * The SQL of the listing's product ids with every filter but `$skip`.
	 */
	private function products( string $skip ): string {
		if ( isset( $this->sql[ $skip ] ) ) {
			return $this->sql[ $skip ];
		}
		$filters = ShopQuery::request();
		unset( $filters[ $skip ] );
		$price = $skip === 'price' ? null : ShopQuery::price();

		$query = new \WP_Query();
		$vars  = $this->listing() + [
			'post_type'              => PostTypes::PRODUCT,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'orderby'                => 'none',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];
		$vars  = ShopQuery::hide_in( $vars, isset( $vars['s'] ) ? 'search' : 'catalog' );
		// The filters go in after WP_Query parses its vars, as in the main query.
		$apply = static function ( \WP_Query $q ) use ( $query, $filters, $price ): void {
			if ( $q === $query ) {
				ShopQuery::filter( $q, $filters, $price );
			}
		};
		$stop  = static function ( mixed $posts, \WP_Query $q ) use ( $query ): mixed {
			return $q === $query ? [] : $posts;
		};
		add_action( 'pre_get_posts', $apply, PHP_INT_MAX );
		add_filter( 'posts_pre_query', $stop, PHP_INT_MAX, 2 );
		try {
			$query->query( $vars );
		} finally {
			remove_action( 'pre_get_posts', $apply, PHP_INT_MAX );
			remove_filter( 'posts_pre_query', $stop, PHP_INT_MAX );
		}
		$this->sql[ $skip ] = (string) $query->request;
		return $this->sql[ $skip ];
	}

	/**
	 * The query vars that pick the listing: its category or tag, and the
	 * search words. Elsewhere, nothing: the whole catalog.
	 *
	 * @return array<string,mixed>
	 */
	private function listing(): array {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			return [];
		}
		$vars = [];
		if ( $wp_query->is_search() ) {
			$vars['s'] = (string) $wp_query->get( 's' );
		}
		$term = $wp_query->get_queried_object();
		if ( $term instanceof \WP_Term && in_array( $term->taxonomy, [ PostTypes::CATEGORY, PostTypes::TAG ], true ) ) {
			$vars['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the listing's own term.
				[
					'taxonomy'         => $term->taxonomy,
					'field'            => 'term_id',
					'terms'            => [ $term->term_id ],
					'include_children' => true,
				],
			];
		}
		return $vars;
	}

	/**
	 * @template T
	 * @param callable():T $count
	 * @return T
	 */
	private function remember( string $kind, string $sql, callable $count ): mixed {
		$key = $kind . ':' . md5( $sql ) . ':' . wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'terms' );
		$hit = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found ) {
			return $hit;
		}
		$value = $count();
		wp_cache_set( $key, $value, self::GROUP, DAY_IN_SECONDS );
		return $value;
	}
}
