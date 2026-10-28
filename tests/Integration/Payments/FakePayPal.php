<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Payments;

/**
 * The PayPal API answered from a table, through `pre_http_request`, for
 * the sandbox and the live host. A route is "METHOD path" (no leading
 * slash, no query) and answers [status, body]; a list of answers is used
 * in turn, the last one repeating, and a key ending in "/" answers every
 * path under it. Every call is recorded with its decoded JSON body, its
 * headers and its host.
 */
final class FakePayPal {
	/** @var array<string,array{0:int,1:array<string,mixed>}|list<array{0:int,1:array<string,mixed>}>> */
	public static array $routes = [];

	/** @var list<array{method:string,path:string,query:array<string,mixed>,body:mixed,raw:string,headers:array<string,string>,live:bool}> */
	public static array $calls = [];

	public static function install(): void {
		self::$routes = [];
		self::$calls  = [];
		delete_option( \Flexa\Commerce\Payments\Gateways\PayPal\PayPalClient::TOKEN_OPTION );
		add_filter( 'pre_http_request', [ self::class, 'answer' ], 10, 3 );
	}

	public static function uninstall(): void {
		remove_filter( 'pre_http_request', [ self::class, 'answer' ], 10 );
		self::$routes = [];
		self::$calls  = [];
	}

	/** A token for any credentials and a webhook made on request, so a save works. */
	public static function connectable(): void {
		self::$routes['POST v1/oauth2/token']           = [
			200,
			[
				'access_token' => 'A21_token',
				'expires_in'   => 32400,
			],
		];
		self::$routes['POST v1/notifications/webhooks'] = [ 201, [ 'id' => 'WH-MADE-1' ] ];
	}

	/** @return list<array{method:string,path:string,query:array<string,mixed>,body:mixed,raw:string,headers:array<string,string>,live:bool}> */
	public static function calls_to( string $method, string $path ): array {
		return array_values( array_filter( self::$calls, static fn ( array $c ): bool => $c['method'] === $method && $c['path'] === $path ) );
	}

	/**
	 * @param false|array<string,mixed>|\WP_Error $pre
	 * @param array<string,mixed>                 $args
	 * @return false|array<string,mixed>
	 */
	public static function answer( mixed $pre, array $args, string $url ): mixed {
		$live = str_starts_with( $url, 'https://api-m.paypal.com/' );
		if ( ! $live && ! str_starts_with( $url, 'https://api-m.sandbox.paypal.com/' ) ) {
			return $pre;
		}
		$parts  = (array) wp_parse_url( $url );
		$path   = ltrim( (string) ( $parts['path'] ?? '' ), '/' );
		$method = (string) ( $args['method'] ?? 'GET' );
		$raw    = is_string( $args['body'] ?? null ) ? $args['body'] : '';
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		self::$calls[] = [
			'method'  => $method,
			'path'    => $path,
			'query'   => $query,
			'body'    => json_decode( $raw, true ),
			'raw'     => $raw,
			'headers' => (array) ( $args['headers'] ?? [] ),
			'live'    => $live,
		];
		$key           = $method . ' ' . $path;
		$route         = self::$routes[ $key ] ?? null;
		if ( $route === null ) {
			foreach ( self::$routes as $prefix => $candidate ) {
				if ( str_ends_with( $prefix, '/' ) && str_starts_with( $key, $prefix ) ) {
					$route = $candidate;
					$key   = $prefix;
				}
			}
		}
		$route ??= [
			404,
			[
				'name'    => 'RESOURCE_NOT_FOUND',
				'message' => 'No such route: ' . $method . ' ' . $path,
			],
		];
		if ( array_is_list( $route ) && is_array( $route[0] ) ) {
			$next                 = count( $route ) > 1 ? array_shift( $route ) : $route[0];
			self::$routes[ $key ] = $route;
			$route                = $next;
		}
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $route[1] ),
			'response' => [
				'code'    => $route[0],
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}
}
