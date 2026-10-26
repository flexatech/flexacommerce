<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Support\SameSite;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every REST endpoint in one place, called from `rest_api_init`. Extensions add their own
 * endpoints by hooking `flexacommerce.rest.register_routes`.
 */
final class Router {
	use HasInstance;

	public function register_routes(): void {
		$scope = self::scope();
		if ( $scope === 'all' ) {
			( new Admin\SettingsEndpoint() )->register_routes();
			( new Admin\StatusEndpoint() )->register_routes();
			( new Admin\LogsEndpoint() )->register_routes();
			( new Admin\ProductsEndpoint() )->register_routes();
			( new Admin\AttributesEndpoint() )->register_routes();
			( new Admin\OrdersEndpoint() )->register_routes();
			( new Admin\CustomersEndpoint() )->register_routes();
			( new Admin\GatewaysEndpoint() )->register_routes();
			( new Admin\ShippingEndpoint() )->register_routes();
			( new Admin\TaxesEndpoint() )->register_routes();
			( new Admin\CouponsEndpoint() )->register_routes();
			( new Admin\BadgesEndpoint() )->register_routes();
			( new Admin\PriceRulesEndpoint() )->register_routes();
			( new Admin\EmailsEndpoint() )->register_routes();
			( new Admin\MigrationsEndpoint() )->register_routes();
			( new Admin\CutoverEndpoint() )->register_routes();
			( new WebhooksEndpoint() )->register_routes();
		}
		if ( $scope !== 'none' ) {
			( new Store\CartEndpoint() )->register_routes();
			( new Store\CheckoutEndpoint() )->register_routes();
			( new Store\ProductsEndpoint() )->register_routes();
			add_filter( 'rest_authentication_errors', [ $this, 'keep_cookie_user_on_store' ], 50 );
		}

		// Extension routes live in their own namespaces (doc 04), so this always runs.
		do_action( 'flexacommerce.rest.register_routes' );
	}

	/**
	 * Which of our endpoints a request needs (doc 10 F6): `none` for a REST
	 * request to another namespace, `store` for one to our store routes (a
	 * checkout builds no admin endpoint), `all` otherwise. Internal
	 * dispatches (`rest_do_request()`, preloading) run outside a REST
	 * request, the index lists every namespace and a batch can carry our
	 * routes, so those still register everything.
	 *
	 * @return 'all'|'store'|'none'
	 */
	private static function scope(): string {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return 'all';
		}
		$route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		$route = '/' . ltrim( is_string( $route ) ? $route : '', '/' );
		if ( $route === '/' || str_starts_with( $route, '/batch/' ) ) {
			return 'all';
		}
		$namespace = '/' . FLEXACOMMERCE_REST_NAMESPACE;
		if ( str_starts_with( $route, $namespace . '/store/' ) ) {
			return 'store';
		}
		return $route === $namespace || str_starts_with( $route, $namespace . '/' ) ? 'all' : 'none';
	}

	/**
	 * Store routes are protected by `X-FXC-Nonce` instead of `X-WP-Nonce`
	 * (doc 06 §2). Without this, core's cookie check (priority 100) would
	 * treat a logged-in shopper as a guest on every store request, since the
	 * storefront script sends no core REST nonce. Only same-site requests
	 * keep the user: core echoes any Origin in its CORS headers, so a
	 * cross-site GET would otherwise be able to read a shopper's cart.
	 */
	public function keep_cookie_user_on_store( mixed $result ): mixed {
		if ( $result !== null ) {
			return $result;
		}
		$route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		$route = '/' . ltrim( is_string( $route ) ? $route : '', '/' );
		if ( ! str_starts_with( $route, '/' . FLEXACOMMERCE_REST_NAMESPACE . '/store/' ) ) {
			return $result;
		}
		return SameSite::current() ? true : $result;
	}
}
