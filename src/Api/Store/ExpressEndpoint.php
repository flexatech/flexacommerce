<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Store;

use Flexa\Commerce\Cart\Cart;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\ClientIp;
use Flexa\Commerce\Support\Countries;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `store/express` and `store/express/order` (ADR-053): the wallet buttons
 * (Apple Pay, Google Pay). `source` is `cart` for the shopper's cart, or
 * `product` with a `product` choice for "buy now" on the product page,
 * which never touches the cart. The quote does not store the address the
 * wallet shares; the order goes through the same checks as the form.
 */
final class ExpressEndpoint extends StoreEndpoint {
	public function register_routes(): void {
		$common = [
			'source'         => [
				'type'    => 'string',
				'enum'    => [ 'cart', 'product' ],
				'default' => 'cart',
			],
			'product'        => [
				'type'    => 'object',
				'default' => [],
			],
			'shipping_rate'  => [
				'type'    => 'string',
				'default' => '',
			],
			'payment_method' => [
				'type'     => 'string',
				'required' => true,
			],
		];
		register_rest_route(
			self::NAMESPACE,
			'/store/express',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'quote' ],
					'permission_callback' => [ $this, 'write_permission' ],
					'args'                => $common + [
						'address' => [
							'type'    => 'object',
							'default' => [],
						],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/store/express/order',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'place' ],
					'permission_callback' => [ $this, 'write_permission' ],
					'args'                => $common,
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw     = $request->get_param( 'address' );
		$raw     = is_array( $raw ) ? $raw : [];
		$country = strtoupper( sanitize_text_field( (string) ( $raw['country'] ?? '' ) ) );
		$address = $country === '' ? null : [
			'country'  => $country,
			'state'    => Countries::state_code( $country, sanitize_text_field( (string) ( $raw['state'] ?? '' ) ) ),
			'postcode' => Countries::normalize_postcode( sanitize_text_field( (string) ( $raw['postcode'] ?? '' ) ) ),
			'city'     => sanitize_text_field( (string) ( $raw['city'] ?? '' ) ),
		];
		return $this->run(
			fn ( CheckoutService $checkout ): array => $checkout->express_quote(
				get_current_user_id(),
				$this->single( $request ),
				$address,
				sanitize_text_field( (string) $request->get_param( 'shipping_rate' ) ),
				sanitize_key( (string) $request->get_param( 'payment_method' ) )
			)
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function place( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = (array) $request->get_json_params();
		foreach ( [ 'billing', 'shipping' ] as $type ) {
			if ( is_array( $data[ $type ] ?? null ) && is_string( $data[ $type ]['country'] ?? null ) ) {
				$country                   = strtoupper( $data[ $type ]['country'] );
				$data[ $type ]['country']  = $country;
				$data[ $type ]['state']    = Countries::state_code( $country, is_string( $data[ $type ]['state'] ?? null ) ? $data[ $type ]['state'] : '' );
				$data[ $type ]['postcode'] = Countries::normalize_postcode( is_string( $data[ $type ]['postcode'] ?? null ) ? $data[ $type ]['postcode'] : '' );
			}
		}
		// The shopper agreed with the terms line under the buttons.
		$data['terms']                     = true;
		$data['ship_to_different_address'] = is_array( $data['shipping'] ?? null ) && $data['shipping'] !== [];
		$input                             = CheckoutRequest::from_array( $data, get_current_user_id(), ClientIp::get() );
		return $this->run(
			function ( CheckoutService $checkout ) use ( $request, $input ): array {
				$single = $this->single( $request );
				return ( $single !== null ? $checkout->place_single( $input, $single ) : $checkout->place_order( $input ) )->to_array();
			},
			201
		);
	}

	/**
	 * The one-product cart for `source=product`, else null.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	private function single( WP_REST_Request $request ): ?Cart {
		if ( $request->get_param( 'source' ) !== 'product' ) {
			return null;
		}
		$product    = $request->get_param( 'product' );
		$product    = is_array( $product ) ? $product : [];
		$attributes = [];
		foreach ( is_array( $product['attributes'] ?? null ) ? $product['attributes'] : [] as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$attributes[ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		$options = $product['options'] ?? null;
		return Plugin::instance()->cart()->cart()->single(
			get_current_user_id(),
			(int) ( $product['product_id'] ?? 0 ),
			(int) ( $product['variant_id'] ?? 0 ),
			(int) ( $product['quantity'] ?? 1 ),
			$attributes,
			is_array( $options ) && $options !== [] ? ProductOptions::posted( $options ) : []
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
