<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Orders\CustomerRepository;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only customers list: users with orders and guest emails, with their
 * order count and what they spent in the store currency. Anyone who can see
 * orders can see it, since it shows nothing the orders do not.
 */
final class CustomersEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/customers',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
					'args'                => [
						'type'     => [
							'type' => 'string',
							'enum' => CustomerRepository::TYPES,
						],
						'search'   => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'page'     => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						],
						'orderby'  => [
							'type'    => 'string',
							'enum'    => CustomerRepository::ORDER_BY,
							'default' => 'last_order',
						],
						'order'    => [
							'type'    => 'string',
							'enum'    => [ 'asc', 'desc' ],
							'default' => 'desc',
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$currency = Settings::string( 'currency' );
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = Plugin::instance()->orders()->customers()->search(
			$currency,
			(string) $request->get_param( 'type' ),
			trim( (string) $request->get_param( 'search' ) ),
			(string) $request->get_param( 'orderby' ),
			(string) $request->get_param( 'order' ),
			(int) $request->get_param( 'page' ),
			$per_page
		);

		$ids = array_values( array_filter( array_column( $result['items'], 'customer_id' ) ) );
		if ( $ids !== [] ) {
			cache_users( $ids );
		}
		$items = [];
		foreach ( $result['items'] as $row ) {
			$user    = $row['customer_id'] > 0 ? get_userdata( $row['customer_id'] ) : false;
			$items[] = [
				'customer_id' => $row['customer_id'],
				'registered'  => $user instanceof \WP_User,
				'email'       => $user instanceof \WP_User ? $user->user_email : $row['email'],
				'name'        => $row['name'] !== '' ? $row['name'] : ( $user instanceof \WP_User ? $user->display_name : '' ),
				'orders'      => $row['orders'],
				'spent'       => $row['spent'],
				'currency'    => $currency,
				'first_order' => str_replace( ' ', 'T', $row['first_order'] ) . 'Z',
				'last_order'  => str_replace( ' ', 'T', $row['last_order'] ) . 'Z',
				'edit_url'    => $user instanceof \WP_User && current_user_can( 'edit_user', $user->ID ) ? get_edit_user_link( $user->ID ) : '',
			];
		}

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}
}
