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
 * "off" mode, register a plain public `fxc_product` post type, so a
 * catalog URL is compared with a core archive or single post of the same
 * posts.
 */

declare(strict_types=1);

// phpcs:disable -- test tooling, runs outside WordPress until wp-settings.php.

[ , $fxc_uri, $fxc_mode, $fxc_out, $fxc_json ] = $argv + [ '', '/', 'off', '', '{}' ];
$fxc_options = (array) json_decode( $fxc_json, true );

$_SERVER['REQUEST_URI']     = $fxc_uri;
$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['REQUEST_METHOD']  = (string) ( $fxc_options['method'] ?? 'GET' );
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'flexacommerce-probe';
$_GET                       = [];
parse_str( (string) parse_url( $fxc_uri, PHP_URL_QUERY ), $_GET );
$_REQUEST = $_GET;
$_COOKIE  = (array) ( $fxc_options['cookies'] ?? [] );
foreach ( (array) ( $fxc_options['headers'] ?? [] ) as $fxc_name => $fxc_value ) {
	$fxc_key             = strtoupper( str_replace( '-', '_', (string) $fxc_name ) );
	$fxc_key             = in_array( $fxc_key, [ 'CONTENT_TYPE', 'CONTENT_LENGTH' ], true ) ? $fxc_key : 'HTTP_' . $fxc_key;
	$_SERVER[ $fxc_key ] = (string) $fxc_value;
}
if ( isset( $fxc_options['body'] ) ) {
	// WP_REST_Server::get_raw_data() reads this before php://input, which is empty in the CLI.
	$GLOBALS['HTTP_RAW_POST_DATA'] = (string) $fxc_options['body'];
}

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
if ( ( $fxc_options['theme'] ?? '' ) === 'classic' ) {
	$fxc_root = dirname( __DIR__, 2 ) . '/fixtures/themes';
	$fxc_add( 'setup_theme', static fn () => register_theme_directory( $fxc_root ), 0 );
	foreach ( [ 'template', 'stylesheet' ] as $fxc_option ) {
		$fxc_add( 'pre_option_' . $fxc_option, static fn (): string => 'fxc-classic' );
		$fxc_add( 'pre_option_' . $fxc_option . '_root', static fn (): string => $fxc_root );
	}
} elseif ( $fxc_themes !== [] ) {
	$fxc_theme = basename( $fxc_themes[0] );
	$fxc_add( 'pre_option_template', static fn (): string => $fxc_theme );
	$fxc_add( 'pre_option_stylesheet', static fn (): string => $fxc_theme );
}
if ( isset( $fxc_options['per_page'] ) ) {
	$fxc_per_page = (int) $fxc_options['per_page'];
	$fxc_add( 'pre_option_posts_per_page', static fn (): int => $fxc_per_page );
}
// Catalog pages set no cookie and no no-cache header (P11). The CLI keeps
// no response headers, so record what goes through WordPress instead.
$fxc_nocache = false;
$fxc_headers = [];
$fxc_add( 'nocache_headers', static function ( $headers ) use ( &$fxc_nocache ) {
	$fxc_nocache = true;
	return $headers;
} );
$fxc_add( 'wp_headers', static function ( $headers ) use ( &$fxc_headers ) {
	$fxc_headers = (array) $headers;
	return $headers;
}, PHP_INT_MAX );
if ( $fxc_mode === 'off' && ! empty( $fxc_options['bare_types'] ) ) {
	$fxc_add( 'init', static function (): void {
		register_post_type( 'fxc_product', [ 'public' => true, 'has_archive' => true, 'rewrite' => false, 'query_var' => 'fxc_product' ] );
	} );
}
if ( $fxc_mode === 'on' ) {
	$fxc_add( 'muplugins_loaded', static function () use ( $fxc_plugin ): void {
		require $fxc_plugin;
	} );
}

register_shutdown_function(
	static function () use ( $fxc_start, $fxc_out, $fxc_classes, $fxc_options, &$fxc_nocache, &$fxc_headers ): void {
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
					'bytes'     => $bytes,
					'autoload'  => $autoload,
					'html'      => strlen( $html ),
					'status'    => http_response_code(),
					'state'     => $state === [] ? 0 : strlen( (string) wp_json_encode( $state ) ),
					'nocache'   => $fxc_nocache,
					'headers'   => $fxc_headers,
					'rest'      => $rest,
					'template'  => basename( (string) ( $GLOBALS['template'] ?? '' ) ),
					'counted'   => isset( $fxc_options['count'] ) ? substr_count( $html, (string) $fxc_options['count'] ) : 0,
				]
			)
		);
	}
);

ob_start();
require ABSPATH . 'wp-settings.php';
wp();
require ABSPATH . WPINC . '/template-loader.php';
