<?php
/**
 * One front-end request in a fresh PHP process (doc 10 §5.2). Run by
 * Probe::run(), never directly by PHPUnit.
 *
 *   php probe-request.php <uri> <on|off> <result-file>
 *
 * Loads WordPress from the test-suite config, with FlexaCommerce loaded as
 * the bootstrap does (or not at all), no other plugin, and writes the
 * counters as JSON to <result-file> at shutdown.
 */

declare(strict_types=1);

// phpcs:disable -- test tooling, runs outside WordPress until wp-settings.php.

[ , $fxc_uri, $fxc_mode, $fxc_out ] = $argv + [ '', '/', 'off', '' ];

$_SERVER['REQUEST_URI']     = $fxc_uri;
$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'flexacommerce-probe';
$_GET                       = [];
parse_str( (string) parse_url( $fxc_uri, PHP_URL_QUERY ), $_GET );
$_REQUEST = $_GET;

define( 'WP_USE_THEMES', true );
define( 'SAVEQUERIES', true );
define( 'DISABLE_WP_CRON', true );

$fxc_start   = hrtime( true );
$fxc_plugin  = dirname( __DIR__, 3 ) . '/flexacommerce.php';
$fxc_classes = get_declared_classes();

// The config sets $table_prefix, which wp-settings.php reads as a global.
require dirname( __DIR__, 2 ) . '/wp-tests-config.php';

$fxc_add = static function ( string $hook, callable $callback, int $priority = 10 ): void {
	$GLOBALS['wp_filter'][ $hook ][ $priority ][] = [
		'function'      => $callback,
		'accepted_args' => 1,
	];
};
$fxc_add( 'option_active_plugins', static fn (): array => [] );
$fxc_add( 'site_option_active_sitewide_plugins', static fn (): array => [] );
$fxc_add( 'wp_redirect', static fn (): bool => false );

// The test install points at a "default" theme that does not exist, which
// renders nothing. Use the newest bundled theme so the page does real work.
$fxc_themes = glob( ABSPATH . 'wp-content/themes/twenty*', GLOB_ONLYDIR ) ?: [];
rsort( $fxc_themes );
if ( $fxc_themes !== [] ) {
	$fxc_theme = basename( $fxc_themes[0] );
	$fxc_add( 'pre_option_template', static fn (): string => $fxc_theme );
	$fxc_add( 'pre_option_stylesheet', static fn (): string => $fxc_theme );
}
if ( $fxc_mode === 'on' ) {
	$fxc_add( 'muplugins_loaded', static function () use ( $fxc_plugin ): void {
		require $fxc_plugin;
	} );
}

register_shutdown_function(
	static function () use ( $fxc_start, $fxc_out, $fxc_classes ): void {
		$html = '';
		while ( ob_get_level() > 0 ) {
			$html .= (string) ob_get_clean();
		}
		$ms   = ( hrtime( true ) - $fxc_start ) / 1e6;
		$peak = memory_get_peak_usage();

		$queries = [];
		foreach ( (array) ( $GLOBALS['wpdb']->queries ?? [] ) as $q ) {
			$queries[] = [
				'sql'    => preg_replace( '/\s+/', ' ', (string) $q[0] ),
				'caller' => (string) $q[2],
			];
		}

		$callbacks = 0;
		foreach ( (array) ( $GLOBALS['wp_filter'] ?? [] ) as $hook ) {
			foreach ( $hook instanceof WP_Hook ? $hook->callbacks : [] as $list ) {
				$callbacks += count( $list );
			}
		}

		$ours = static fn ( string $name ): bool => str_starts_with( $name, 'Flexa\\Commerce\\' );
		$own  = array_values(
			array_filter( array_merge( get_declared_classes(), get_declared_traits(), get_declared_interfaces() ), $ours )
		);
		sort( $own );

		$assets = [];
		foreach ( [ 'wp_scripts', 'wp_styles' ] as $registry ) {
			$deps = $GLOBALS[ $registry ] ?? null;
			if ( ! $deps instanceof WP_Dependencies ) {
				continue;
			}
			foreach ( $deps->done as $handle ) {
				$src = (string) ( $deps->registered[ $handle ]->src ?? '' );
				if ( str_starts_with( (string) $handle, 'flexacommerce' ) || str_contains( $src, '/flexacommerce/' ) ) {
					$assets[] = $registry . ':' . $handle;
				}
			}
		}

		$autoload = [];
		if ( function_exists( 'wp_load_alloptions' ) ) {
			foreach ( wp_load_alloptions() as $name => $value ) {
				if ( str_starts_with( (string) $name, 'flexacommerce' ) ) {
					$autoload[ $name ] = strlen( (string) $value );
				}
			}
		}

		file_put_contents(
			$fxc_out,
			(string) json_encode(
				[
					'ms'        => round( $ms, 2 ),
					'peak'      => $peak,
					'queries'   => $queries,
					'classes'   => count( get_declared_classes() ) - count( $fxc_classes ),
					'own'       => $own,
					'callbacks' => $callbacks,
					'assets'    => $assets,
					'autoload'  => $autoload,
					'html'      => strlen( $html ),
					'status'    => http_response_code(),
				]
			)
		);
	}
);

ob_start();
require ABSPATH . 'wp-settings.php';
wp();
require ABSPATH . WPINC . '/template-loader.php';
