<?php
/**
 * One front-end request in a fresh PHP process (doc 10 §5.2). Run by
 * Probe::run(), never directly by PHPUnit.
 *
 *   php probe-request.php <uri> <on|off> <result-file> [<options-json>]
 *
 * Loads WordPress from the test-suite config, with FlexaCommerce loaded as
 * the bootstrap does (or not at all), no other plugin, and writes the
 * counters as JSON to <result-file> at shutdown.
 *
 * Options: `method`, `body` (raw request body), `headers` (name => value),
 * `cookies`, `per_page` (posts_per_page), `theme` ("classic" for the
 * fixture classic theme in tests/fixtures/themes), `count` (a string;
 * the result's `counted` is how often it occurs in the page), and
 * `bare_types`: in
 * "off" mode, register a plain public `fxcm_product` post type, so a
 * catalog URL is compared with a core archive or single post of the same
 * posts.
 */

declare(strict_types=1);

// phpcs:disable -- test tooling, runs outside WordPress until wp-settings.php.

[ , $fxcm_uri, $fxcm_mode, $fxcm_out, $fxcm_json ] = $argv + [ '', '/', 'off', '', '{}' ];
$fxcm_options = (array) json_decode( $fxcm_json, true );

$_SERVER['REQUEST_URI']     = $fxcm_uri;
$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['REQUEST_METHOD']  = (string) ( $fxcm_options['method'] ?? 'GET' );
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'flexacommerce-probe';
$_GET                       = [];
parse_str( (string) parse_url( $fxcm_uri, PHP_URL_QUERY ), $_GET );
$_REQUEST = $_GET;
$_COOKIE  = (array) ( $fxcm_options['cookies'] ?? [] );
foreach ( (array) ( $fxcm_options['headers'] ?? [] ) as $fxcm_name => $fxcm_value ) {
	$fxcm_key             = strtoupper( str_replace( '-', '_', (string) $fxcm_name ) );
	$fxcm_key             = in_array( $fxcm_key, [ 'CONTENT_TYPE', 'CONTENT_LENGTH' ], true ) ? $fxcm_key : 'HTTP_' . $fxcm_key;
	$_SERVER[ $fxcm_key ] = (string) $fxcm_value;
}
if ( isset( $fxcm_options['body'] ) ) {
	// WP_REST_Server::get_raw_data() reads this before php://input, which is empty in the CLI.
	$GLOBALS['HTTP_RAW_POST_DATA'] = (string) $fxcm_options['body'];
}

define( 'WP_USE_THEMES', true );
define( 'SAVEQUERIES', true );
define( 'DISABLE_WP_CRON', true );

$fxcm_start   = hrtime( true );
$fxcm_plugin  = dirname( __DIR__, 3 ) . '/flexacommerce.php';
$fxcm_classes = get_declared_classes();

// The config sets $table_prefix, which wp-settings.php reads as a global.
require dirname( __DIR__, 2 ) . '/wp-tests-config.php';

$fxcm_add = static function ( string $hook, callable $callback, int $priority = 10 ): void {
	$GLOBALS['wp_filter'][ $hook ][ $priority ][] = [
		'function'      => $callback,
		'accepted_args' => 1,
	];
};
$fxcm_add( 'option_active_plugins', static fn (): array => [] );
$fxcm_add( 'site_option_active_sitewide_plugins', static fn (): array => [] );
$fxcm_add( 'wp_redirect', static fn (): bool => false );

// The test install points at a "default" theme that does not exist, which
// renders nothing. Use the newest bundled theme so the page does real work.
$fxcm_themes = glob( ABSPATH . 'wp-content/themes/twenty*', GLOB_ONLYDIR ) ?: [];
rsort( $fxcm_themes );
if ( ( $fxcm_options['theme'] ?? '' ) === 'classic' ) {
	$fxcm_root = dirname( __DIR__, 2 ) . '/fixtures/themes';
	$fxcm_add( 'setup_theme', static fn () => register_theme_directory( $fxcm_root ), 0 );
	foreach ( [ 'template', 'stylesheet' ] as $fxcm_option ) {
		$fxcm_add( 'pre_option_' . $fxcm_option, static fn (): string => 'fxc-classic' );
		$fxcm_add( 'pre_option_' . $fxcm_option . '_root', static fn (): string => $fxcm_root );
	}
} elseif ( $fxcm_themes !== [] ) {
	$fxcm_theme = basename( $fxcm_themes[0] );
	$fxcm_add( 'pre_option_template', static fn (): string => $fxcm_theme );
	$fxcm_add( 'pre_option_stylesheet', static fn (): string => $fxcm_theme );
}
if ( isset( $fxcm_options['per_page'] ) ) {
	$fxcm_per_page = (int) $fxcm_options['per_page'];
	$fxcm_add( 'pre_option_posts_per_page', static fn (): int => $fxcm_per_page );
}
// Catalog pages set no cookie and no no-cache header (P11). The CLI keeps
// no response headers, so record what goes through WordPress instead.
$fxcm_nocache = false;
$fxcm_headers = [];
$fxcm_add( 'nocache_headers', static function ( $headers ) use ( &$fxcm_nocache ) {
	$fxcm_nocache = true;
	return $headers;
} );
$fxcm_add( 'wp_headers', static function ( $headers ) use ( &$fxcm_headers ) {
	$fxcm_headers = (array) $headers;
	return $headers;
}, PHP_INT_MAX );
if ( $fxcm_mode === 'off' && ! empty( $fxcm_options['bare_types'] ) ) {
	$fxcm_add( 'init', static function (): void {
		register_post_type( 'fxcm_product', [ 'public' => true, 'has_archive' => true, 'rewrite' => false, 'query_var' => 'fxcm_product' ] );
	} );
}
if ( $fxcm_mode === 'on' ) {
	$fxcm_add( 'muplugins_loaded', static function () use ( $fxcm_plugin ): void {
		require $fxcm_plugin;
	} );
}

register_shutdown_function(
	static function () use ( $fxcm_start, $fxcm_out, $fxcm_classes, $fxcm_options, &$fxcm_nocache, &$fxcm_headers ): void {
		$html = '';
		while ( ob_get_level() > 0 ) {
			$html .= (string) ob_get_clean();
		}
		$ms   = ( hrtime( true ) - $fxcm_start ) / 1e6;
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

		// Our files the page loads, with their gzip size (P8): the printed
		// URLs (script modules and the import map included) and the styles
		// WordPress inlined, whose URL is no longer in the page.
		$assets = [];
		$urls   = [];
		foreach ( [ 'wp_scripts', 'wp_styles' ] as $registry ) {
			$deps = $GLOBALS[ $registry ] ?? null;
			if ( ! $deps instanceof WP_Dependencies ) {
				continue;
			}
			foreach ( $deps->done as $handle ) {
				$src = (string) ( $deps->registered[ $handle ]->src ?? '' );
				if ( str_starts_with( (string) $handle, 'flexacommerce' ) || str_contains( $src, '/flexacommerce/' ) ) {
					$assets[] = $registry . ':' . $handle;
					$urls[]   = $src;
				}
			}
		}
		$bytes = [];
		if ( defined( 'FLEXACOMMERCE_URL' ) ) {
			preg_match_all( '#' . preg_quote( FLEXACOMMERCE_URL, '#' ) . '[^"\'?\s]+\.(?:js|css)#', str_replace( '\\/', '/', $html ), $found );
			foreach ( array_merge( $urls, $found[0] ) as $url ) {
				if ( ! str_starts_with( $url, FLEXACOMMERCE_URL ) ) {
					continue;
				}
				$file = strtok( substr( $url, strlen( FLEXACOMMERCE_URL ) ), '?' );
				if ( is_string( $file ) && ! isset( $bytes[ $file ] ) && is_readable( FLEXACOMMERCE_PATH . $file ) ) {
					$bytes[ $file ] = strlen( (string) gzencode( (string) file_get_contents( FLEXACOMMERCE_PATH . $file ) ) );
				}
			}
			ksort( $bytes );
		}

		$autoload = [];
		if ( function_exists( 'wp_load_alloptions' ) ) {
			foreach ( wp_load_alloptions() as $name => $value ) {
				if ( str_starts_with( (string) $name, 'flexacommerce' ) ) {
					$autoload[ $name ] = strlen( (string) $value );
				}
			}
		}

		// Inlined variant state of the add-to-cart forms (P3b).
		$state = function_exists( 'wp_interactivity_state' ) ? wp_interactivity_state( 'flexacommerce/cart' ) : [];

		// The REST response, for routes that answer one.
		$rest = null;
		if ( str_contains( $html, '"' ) && ( $html[0] ?? '' ) === '{' ) {
			$rest = json_decode( $html, true );
		}

		file_put_contents(
			$fxcm_out,
			(string) json_encode(
				[
					'ms'        => round( $ms, 2 ),
					'peak'      => $peak,
					'queries'   => $queries,
					'classes'   => count( get_declared_classes() ) - count( $fxcm_classes ),
					'own'       => $own,
					'callbacks' => $callbacks,
					'assets'    => $assets,
					'bytes'     => $bytes,
					'autoload'  => $autoload,
					'html'      => strlen( $html ),
					'status'    => http_response_code(),
					'state'     => $state === [] ? 0 : strlen( (string) wp_json_encode( $state ) ),
					'nocache'   => $fxcm_nocache,
					'headers'   => $fxcm_headers,
					'rest'      => $rest,
					'template'  => basename( (string) ( $GLOBALS['template'] ?? '' ) ),
					'counted'   => isset( $fxcm_options['count'] ) ? substr_count( $html, (string) $fxcm_options['count'] ) : 0,
				]
			)
		);
	}
);

ob_start();
require ABSPATH . 'wp-settings.php';
wp();
require ABSPATH . WPINC . '/template-loader.php';
