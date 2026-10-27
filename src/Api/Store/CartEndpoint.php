<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Store;

use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `store/cart`, `store/cart/items` and `store/cart/coupons` (doc 04 §2). The body is
 * `CartPresenter`'s shape.
 */
final class CartEndpoint extends StoreEndpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/store/cart',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'read_permission' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'clear' ],
					'permission_callback' => [ $this, 'write_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/store/cart/items',
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
						'variant_id' => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
						'quantity'   => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'attributes' => [
							'type'                 => 'object',
							'default'              => [],
							'additionalProperties' => [ 'type' => [ 'string', 'integer' ] ],
						],
						// Product option values; ProductOptions checks each one for its field.
						'options'    => [
							'type'    => 'object',
							'default' => [],
						],
						// The line these choices replace (ADR-037).
						'replace'    => [
							'type'    => 'string',
							'pattern' => '^[a-f0-9]{16}$',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/store/cart/items/(?P<key>[a-f0-9]{16})',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'write_permission' ],
					'args'                => [
						'quantity' => [
							'type'     => 'integer',
							'minimum'  => 0,
							'required' => true,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove' ],
					'permission_callback' => [ $this, 'write_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/store/cart/coupons',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'apply_coupon' ],
					'permission_callback' => [ $this, 'write_permission' ],
					'args'                => [
						'code' => [
							'type'      => 'string',
							'required'  => true,
							'maxLength' => Coupon::MAX_CODE_LENGTH,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/store/cart/coupons/(?P<code>[^/]+)',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_coupon' ],
					'permission_callback' => [ $this, 'write_permission' ],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		return $this->run( static fn (): CartState => Plugin::instance()->cart()->cart()->state( get_current_user_id() ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function add( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$attributes = [];
		$raw        = $request->get_param( 'attributes' );
		foreach ( is_array( $raw ) ? $raw : [] as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$attributes[ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		$options = $request->get_param( 'options' );
		$options = is_array( $options ) && $options !== [] ? ProductOptions::posted( $options ) : [];
		$replace = (string) $request->get_param( 'replace' );
		if ( $replace !== '' ) {
			return $this->run(
				static fn (): CartState => Plugin::instance()->cart()->cart()->replace(
					get_current_user_id(),
					$replace,
					(int) $request->get_param( 'product_id' ),
					(int) $request->get_param( 'variant_id' ),
					(int) $request->get_param( 'quantity' ),
					$attributes,
					$options
				)
			);
		}
		return $this->run(
			static fn (): CartState => Plugin::instance()->cart()->cart()->add(
				get_current_user_id(),
				(int) $request->get_param( 'product_id' ),
				(int) $request->get_param( 'variant_id' ),
				(int) $request->get_param( 'quantity' ),
				$attributes,
				$options
			),
			201
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			static fn (): CartState => Plugin::instance()->cart()->cart()->set_quantity(
				get_current_user_id(),
				(string) $request->get_param( 'key' ),
				(int) $request->get_param( 'quantity' )
			)
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			static fn (): CartState => Plugin::instance()->cart()->cart()->remove( get_current_user_id(), (string) $request->get_param( 'key' ) )
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function apply_coupon( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		return $this->run( static fn (): CartState => Plugin::instance()->cart()->cart()->apply_coupon( get_current_user_id(), $code ), 201 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function remove_coupon( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$code = sanitize_text_field( rawurldecode( (string) $request->get_param( 'code' ) ) );
		return $this->run( static fn (): CartState => Plugin::instance()->cart()->cart()->remove_coupon( get_current_user_id(), $code ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function clear( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		return $this->run( static fn (): CartState => Plugin::instance()->cart()->cart()->clear( get_current_user_id() ) );
	}

	/** @param callable():CartState $work */
	private function run( callable $work, int $status = 200 ): WP_REST_Response|WP_Error {
		try {
			$state = $work();
		} catch ( CommerceException $e ) {
			$error = self::error( $e );
			$error->add_data( array_merge( (array) $error->get_error_data(), [ 'nonce' => self::current_nonce() ] ) );
			return $error;
		}
		return self::respond( Plugin::instance()->cart()->presenter()->to_array( $state ), $status );
	}
}
