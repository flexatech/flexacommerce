<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Store;

use Flexa\Commerce\Plugin;
use Flexa\Commerce\Wishlist\WishlistRefused;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `store/wishlist`: the heart button (ADR-066). Every answer carries the
 * product ids in the list, which the button reads; the no-JS form posts to
 * admin-post.php instead.
 */
final class WishlistEndpoint extends StoreEndpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/store/wishlist',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'read_permission' ],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/store/wishlist/items',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'add' ],
					'permission_callback' => [ $this, 'write_permission' ],
					'args'                => [
						'product_id' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/store/wishlist/items/(?P<product_id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove' ],
					'permission_callback' => [ $this, 'write_permission' ],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return self::list( Plugin::instance()->wishlist()->ids( get_current_user_id() ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function add( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$ids = Plugin::instance()->wishlist()->add( get_current_user_id(), (int) $request->get_param( 'product_id' ) );
		} catch ( WishlistRefused $e ) {
			return new WP_Error(
				'flexacommerce_wishlist_' . $e->reason,
				WishlistRefused::text( $e->reason ),
				[
					'status' => $e->reason === 'limit' ? 429 : 400,
					'reason' => $e->reason,
				]
			);
		}
		return self::list( $ids, __( 'Saved to your wishlist.', 'flexacommerce' ), 201 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function remove( WP_REST_Request $request ): WP_REST_Response {
		$ids = Plugin::instance()->wishlist()->remove( get_current_user_id(), (int) $request->get_param( 'product_id' ) );
		return self::list( $ids, __( 'Removed from your wishlist.', 'flexacommerce' ) );
	}

	/** @param list<int> $ids */
	private static function list( array $ids, string $message = '', int $status = 200 ): WP_REST_Response {
		return self::respond(
			[
				'items'   => $ids,
				'count'   => count( $ids ),
				'message' => $message,
			],
			$status
		);
	}
}
