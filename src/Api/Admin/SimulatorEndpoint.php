<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Checkout\Simulator;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The rule simulator (ADR-046): prices a made-up cart the way checkout
 * would and says what every rule and coupon did. Nothing is stored.
 */
final class SimulatorEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/simulator',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/simulator/customers',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'customers' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'search' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);
	}

	/**
	 * Accounts to test as, by name, login or email, with their roles: any
	 * account, ordered before or not, since a role is what a rule names.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function customers( WP_REST_Request $request ): WP_REST_Response {
		$search = trim( (string) $request->get_param( 'search' ) );
		$args   = [
			'number'  => 10,
			'orderby' => 'display_name',
			'fields'  => 'all',
		];
		if ( $search !== '' ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}
		$names = wp_roles()->get_names();
		$rows  = [];
		foreach ( ( new \WP_User_Query( $args ) )->get_results() as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$rows[] = [
				'id'    => $user->ID,
				'name'  => $user->display_name,
				'email' => $user->user_email,
				'roles' => array_values( array_map( static fn ( string $role ): string => translate_user_role( $names[ $role ] ?? $role ), $user->roles ) ),
			];
		}
		return new WP_REST_Response( $rows, 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body  = (array) $request->get_json_params();
		$lines = [];
		foreach ( array_slice( (array) ( $body['lines'] ?? [] ), 0, Simulator::MAX_LINES ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id       = absint( $entry['id'] ?? 0 );
			$quantity = max( 1, absint( $entry['quantity'] ?? 1 ) );
			if ( $id <= 0 ) {
				continue;
			}
			if ( ( $entry['type'] ?? 'product' ) === 'variant' ) {
				$variant = Plugin::instance()->catalog()->variants()->find( $id );
				$lines[] = [
					'product_id' => $variant !== null ? $variant->product_id : 0,
					'variant_id' => $id,
					'quantity'   => $quantity,
				];
				continue;
			}
			$lines[] = [
				'product_id' => $id,
				'quantity'   => $quantity,
			];
		}
		$codes = [];
		foreach ( (array) ( $body['coupons'] ?? [] ) as $code ) {
			if ( is_string( $code ) ) {
				$codes[] = sanitize_text_field( $code );
			}
		}

		try {
			$result = Plugin::instance()->checkout()->simulator()->run(
				[
					'lines'          => $lines,
					'customer_id'    => absint( $body['customer_id'] ?? 0 ),
					'country'        => sanitize_text_field( (string) ( $body['country'] ?? '' ) ),
					'state'          => sanitize_text_field( (string) ( $body['state'] ?? '' ) ),
					'postcode'       => sanitize_text_field( (string) ( $body['postcode'] ?? '' ) ),
					'city'           => sanitize_text_field( (string) ( $body['city'] ?? '' ) ),
					'shipping_rate'  => sanitize_text_field( (string) ( $body['shipping_rate'] ?? '' ) ),
					'payment_method' => sanitize_key( (string) ( $body['payment_method'] ?? '' ) ),
					'coupons'        => $codes,
					'final'          => (bool) ( $body['final'] ?? false ),
				]
			);
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( $result, 200 );
	}
}
