<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api;

use Flexa\Commerce\Support\Capabilities;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for REST endpoints. The permission callbacks live here so no
 * route can be registered without one (RoutesHavePermissionsTest checks).
 */
abstract class Endpoint {
	public const NAMESPACE = FLEXACOMMERCE_REST_NAMESPACE;

	abstract public function register_routes(): void;

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function manage_permission( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );
		return Capabilities::can_manage() ? true : self::forbidden();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function view_orders_permission( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );
		return Capabilities::can_view_orders() ? true : self::forbidden();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function edit_orders_permission( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );
		return Capabilities::can_edit_orders() ? true : self::forbidden();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function refund_orders_permission( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );
		return Capabilities::can_refund_orders() ? true : self::forbidden();
	}

	protected static function forbidden(): WP_Error {
		return new WP_Error(
			'flexacommerce_forbidden',
			__( 'You do not have permission to perform this action.', 'flexacommerce' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
