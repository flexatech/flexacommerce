<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\ProductRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- counts over the SQL of a WP_Query built from the shop's own query vars (ShopFacets); the outer values are prepared. Results are cached in the object cache by ShopFacets.

/**
 * Counts for the shop filters (ADR-055). Every method takes `$ids_sql`, a
 * `SELECT ID` that WP_Query wrote for the products matching the other
 * filters, and counts inside it.
 */
final class ShopFacetRepository {
	/**
	 * Products per value of one global attribute.
	 *
	 * @return array<int,int> value term id => products
	 */
	public function attribute_counts( string $ids_sql, string $taxonomy, int $attribute_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			self::inside(
				$wpdb->prepare(
					"SELECT tt.term_id, COUNT(DISTINCT tr.object_id) AS products FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tt.parent = %d AND tr.object_id IN ( FXC_IDS ) GROUP BY tt.term_id",
					$taxonomy,
					$attribute_id
				),
				$ids_sql
			),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (int) $row['term_id'] ] = (int) $row['products'];
		}
		return $out;
	}

	/**
	 * The terms of `$taxonomy` each matching product has, for counts that
	 * take in child categories (ShopFacets adds each pair to the ancestors).
	 *
	 * @return list<array{0:int,1:int}> product id, term id
	 */
	public function term_pairs( string $ids_sql, string $taxonomy ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			self::inside(
				$wpdb->prepare(
					"SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id IN ( FXC_IDS )",
					$taxonomy
				),
				$ids_sql
			),
			ARRAY_N
		);
		return array_map( static fn ( array $row ): array => [ (int) $row[0], (int) $row[1] ], is_array( $rows ) ? $rows : [] );
	}

	/** @return array<string,int> stock status => products */
	public function stock_counts( string $ids_sql ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			self::inside(
				$wpdb->prepare(
					"SELECT meta_value, COUNT(DISTINCT post_id) AS products FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ( FXC_IDS ) GROUP BY meta_value",
					ProductRepository::META_STOCK_STATUS
				),
				$ids_sql
			),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row['meta_value'] ] = (int) $row['products'];
		}
		return $out;
	}

	/**
	 * The lowest and highest stored price, in minor units, or null when no
	 * matching product has a price.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public function price_bounds( string $ids_sql ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			self::inside(
				$wpdb->prepare(
					"SELECT MIN( CASE WHEN meta_key = %s THEN CAST( meta_value AS SIGNED ) END ) AS low, MAX( CASE WHEN meta_key = %s THEN CAST( meta_value AS SIGNED ) END ) AS high FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) AND post_id IN ( FXC_IDS )",
					ProductRepository::META_PRICE_MIN,
					ProductRepository::META_PRICE_MAX,
					ProductRepository::META_PRICE_MIN,
					ProductRepository::META_PRICE_MAX
				),
				$ids_sql
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || $row['low'] === null || $row['high'] === null ) {
			return null;
		}
		return [ (int) $row['low'], (int) $row['high'] ];
	}

	/**
	 * Puts the product id query into a prepared statement, after prepare()
	 * so its own `%` signs stay as they are. The ids are read from a derived
	 * table: as a plain `IN ( SELECT ... GROUP BY ... )` MariaDB's semijoin
	 * now and then returned rows the subquery leaves out.
	 */
	private static function inside( string $prepared, string $ids_sql ): string {
		return str_replace( '( FXC_IDS )', '( SELECT fxc_ids.ID FROM ( ' . $ids_sql . ' ) AS fxc_ids )', $prepared );
	}
}
