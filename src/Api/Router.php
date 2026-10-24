<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api;

use Flexa\Commerce\Concerns\HasInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every REST endpoint in one place. Extensions add their own
 * endpoints by hooking `flexacommerce.rest.register_routes`.
 */
final class Router {
	use HasInstance;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		if ( self::needs_core_routes() ) {
			( new Admin\SettingsEndpoint() )->register_routes();
			( new Admin\StatusEndpoint() )->register_routes();
			( new Admin\LogsEndpoint() )->register_routes();
			( new Admin\ProductsEndpoint() )->register_routes();
			( new Admin\AttributesEndpoint() )->register_routes();
		}

		// Extension routes live in their own namespaces (doc 04), so this always runs.
		do_action( 'flexacommerce.rest.register_routes' );
	}

	/**
	 * False only for a REST request to another namespace (doc 10 F6).
	 * Internal dispatches (`rest_do_request()`, preloading) run outside a
	 * REST request, the index lists every namespace and a batch can carry
	 * our routes, so those still register everything.
	 */
	private static function needs_core_routes(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return true;
		}
		$route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		$route = '/' . ltrim( is_string( $route ) ? $route : '', '/' );
		if ( $route === '/' || str_starts_with( $route, '/batch/' ) ) {
			return true;
		}
		$namespace = '/' . FLEXACOMMERCE_REST_NAMESPACE;
		return $route === $namespace || str_starts_with( $route, $namespace . '/' );
	}
}
