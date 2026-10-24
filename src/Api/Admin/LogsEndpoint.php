<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class LogsEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/logs',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'level'    => [
							'type' => 'string',
							'enum' => [ '', ...Logger::LEVELS ],
						],
						'channel'  => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						],
						'search'   => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'page'     => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'clear' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$repository = Plugin::instance()->services()->log_repository();
		$page       = (int) $request->get_param( 'page' );
		$per_page   = (int) $request->get_param( 'per_page' );
		$result     = $repository->list(
			[
				'level'   => (string) $request->get_param( 'level' ),
				'channel' => (string) $request->get_param( 'channel' ),
				'search'  => (string) $request->get_param( 'search' ),
			],
			$page,
			$per_page
		);

		$response = new WP_REST_Response(
			[
				'items'    => $result['items'],
				'total'    => $result['total'],
				'channels' => $repository->channels(),
			],
			200
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / max( 1, $per_page ) ) ) );
		return $response;
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function clear( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$deleted = Plugin::instance()->services()->log_repository()->clear();
		return new WP_REST_Response( [ 'deleted' => $deleted ], 200 );
	}
}
