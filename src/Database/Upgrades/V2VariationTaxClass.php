<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database\Upgrades;

use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * DB_VERSION 2 (doc 03 §4.1): variation rows take the product's tax class.
 * No store charged tax before version 2, so the stored '' was never a choice.
 */
final class V2VariationTaxClass implements UpgradeStep {
	public function run(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off schema upgrade on a plugin table.
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET tax_class = %s WHERE is_base = 0 AND tax_class = %s', Schema::table( 'variants' ), 'parent', '' )
		);
		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'flexacommerce_variants' );
		}
	}
}
