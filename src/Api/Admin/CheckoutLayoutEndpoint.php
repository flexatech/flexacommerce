<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Contracts\CommerceException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout builder (ADR-031). PUT takes the whole layout, checks it
 * and answers with it normalised, so the screen shows what was stored.
 */
final class CheckoutLayoutEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/checkout-layout',
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
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'reset' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return self::payload( CheckoutLayout::current() );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return self::payload( CheckoutLayout::save( (array) $request->get_json_params() ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function reset( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return self::payload( CheckoutLayout::reset() );
	}

	private static function payload( CheckoutLayout $layout ): WP_REST_Response {
		return new WP_REST_Response(
			$layout->to_array() + [
				'default' => CheckoutLayout::default()->to_array()['fields'],
				'labels'  => self::labels(),
			],
			200
		);
	}

	/**
	 * The labels core fields get when the merchant leaves theirs empty.
	 *
	 * @return array<string,string>
	 */
	private static function labels(): array {
		return [
			'email'         => __( 'Email address', 'flexacommerce' ),
			'first_name'    => __( 'First name', 'flexacommerce' ),
			'last_name'     => __( 'Last name', 'flexacommerce' ),
			'company'       => __( 'Company', 'flexacommerce' ),
			'country'       => __( 'Country / Region', 'flexacommerce' ),
			'address_1'     => __( 'Street address', 'flexacommerce' ),
			'address_2'     => __( 'Apartment, suite, unit', 'flexacommerce' ),
			'city'          => __( 'Town / City', 'flexacommerce' ),
			'state'         => __( 'State / County', 'flexacommerce' ),
			'postcode'      => __( 'Postcode', 'flexacommerce' ),
			'phone'         => __( 'Phone', 'flexacommerce' ),
			'customer_note' => __( 'Order notes', 'flexacommerce' ),
		];
	}
}
