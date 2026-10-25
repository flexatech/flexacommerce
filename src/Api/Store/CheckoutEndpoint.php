<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Store;

use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Countries;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Flexa\Commerce\Support\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * `store/checkout`, `store/checkout/shipping` and `store/orders/{id}/pay`
 * (doc 04 §2). The form state and every quote are computed on the server;
 * the body only names choices.
 */
final class CheckoutEndpoint extends StoreEndpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/store/checkout',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'read_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'place' ],
					'permission_callback' => [ $this, 'write_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/store/checkout/shipping',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'shipping' ],
					'permission_callback' => [ $this, 'write_permission' ],
					'args'                => [
						'country'  => [
							'type'     => 'string',
							'required' => true,
						],
						'state'    => [
							'type'    => 'string',
							'default' => '',
						],
						'postcode' => [
							'type'    => 'string',
							'default' => '',
						],
						'city'     => [
							'type'    => 'string',
							'default' => '',
						],
						'rate_id'  => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/store/orders/(?P<id>\d+)/pay',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'pay' ],
					'permission_callback' => [ $this, 'write_permission' ],
					'args'                => [
						'key'            => [
							'type'     => 'string',
							'required' => true,
						],
						'payment_method' => [
							'type'     => 'string',
							'required' => true,
						],
						'gateway_input'  => [
							'type'    => 'object',
							'default' => [],
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		return $this->run( static fn ( CheckoutService $checkout ): array => $checkout->form_state( get_current_user_id() ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function place( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = CheckoutRequest::from_array( (array) $request->get_json_params(), get_current_user_id(), ClientIp::get() );
		return $this->run( static fn ( CheckoutService $checkout ): array => $checkout->place_order( $input )->to_array(), 201 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function shipping( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$destination = new Destination(
			strtoupper( sanitize_text_field( (string) $request->get_param( 'country' ) ) ),
			strtoupper( sanitize_text_field( (string) $request->get_param( 'state' ) ) ),
			Countries::normalize_postcode( sanitize_text_field( (string) $request->get_param( 'postcode' ) ) ),
			sanitize_text_field( (string) $request->get_param( 'city' ) ),
		);
		$rate_id     = sanitize_text_field( (string) $request->get_param( 'rate_id' ) );
		return $this->run( static fn ( CheckoutService $checkout ): array => $checkout->update_shipping( get_current_user_id(), $destination, $rate_id ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function pay( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $request->get_param( 'gateway_input' );
		$input = is_array( $input ) ? array_filter( $input, 'is_string', ARRAY_FILTER_USE_KEY ) : [];
		return $this->run(
			static fn ( CheckoutService $checkout ): array => $checkout->retry_payment(
				(int) $request->get_param( 'id' ),
				(string) $request->get_param( 'key' ),
				sanitize_key( (string) $request->get_param( 'payment_method' ) ),
				$input,
				get_current_user_id()
			)->to_array()
		);
	}

	/** @param callable(CheckoutService):array<string,mixed> $work */
	private function run( callable $work, int $status = 200 ): WP_REST_Response|WP_Error {
		try {
			$data = $work( Plugin::instance()->checkout()->service() );
		} catch ( CommerceException $e ) {
			$error = self::error( $e );
			$error->add_data( array_merge( (array) $error->get_error_data(), [ 'nonce' => self::current_nonce() ] ) );
			return $error;
		}
		return self::respond( $data, $status );
	}
}
