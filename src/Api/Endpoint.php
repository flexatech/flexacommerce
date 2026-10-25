<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\DuplicateSku;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidAttribute;
use Flexa\Commerce\Contracts\Exceptions\CurrencyMismatch;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Exceptions\InvalidOrderRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidRefund;
use Flexa\Commerce\Contracts\Exceptions\InvalidTransition;
use Flexa\Commerce\Contracts\Exceptions\OrderNotFound;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Contracts\Exceptions\NotPurchasable;
use Flexa\Commerce\Contracts\Exceptions\ProductNotFound;
use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Contracts\Exceptions\TotalsChanged;
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

	/**
	 * Per-product check on routes with an `{id}`; 404 for ids that are not
	 * products, so the route does not reveal other post types.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function edit_product_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( ! Capabilities::can_edit_products() ) {
			return self::forbidden();
		}
		$id = (int) $request->get_param( 'id' );
		if ( get_post_type( $id ) !== PostTypes::PRODUCT ) {
			return self::error( new ProductNotFound( $id ) );
		}
		return Capabilities::can_edit_product( $id ) ? true : self::forbidden();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function edit_products_permission( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );
		return Capabilities::can_edit_products() ? true : self::forbidden();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function manage_product_terms_permission( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );
		return Capabilities::can_manage_product_terms() ? true : self::forbidden();
	}

	/** Maps a domain exception to a REST error with a stable code. */
	protected static function error( CommerceException $e ): WP_Error {
		$data = match ( true ) {
			$e instanceof DuplicateSku     => [
				'status'     => 409,
				'sku'        => $e->sku,
				'product_id' => $e->product_id,
			],
			$e instanceof InvalidProduct   => [
				'status' => 400,
				'field'  => $e->field,
			],
			$e instanceof ProductNotFound  => [ 'status' => 404 ],
			$e instanceof InvalidAttribute => [ 'status' => 400 ],
			$e instanceof InsufficientStock => [
				'status'     => 409,
				'variant_id' => $e->variant_id,
				'available'  => $e->available,
			],
			$e instanceof NotPurchasable   => [
				'status'     => 400,
				'variant_id' => $e->variant_id,
			],
			$e instanceof InvalidCartRequest => [
				'status' => $e->reason === 'conflict' ? 409 : 400,
				'field'  => $e->field,
				'reason' => $e->reason,
			],
			$e instanceof RateLimited      => [
				'status'      => 429,
				'retry_after' => $e->retry_after,
			],
			$e instanceof OrderNotFound    => [ 'status' => 404 ],
			$e instanceof InvalidOrderRequest => [
				'status' => 400,
				'field'  => $e->field,
			],
			$e instanceof InvalidTransition => [
				'status' => 409,
				'from'   => $e->from,
				'to'     => $e->to,
			],
			$e instanceof InvalidRefund    => [ 'status' => 400 ],
			$e instanceof InvalidCheckout  => [
				'status' => 400,
				'errors' => $e->errors,
			],
			$e instanceof TotalsChanged    => [ 'status' => 409 ],
			$e instanceof CurrencyMismatch => [ 'status' => 400 ],
			default                        => [ 'status' => 500 ],
		};
		return new WP_Error( 'flexacommerce_' . $e->code(), $e->getMessage(), $data );
	}

	protected static function forbidden(): WP_Error {
		return new WP_Error(
			'flexacommerce_forbidden',
			__( 'You do not have permission to perform this action.', 'flexacommerce' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
