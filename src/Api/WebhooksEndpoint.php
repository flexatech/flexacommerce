<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api;

use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /webhooks/{gateway} (doc 02 §7.8). There is no user to check: the
 * gateway verifies the signature in parse_webhook(). A disabled gateway
 * still receives its late webhooks.
 */
final class WebhooksEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/(?P<gateway>[a-z0-9_]{1,64})',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'receive' ],
				'permission_callback' => [ $this, 'gateway_permission' ],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function gateway_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( Plugin::instance()->payments()->gateways()->has( (string) $request['gateway'] ) ) {
			return true;
		}
		return new WP_Error( 'flexacommerce_unknown_gateway', __( 'Unknown payment gateway.', 'flexacommerce' ), [ 'status' => 404 ] );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function receive( WP_REST_Request $request ): WP_REST_Response {
		$gateway = (string) $request['gateway'];
		try {
			$status = Plugin::instance()->payments()->service()->handle_webhook( $gateway, $request );
		} catch ( \Throwable $e ) {
			// 500 so the gateway retries; the dedupe row rolled back with the transaction.
			Plugin::instance()->services()->logger( 'payments' )->error(
				'Webhook from {gateway} failed',
				[
					'gateway'   => $gateway,
					'exception' => $e,
				]
			);
			$status = 500;
		}
		$response = new WP_REST_Response( [ 'received' => $status === 200 ], $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
