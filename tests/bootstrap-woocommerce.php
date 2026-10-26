<?php
/**
 * Migration bootstrap: the integration bootstrap plus a real WooCommerce
 * (doc 05 §11). WooCommerce comes from FXC_WOOCOMMERCE_DIR or from
 * tests/.cache/woocommerce (tools/fetch-woocommerce.sh). FXC_WC_STORAGE
 * picks the order storage for the whole run: `legacy` (posts), `hpos`, or
 * `sync` (HPOS authoritative with compatibility mode on).
 *
 * Tables get their own prefix, so this suite and the integration suite can
 * share the test database without dropping each other's tables.
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$fxc_wc_dir     = rtrim( (string) ( getenv( 'FXC_WOOCOMMERCE_DIR' ) ?: __DIR__ . '/.cache/woocommerce' ), '/' );
$fxc_wc_storage = (string) ( getenv( 'FXC_WC_STORAGE' ) ?: 'hpos' );
if ( ! is_file( $fxc_wc_dir . '/woocommerce.php' ) ) {
	fwrite( STDERR, "WooCommerce not found in {$fxc_wc_dir}. Run tools/fetch-woocommerce.sh first.\n" );
	exit( 1 );
}
if ( ! in_array( $fxc_wc_storage, [ 'legacy', 'hpos', 'sync' ], true ) ) {
	fwrite( STDERR, "FXC_WC_STORAGE must be legacy, hpos or sync.\n" );
	exit( 1 );
}
define( 'FXC_TESTS_WC_STORAGE', $fxc_wc_storage );

putenv( 'WP_TESTS_TABLE_PREFIX=wctests_' );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $fxc_wc_dir ): void {
		require $fxc_wc_dir . '/woocommerce.php';
		require dirname( __DIR__ ) . '/flexacommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		\Flexa\Commerce\Database\Schema::drop_all();
		\Flexa\Commerce\Setup\Activator::activate_site();

		\WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();

		$container = wc_get_container();
		$container->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		$hpos = FXC_TESTS_WC_STORAGE !== 'legacy';
		update_option( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, $hpos ? 'yes' : 'no' );
		update_option( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, FXC_TESTS_WC_STORAGE === 'sync' ? 'yes' : 'no' );
		update_option( 'woocommerce_feature_custom_order_tables_enabled', $hpos ? 'yes' : 'no' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
	}
);

require $tests_dir . '/includes/bootstrap.php';

\Flexa\Commerce\Plugin::instance()->orders();
