<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Store;

use Flexa\Commerce\Contracts\Exceptions\ProductNotFound;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `store/products/{id}/variants`: the purchase options of a published
 * product, for products with more variations than the page inlines.
 */
final class ProductsEndpoint extends StoreEndpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/store/products/(?P<id>\d+)/variants',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'variants' ],
					'permission_callback' => [ $this, 'read_permission' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function variants( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$product = Plugin::instance()->catalog()->products()->find( $id );
		if ( $product === null || $product->status !== 'publish' || post_password_required( $id ) ) {
			return self::error( new ProductNotFound( $id ) );
		}
		$response = new WP_REST_Response( Plugin::instance()->cart()->purchase_options()->for_product( $product, get_current_user_id() ), 200 );
		// Catalog data: cacheable for a short while, like the page that links it.
		$response->header( 'Cache-Control', 'public, max-age=60' );
		return $response;
	}
}
