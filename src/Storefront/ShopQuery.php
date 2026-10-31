<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The shop's main query (doc 07 phase 5): catalog visibility, the page
 * size, sorting, category and attribute filters, and exact SKU matches in
 * product search. Plugin::on_pre_get_posts() calls it only for the main
 * query of a shop archive, a product taxonomy or a search.
 *
 * Sorting reads `?orderby=` (a public query var, as in WooCommerce);
 * filters read `?filter_cat=slug,slug`, `?filter_{attribute}=slug,slug`,
 * `?filter_stock_status=instock,onbackorder` and `?min_price=&max_price=`
 * (shown prices in major units, the names WooCommerce uses). Values of one
 * filter match any, different filters must all match.
 */
final class ShopQuery {
	use HasInstance;

	public const SORTS = [ 'menu_order', 'popularity', 'date', 'price', 'price-desc' ];

	public const CATEGORY_FILTER = 'filter_cat';

	public const FILTER_PREFIX = 'filter_';

	/** The filter name of the stock filter, after FILTER_PREFIX. */
	public const STOCK_FILTER = 'stock_status';

	public const MIN_PRICE = 'min_price';

	public const MAX_PRICE = 'max_price';

	/**
	 * Query var naming the context (`catalog` or `search`) whose hidden
	 * products a query leaves out. Set it with hide_in(), which also adds
	 * the `posts_where` filter that reads it; a query with
	 * `suppress_filters` ignores it.
	 */
	public const VISIBILITY_VAR = 'fxc_visibility';

	/** Visibilities left out of the catalog and of search (doc 03 §3.3). */
	private const HIDDEN_IN = [
		'catalog' => [ 'hidden', 'search' ],
		'search'  => [ 'hidden', 'catalog' ],
	];

	/** @var list<int> */
	private array $sku_matches = [];

	public function main( \WP_Query $query ): void {
		if ( $query->is_search() ) {
			$this->search( $query );
			return;
		}
		$query->set( 'posts_per_page', self::per_page() );
		$this->hide( $query, 'catalog' );
		$this->sort( $query, (string) $query->get( 'orderby' ) );
		self::filter( $query, self::request(), self::price() );
	}

	/**
	 * A Query Loop block listing products follows the same visibility rule
	 * as the shop (`query_loop_block_query_vars`).
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function loop_vars( array $vars ): array {
		$types = (array) ( $vars['post_type'] ?? [] );
		if ( ! in_array( PostTypes::PRODUCT, $types, true ) ) {
			return $vars;
		}
		return self::hide_in( $vars, 'catalog' );
	}

	/**
	 * WP_Query vars that leave out the products hidden in `$context`.
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public static function hide_in( array $vars, string $context ): array {
		$vars[ self::VISIBILITY_VAR ] = $context;
		self::watch();
		return $vars;
	}

	/** Adds the `posts_where` filter once, on the first query that needs it (no extra callback on other pages, P1). */
	private static function watch(): void {
		if ( ! has_filter( 'posts_where', [ self::class, 'posts_where' ] ) ) {
			add_filter( 'posts_where', [ self::class, 'posts_where' ], 10, 2 );
		}
	}

	/** Leaves out hidden products in a query that names a visibility context. */
	public static function posts_where( string $where, \WP_Query $query ): string {
		$context = $query->get( self::VISIBILITY_VAR );
		return is_string( $context ) && $context !== '' ? $where . self::visibility_where( $context ) : $where;
	}

	/**
	 * Products per shop page; WooCommerce shows 16 (4 rows of 4).
	 */
	public static function per_page(): int {
		/**
		 * Filters the number of products on a shop page.
		 *
		 * @param int $per_page
		 */
		return max( 1, (int) apply_filters( 'flexacommerce.shop.per_page', Settings::int( 'products_per_page' ) ) );
	}

	/**
	 * The sort the request asked for, or the default one. It reads the
	 * request vars: sort() replaces the query's `orderby` with clauses.
	 */
	public static function current_sort(): string {
		global $wp_query;
		$sort = $wp_query instanceof \WP_Query ? ( $wp_query->query['orderby'] ?? '' ) : '';
		return is_string( $sort ) && in_array( $sort, self::SORTS, true ) ? $sort : 'menu_order';
	}

	/**
	 * The filter values of the request, keyed by filter name (`cat` or an
	 * attribute slug). Each value is a list of term slugs.
	 *
	 * @return array<string,list<string>>
	 */
	public static function request(): array {
		$out = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter links.
		foreach ( wp_unslash( $_GET ) as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, self::FILTER_PREFIX ) ) {
				continue;
			}
			// `filter_x=a,b` from a link, `filter_x[]=a&filter_x[]=b` from the filter form.
			$value = is_array( $value ) ? implode( ',', array_filter( $value, 'is_string' ) ) : $value;
			if ( ! is_string( $value ) ) {
				continue;
			}
			$name  = sanitize_title( substr( $key, strlen( self::FILTER_PREFIX ) ) );
			$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', explode( ',', $value ) ) ) ) );
			if ( $name !== '' && $slugs !== [] ) {
				$out[ $name ] = array_slice( $slugs, 0, 20 );
			}
		}
		return $out;
	}

	private function search( \WP_Query $query ): void {
		$types = $query->get( 'post_type' );
		$types = $types === '' || $types === 'any' ? [ 'any' ] : (array) $types;
		if ( ! in_array( 'any', $types, true ) && ! in_array( PostTypes::PRODUCT, $types, true ) ) {
			return;
		}
		$this->hide( $query, 'search' );
		if ( $types === [ PostTypes::PRODUCT ] ) {
			$query->set( 'posts_per_page', self::per_page() );
			$this->sort( $query, (string) $query->get( 'orderby' ) );
			self::filter( $query, self::request(), self::price() );
		}
		$this->sku( $query );
	}

	/** Products whose visibility leaves them out of this context; posts have no such meta. */
	private function hide( \WP_Query $query, string $context ): void {
		$query->set( self::VISIBILITY_VAR, $context );
		self::watch();
	}

	/**
	 * The WHERE condition that leaves out what is hidden in `catalog` or
	 * `search`; posts and products without the meta pass. A correlated
	 * NOT EXISTS, not a meta_query: the meta_query form (NOT EXISTS OR
	 * NOT IN) joins postmeta twice and needs a GROUP BY, so MySQL sorted
	 * every product before taking one page (150,000 rows read per shop page
	 * at 10,000 products, T3 2026-10-25). This one walks the posts index
	 * and stops at the page size.
	 */
	public static function visibility_where( string $context ): string {
		global $wpdb;
		if ( ! isset( self::HIDDEN_IN[ $context ] ) ) {
			return '';
		}
		[ $first, $second ] = self::HIDDEN_IN[ $context ];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		return (string) $wpdb->prepare( " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS fxc_visibility WHERE fxc_visibility.post_id = {$wpdb->posts}.ID AND fxc_visibility.meta_key = %s AND fxc_visibility.meta_value IN ( %s, %s ) )", ProductRepository::META_VISIBILITY, $first, $second );
	}

	private function sort( \WP_Query $query, string $sort ): void {
		$sort = in_array( $sort, self::SORTS, true ) ? $sort : 'menu_order';
		switch ( $sort ) {
			case 'date':
				$query->set(
					'orderby',
					[
						'date' => 'DESC',
						'ID'   => 'DESC',
					]
				);
				return;
			case 'price':
			case 'price-desc':
				$desc = $sort === 'price-desc';
				$key  = $desc ? ProductRepository::META_PRICE_MAX : ProductRepository::META_PRICE_MIN;
				$meta = $query->get( 'meta_query' );
				$meta = is_array( $meta ) ? $meta : [];
				// Products without a price stay in the list, last when ascending.
				$meta['fxc_price'] = [
					'relation'        => 'OR',
					'fxc_price_value' => [
						'key'     => $key,
						'compare' => 'EXISTS',
						'type'    => 'NUMERIC',
					],
					[
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					],
				];
				$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- `_fxc_price_min/max` exist for sorting (doc 03 §8).
				$query->set(
					'orderby',
					[
						'fxc_price_value' => $desc ? 'DESC' : 'ASC',
						'ID'              => 'DESC',
					]
				);
				return;
			case 'popularity':
				// Placeholder until sales counts are stored: newest first.
				$query->set(
					'orderby',
					[
						'date' => 'DESC',
						'ID'   => 'DESC',
					]
				);
				return;
			default:
				$query->set(
					'orderby',
					[
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					]
				);
		}
	}

	/**
	 * The price bounds of the request as typed: shown prices in major
	 * units, null for a bound left empty. Null when neither is set.
	 *
	 * @return array{0:?float,1:?float}|null
	 */
	public static function price(): ?array {
		$bounds = [];
		foreach ( [ self::MIN_PRICE, self::MAX_PRICE ] as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter links.
			$raw      = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : '';
			$raw      = str_replace( ',', '.', $raw );
			$bounds[] = is_numeric( $raw ) && (float) $raw >= 0 ? (float) $raw : null;
		}
		[ $min, $max ] = $bounds;
		if ( $min !== null && $max !== null && $min > $max ) {
			[ $min, $max ] = [ $max, $min ];
		}
		return $min === null && $max === null ? null : [ $min, $max ];
	}

	/**
	 * Narrows a query to the filters: each filter an AND, values of one
	 * filter OR. `$price` holds shown prices in major units (price()).
	 *
	 * @param array<string,list<string>>      $filters
	 * @param array{0:?float,1:?float}|null $price
	 */
	public static function filter( \WP_Query $query, array $filters, ?array $price ): void {
		$tax = [];
		foreach ( $filters as $name => $slugs ) {
			if ( $name === self::STOCK_FILTER ) {
				continue;
			}
			if ( $name === 'cat' ) {
				$tax[] = [
					'taxonomy'         => PostTypes::CATEGORY,
					'field'            => 'slug',
					'terms'            => $slugs,
					'include_children' => true,
				];
				continue;
			}
			$ids = self::attribute_values( $name, $slugs );
			// An unknown attribute or value matches nothing, like an empty filter result in WooCommerce.
			$tax[] = [
				'taxonomy'         => PostTypes::ATTRIBUTE,
				'field'            => 'term_id',
				'terms'            => $ids !== [] ? $ids : [ 0 ],
				'include_children' => false,
			];
		}
		if ( $tax !== [] ) {
			$own             = $query->get( 'tax_query' );
			$tax             = array_merge( is_array( $own ) ? $own : [], $tax );
			$tax['relation'] = 'AND';
			$query->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the filter itself.
		}

		$meta = [];
		if ( isset( $filters[ self::STOCK_FILTER ] ) ) {
			$meta['fxc_stock_filter'] = [
				'key'     => ProductRepository::META_STOCK_STATUS,
				'value'   => $filters[ self::STOCK_FILTER ],
				'compare' => 'IN',
			];
		}
		[ $low, $high ] = self::stored_range( $price );
		// A product matches when its price range meets the asked one.
		if ( $low !== null ) {
			$meta['fxc_price_low'] = [
				'key'     => ProductRepository::META_PRICE_MAX,
				'value'   => $low,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			];
		}
		if ( $high !== null ) {
			$meta['fxc_price_high'] = [
				'key'     => ProductRepository::META_PRICE_MIN,
				'value'   => $high,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			];
		}
		if ( $meta !== [] ) {
			$own  = $query->get( 'meta_query' );
			$meta = array_merge( is_array( $own ) ? $own : [], $meta );
			$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- `_fxc_price_min/max` and `_fxc_stock_status` exist for the shop (doc 03 §8).
		}
	}

	/**
	 * Shown price bounds (major units) as stored minor amounts: the stored
	 * price leaves out tax when the shop shows it with tax, and the other
	 * way round, so the bounds are scaled by the standard rate at the store
	 * address, as WooCommerce's price filter does. The low bound rounds
	 * down and the high one up, so a product shown at exactly a bound stays.
	 *
	 * @param array{0:?float,1:?float}|null $price
	 * @return array{0:?int,1:?int}
	 */
	public static function stored_range( ?array $price ): array {
		if ( $price === null ) {
			return [ null, null ];
		}
		$pricing = Plugin::instance()->cart()->pricing();
		$scale   = 10 ** $pricing->exponent();
		$ratio   = $pricing->catalog_ratio();
		return [
			$price[0] === null ? null : (int) floor( round( $price[0] * $scale / $ratio, 6 ) ),
			$price[1] === null ? null : (int) ceil( round( $price[1] * $scale / $ratio, 6 ) ),
		];
	}

	/**
	 * Term ids of the values `$slugs` under the global attribute `$attribute`.
	 *
	 * @param list<string> $slugs
	 * @return list<int>
	 */
	public static function attribute_values( string $attribute, array $slugs ): array {
		$parent = get_term_by( 'slug', $attribute, PostTypes::ATTRIBUTE );
		if ( ! $parent instanceof \WP_Term || $parent->parent !== 0 ) {
			return [];
		}
		$ids = get_terms(
			[
				'taxonomy'   => PostTypes::ATTRIBUTE,
				'parent'     => $parent->term_id,
				'slug'       => $slugs,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/** An exact SKU, of the product or of one of its options, also finds the product (doc 01). */
	private function sku( \WP_Query $query ): void {
		$term = trim( (string) $query->get( 's' ) );
		if ( $term === '' || strlen( $term ) > 100 ) {
			return;
		}
		$variant = Plugin::instance()->catalog()->variants()->find_by_sku( $term );
		if ( $variant === null || ! $variant->enabled ) {
			return;
		}
		$this->sku_matches = [ $variant->product_id ];
		add_filter( 'posts_search', [ $this, 'posts_search' ], 10, 2 );
	}

	/** Adds the SKU matches to the search clause of that one query. */
	public function posts_search( string $search, \WP_Query $query ): string {
		remove_filter( 'posts_search', [ $this, 'posts_search' ], 10 );
		global $wpdb;
		$ids               = implode( ',', array_map( 'intval', $this->sku_matches ) );
		$this->sku_matches = [];
		if ( $ids === '' || ! $query->is_main_query() ) {
			return $search;
		}
		$rest = (string) preg_replace( '/^\s*AND\s*/', '', $search );
		return $rest === ''
			? " AND {$wpdb->posts}.ID IN ({$ids})"
			: " AND ({$wpdb->posts}.ID IN ({$ids}) OR ({$rest}))";
	}
}
