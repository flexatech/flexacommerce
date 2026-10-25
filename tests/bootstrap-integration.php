<?php
/**
 * Integration bootstrap: real WordPress (wp-phpunit) against a real MySQL or
 * MariaDB. Configure with WP_CORE_DIR and WP_TESTS_DB_* (tests/wp-tests-config.php).
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/flexacommerce.php';
	}
);

// Install as an activation would, once the test site exists. The WordPress
// installer recreates only core tables (post ids restart at 1), so plugin
// tables are dropped first: rows left by an aborted run would otherwise
// match the new post ids.
tests_add_filter(
	'setup_theme',
	static function (): void {
		\Flexa\Commerce\Database\Schema::drop_all();
		\Flexa\Commerce\Setup\Activator::activate_site();
	}
);

require $tests_dir . '/includes/bootstrap.php';

// Build the order module before the first test saves the hooks, so the
// email listeners it binds stay bound in every test.
\Flexa\Commerce\Plugin::instance()->orders();
