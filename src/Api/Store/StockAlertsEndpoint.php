<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Store;

use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\StockAlerts\SignupMessages;
use Flexa\Commerce\StockAlerts\SignupRefused;
use Flexa\Commerce\Support\ClientIp;
use Flexa\Commerce\Support\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `store/stock-alerts`: the back-in-stock signup from a product page
 * (ADR-065). The no-JS form posts to admin-post.php instead.
 */
final class StockAlertsEndpoint extends StoreEndpoint {
	public const LIMIT = 'stock_alert_ip';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/store/stock-alerts',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'write_permission' ],
					'args'                => [
						'product_id' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
						'variant_id' => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
						'email'      => [
							'type'      => 'string',
							'maxLength' => 190,
							'default'   => '',
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			( new RateLimiter() )->hit( self::LIMIT, ClientIp::get() );
		} catch ( RateLimited $e ) {
			return new WP_Error(
				'flexacommerce_rate_limited',
				SignupMessages::text( 'limit' ),
				[
					'status'      => 429,
					'retry_after' => $e->retry_after,
				]
			);
		}
		try {
			Plugin::instance()->stock_alerts()->subscribe(
				(int) $request->get_param( 'product_id' ),
				(int) $request->get_param( 'variant_id' ),
				(string) $request->get_param( 'email' ),
				get_current_user_id()
			);
		} catch ( SignupRefused $e ) {
			return new WP_Error(
				'flexacommerce_stock_alert_' . $e->reason,
				SignupMessages::text( $e->reason ),
				[
					'status' => 400,
					'reason' => $e->reason,
				]
			);
		}
		return self::respond(
			[
				'status'  => 'waiting',
				'message' => SignupMessages::text( SignupMessages::ADDED ),
			],
			201
		);
	}
}
