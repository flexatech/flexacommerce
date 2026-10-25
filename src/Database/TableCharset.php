<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Answers wpdb's charset questions (table and column) for FlexaCommerce tables, so
 * `$wpdb->insert()`, `update()` and `replace()` skip the
 * `SHOW FULL COLUMNS` query they run once per table and request
 * (P6q, doc 10 §4.1). The tables are created with the connection charset
 * (Schema::definitions()), so that is the right answer. wpdb then skips its
 * column length check on these tables; strict mode rejects an overlong
 * value instead. Without collation support the tables use the server
 * default, which only wpdb's own lookup knows.
 */
final class TableCharset {
	public static function register(): void {
		if ( ! has_filter( 'pre_get_table_charset', [ self::class, 'filter' ] ) ) {
			add_filter( 'pre_get_table_charset', [ self::class, 'filter' ], 10, 2 );
			add_filter( 'pre_get_col_charset', [ self::class, 'filter' ], 10, 2 );
		}
	}

	public static function filter( mixed $charset, mixed $table ): mixed {
		global $wpdb;
		if ( $charset !== null || ! is_string( $table ) || ! $wpdb instanceof \wpdb || $wpdb->charset === '' || ! $wpdb->has_cap( 'collation' ) ) {
			return $charset;
		}
		return str_starts_with( strtolower( $table ), strtolower( $wpdb->prefix . 'fxc_' ) ) ? $wpdb->charset : $charset;
	}
}
