<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\Extensions;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Maintenance\Doctor;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Setup\Requirements;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class StatusEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'show' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		global $wpdb, $wp_version;

		$services = Plugin::instance()->services();

		return new WP_REST_Response(
			[
				'environment'  => [
					'plugin_version' => FLEXACOMMERCE_VERSION,
					'php_version'    => PHP_VERSION,
					'wp_version'     => (string) $wp_version,
					'db_server'      => (string) $wpdb->db_server_info(),
					'multisite'      => is_multisite(),
					'woocommerce'    => class_exists( 'WooCommerce' ),
					'api_version'    => Extensions::API_VERSION,
				],
				'database'     => [
					'installed_version' => Schema::installed_version(),
					'code_version'      => (int) Schema::DB_VERSION,
					'tables'            => Schema::status(),
				],
				'requirements' => Requirements::check(),
				'doctor'       => ( new Doctor( $services->integrity() ) )->run(),
			],
			200
		);
	}
}
