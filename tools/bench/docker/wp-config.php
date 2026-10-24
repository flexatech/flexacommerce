<?php
/**
 * Benchmark stack only (doc 10 §5.3). The host name picks the store:
 *   fxc.bench   FlexaCommerce store      (database bench_fxc)
 *   wc.bench    WooCommerce store        (database bench_wc)
 *   core.bench  bench_fxc with every plugin off, the core baseline
 */

$bench_host  = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? 'fxc.bench' ) );
$bench_store = [
	'fxc.bench'  => 'bench_fxc',
	'core.bench' => 'bench_fxc',
	'wc.bench'   => 'bench_wc',
];
if ( ! isset( $bench_store[ $bench_host ] ) ) {
	$bench_host = 'fxc.bench';
}

define( 'BENCH_HOST', $bench_host );
define( 'DB_NAME', $bench_store[ $bench_host ] );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'bench' );
define( 'DB_HOST', 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_HOME', 'http://' . $bench_host );
define( 'WP_SITEURL', 'http://' . $bench_host );

// Fixed keys: this stack holds no secrets and must behave the same on every run.
foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as $bench_key ) {
	define( $bench_key, 'bench-' . $bench_key );
}

$table_prefix = 'wp_';

define( 'WP_DEBUG', false );
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'WP_ENVIRONMENT_TYPE', 'production' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
