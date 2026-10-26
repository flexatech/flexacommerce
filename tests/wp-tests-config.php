<?php
/**
 * WordPress test-suite config. Every value can be overridden with an
 * environment variable so CI and local machines share this file.
 *
 * The suite DROPS every table in this database. Never point it at a site.
 */

declare(strict_types=1);

define( 'ABSPATH', rtrim( (string) ( getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__, 4 ) ), '/' ) . '/' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'fxc_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = getenv( 'WP_TESTS_TABLE_PREFIX' ) ?: 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'FlexaCommerce Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

// Distinct values, as on a real site: with duplicates wp_salt() falls back
// to salts stored as options, which adds a query the budgets would count.
define( 'AUTH_KEY', 'fxc-test-auth-key' );
define( 'SECURE_AUTH_KEY', 'fxc-test-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'fxc-test-logged-in-key' );
define( 'NONCE_KEY', 'fxc-test-nonce-key' );
define( 'AUTH_SALT', 'fxc-test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'fxc-test-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'fxc-test-logged-in-salt' );
define( 'NONCE_SALT', 'fxc-test-nonce-salt' );
