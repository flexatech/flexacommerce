<?php
/**
 * Unit-test bootstrap: just enough WordPress, in memory, to run pure logic
 * without a database. Anything that needs real WordPress or MySQL belongs in
 * tests/Integration.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'FLEXACOMMERCE_VERSION', 'test' );
define( 'FLEXACOMMERCE_FILE', dirname( __DIR__ ) . '/flexacommerce.php' );
define( 'FLEXACOMMERCE_PATH', dirname( __DIR__ ) . '/' );
define( 'FLEXACOMMERCE_URL', 'https://example.test/wp-content/plugins/flexacommerce/' );
define( 'FLEXACOMMERCE_BASENAME', 'flexacommerce/flexacommerce.php' );
define( 'FLEXACOMMERCE_REST_NAMESPACE', 'flexacommerce/v1' );
define( 'FLEXACOMMERCE_TEXT_DOMAIN', 'flexacommerce' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

/** In-memory WordPress state the stubs read and write; tests reset it. */
final class WP_Test_State {
	/** @var array<string,mixed> */
	public static array $options = [];
	/** @var array<string,list<callable>> */
	public static array $filters = [];
	/** @var list<array{0:string,1:array<int,mixed>}> */
	public static array $actions = [];

	public static function reset(): void {
		self::$options = [];
		self::$filters = [];
		self::$actions = [];
	}
}

/** Minimal wpdb so type hints resolve; tests extend it with fakes. */
class wpdb {
	public string $prefix     = 'wp_';
	public string $posts      = 'wp_posts';
	public string $options    = 'wp_options';
	public string $last_error = '';

	public function query( string $query ): int|bool {
		unset( $query );
		return 0;
	}

	public function prepare( string $query, mixed ...$args ): string {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		foreach ( $args as $arg ) {
			$query = (string) preg_replace( '/%[isd]/', is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'", $query, 1 );
		}
		return $query;
	}

	public function get_var( string $query ): mixed {
		unset( $query );
		return null;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param list<string>|string|null $format
	 */
	public function insert( string $table, array $data, array|string|null $format = null ): int|false {
		unset( $table, $data, $format );
		return 1;
	}

	public function __get( string $name ): mixed {
		unset( $name );
		return null;
	}
}

function get_option( string $name, mixed $default = false ): mixed {
	return array_key_exists( $name, WP_Test_State::$options ) ? WP_Test_State::$options[ $name ] : $default;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	unset( $autoload );
	WP_Test_State::$options[ $name ] = $value;
	return true;
}

function add_option( string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $name, WP_Test_State::$options ) ) {
		return false;
	}
	WP_Test_State::$options[ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( WP_Test_State::$options[ $name ] );
	return true;
}

function add_filter( string $hook, callable $callback ): bool {
	WP_Test_State::$filters[ $hook ][] = $callback;
	return true;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	foreach ( WP_Test_State::$filters[ $hook ] ?? [] as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function has_filter( string $hook ): bool {
	return ( WP_Test_State::$filters[ $hook ] ?? [] ) !== [];
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}

function home_url( string $path = '' ): string {
	return 'https://shop.example.test' . $path;
}

function site_url( string $path = '' ): string {
	return 'https://shop.example.test' . $path;
}

function do_action( string $hook, mixed ...$args ): void {
	WP_Test_State::$actions[] = [ $hook, $args ];
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function sanitize_text_field( string $text ): string {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $text ) ) );
}

function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
	return json_encode( $data, $flags );
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}

function sanitize_title( string $title ): string {
	$title = strtolower( trim( strip_tags( $title ) ) );
	$title = (string) preg_replace( '/[^a-z0-9_\-]+/', '-', $title );
	return trim( (string) preg_replace( '/-+/', '-', $title ), '-' );
}

$GLOBALS['wpdb'] = new wpdb();
