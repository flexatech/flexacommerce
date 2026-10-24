<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET returns settings; POST/PATCH merge a partial update. Turning on data
 * removal at uninstall needs `confirm: "DELETE"` in the same request.
 */
final class SettingsEndpoint extends Endpoint {
	public const CONFIRM_WORD = 'DELETE';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( self::payload( Settings::all() ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body    = (array) $request->get_json_params();
		$confirm = isset( $body['confirm'] ) && is_string( $body['confirm'] ) ? $body['confirm'] : '';
		unset( $body['confirm'] );

		$clean = Settings::sanitize( $body );
		if ( ( $clean['remove_data_on_uninstall'] ?? false ) === true
			&& ! Settings::bool( 'remove_data_on_uninstall' )
			&& $confirm !== self::CONFIRM_WORD ) {
			return new WP_Error(
				'flexacommerce_confirmation_required',
				__( 'Type DELETE to confirm that all store data will be removed when the plugin is uninstalled.', 'flexacommerce' ),
				[ 'status' => 400 ]
			);
		}

		return new WP_REST_Response( self::payload( Settings::update( $clean ) ), 200 );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array{settings:array<string,mixed>,currencies:list<string>}
	 */
	private static function payload( array $settings ): array {
		return [
			'settings'   => $settings,
			'currencies' => Currency::codes(),
		];
	}
}
