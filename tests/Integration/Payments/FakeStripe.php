<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Payments;

/**
 * The Stripe API answered from a table, through `pre_http_request`. A route
 * is "METHOD path" (no /v1/, no query) and answers [status, body]; a list of
 * answers is used in turn, the last one repeating. Every call is recorded
 * with its decoded form body and headers.
 */
final class FakeStripe {
	/** @var array<string,array{0:int,1:array<string,mixed>}|list<array{0:int,1:array<string,mixed>}>> */
	public static array $routes = [];

	/** @var list<array{method:string,path:string,query:array<string,mixed>,body:array<string,mixed>,headers:array<string,string>}> */
	public static array $calls = [];

	public static function install(): void {
		self::$routes = [];
		self::$calls  = [];
		add_filter( 'pre_http_request', [ self::class, 'answer' ], 10, 3 );
	}

	public static function uninstall(): void {
		remove_filter( 'pre_http_request', [ self::class, 'answer' ], 10 );
		self::$routes = [];
		self::$calls  = [];
	}

	/** The connected account, an empty webhook list and the wallet domain, so a save of keys works. */
	public static function connectable(): void {
		self::$routes['GET account']                 = [
			200,
			[
				'id'       => 'acct_1',
				'settings' => [ 'dashboard' => [ 'display_name' => 'Hopper Goods' ] ],
			],
		];
		self::$routes['GET webhook_endpoints']       = [ 200, [ 'data' => [] ] ];
		self::$routes['POST webhook_endpoints']      = [
			200,
			[
				'id'     => 'we_1',
				'secret' => 'whsec_made_for_you',
			],
		];
		self::$routes['DELETE webhook_endpoints/']   = [ 200, [ 'deleted' => true ] ];
		self::$routes['POST payment_method_domains'] = [
			200,
			[
				'id'        => 'pmd_1',
				'apple_pay' => [ 'status' => 'active' ],
			],
		];
	}

	/** Drop a built gateway and its cached option, as the next request would. */
	public static function forget( string $id ): void {
		$gateways = \Flexa\Commerce\Plugin::instance()->payments()->gateways();
		foreach ( [ [ $gateways, 'built' ], [ $gateways->settings(), 'cache' ] ] as [ $object, $name ] ) {
			$property = new \ReflectionProperty( $object, $name );
			$value    = (array) $property->getValue( $object );
			unset( $value[ $id ] );
			$property->setValue( $object, $value );
		}
	}

	/** @return list<array{method:string,path:string,query:array<string,mixed>,body:array<string,mixed>,headers:array<string,string>}> */
	public static function calls_to( string $method, string $path ): array {
		return array_values( array_filter( self::$calls, static fn ( array $c ): bool => $c['method'] === $method && $c['path'] === $path ) );
	}

	/**
	 * @param false|array<string,mixed>|\WP_Error $pre
	 * @param array<string,mixed>                 $args
	 * @return false|array<string,mixed>
	 */
	public static function answer( mixed $pre, array $args, string $url ): mixed {
		if ( ! str_starts_with( $url, 'https://api.stripe.com/v1/' ) ) {
			return $pre;
		}
		$parts  = (array) wp_parse_url( $url );
		$path   = substr( (string) ( $parts['path'] ?? '' ), 4 );
		$method = (string) ( $args['method'] ?? 'GET' );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		parse_str( is_string( $args['body'] ?? null ) ? $args['body'] : '', $body );
		self::$calls[] = [
			'method'  => $method,
			'path'    => $path,
			'query'   => $query,
			'body'    => $body,
			'headers' => (array) ( $args['headers'] ?? [] ),
		];
		$route         = self::$routes[ $method . ' ' . $path ] ?? null;
		if ( $route === null ) {
			// A route for a prefix, like "DELETE webhook_endpoints/".
			foreach ( self::$routes as $key => $candidate ) {
				if ( str_ends_with( $key, '/' ) && str_starts_with( $method . ' ' . $path, $key ) ) {
					$route = $candidate;
				}
			}
		}
		$route ??= [
			404,
			[
				'error' => [
					'type'    => 'invalid_request_error',
					'message' => 'No such route: ' . $method . ' ' . $path,
				],
			],
		];
		if ( array_is_list( $route ) && is_array( $route[0] ) ) {
			$next                                  = count( $route ) > 1 ? array_shift( $route ) : $route[0];
			$queue                                 = $route;
			self::$routes[ $method . ' ' . $path ] = $queue;
			$route                                 = $next;
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
