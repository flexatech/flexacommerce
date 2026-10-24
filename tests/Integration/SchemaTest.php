<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration;

use Flexa\Commerce\Database\Schema;

final class SchemaTest extends \WP_UnitTestCase {
	public function test_every_table_exists_and_uses_innodb(): void {
		self::assertSame( [], Schema::missing_tables() );
		self::assertSame( [], Schema::non_innodb_tables() );
		self::assertCount( 14, Schema::tables() );
		self::assertSame( (int) Schema::DB_VERSION, Schema::installed_version() );
	}

	public function test_every_declared_index_exists(): void {
		$missing = [];
		foreach ( Schema::definitions() as $short => $sql ) {
			preg_match_all( '/^\s*(?:UNIQUE\s+)?KEY\s+([a-z_]+)\s*\(/mi', $sql, $m );
			foreach ( $m[1] as $index ) {
				if ( ! Schema::index_exists( Schema::table( (string) $short ), $index ) ) {
					$missing[] = $short . '.' . $index;
				}
			}
			if ( ! Schema::index_exists( Schema::table( (string) $short ), 'PRIMARY' ) ) {
				$missing[] = $short . '.PRIMARY';
			}
		}
		self::assertSame( [], $missing );
	}

	public function test_rerunning_migrate_is_a_no_op(): void {
		self::assertSame( [], Schema::migrate() );
	}

	public function test_order_number_sequence_is_seeded(): void {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE name = %s', Schema::table( 'sequences' ), 'order_number' ) );
		self::assertNotNull( $value );
	}

	public function test_only_the_settings_option_is_autoloaded(): void {
		global $wpdb;
		$autoloaded = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload IN ('yes','on','auto-on','auto')",
				$wpdb->esc_like( 'flexacommerce_' ) . '%'
			)
		);
		self::assertSame( [ 'flexacommerce_settings' ], $autoloaded );
	}
}
