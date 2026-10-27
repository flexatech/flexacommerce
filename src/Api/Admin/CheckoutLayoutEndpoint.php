<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Checkout\CheckoutPages;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Support\Countries;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout builder (ADR-031, ADR-032). PUT takes the whole layout,
 * checks it and answers with it normalised, so the screen shows what was
 * stored. POST preview keeps an unsaved layout for one hour and answers
 * with the checkout URL that shows it.
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
		register_rest_route(
			self::NAMESPACE,
			'/admin/checkout-layout/preview',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			CheckoutLayout::store_draft( get_current_user_id(), (array) $request->get_json_params() );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( [ 'url' => add_query_arg( CheckoutLayout::PREVIEW_ARG, wp_create_nonce( CheckoutLayout::PREVIEW_NONCE ), ( new CheckoutPages() )->checkout_url() ) ], 200 );
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
				'default'   => CheckoutLayout::default()->to_array(),
				'labels'    => self::labels(),
				'countries' => self::countries( $layout ),
			],
			200
		);
	}

	/**
	 * The countries a field can be shown for: the ones the store sells to,
	 * and any a field names that the store no longer sells to.
	 *
	 * @return list<array{code:string,name:string}>
	 */
	private static function countries( CheckoutLayout $layout ): array {
		$codes = Countries::allowed();
		foreach ( $layout->to_array()['fields'] as $entries ) {
			foreach ( $entries as $entry ) {
				foreach ( $entry['when']['countries'] ?? [] as $code ) {
					$codes[ $code ] ??= Countries::name( $code );
				}
			}
		}
		$out = [];
		foreach ( $codes as $code => $name ) {
			$out[] = [
				'code' => (string) $code,
				'name' => $name,
			];
		}
		return $out;
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
