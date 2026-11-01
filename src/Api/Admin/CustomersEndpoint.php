<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\GiftCards\GiftCardActivity;
use Flexa\Commerce\Orders\CustomerRepository;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Capabilities;
use Flexa\Commerce\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Customers list: users with orders and guest emails, with their order count
 * and what they spent in the store currency. Anyone who can see orders can
 * see it, since it shows nothing the orders do not. It edits an account's
 * tax-exempt flag, which needs the store manager capability, and its store
 * credit (ADR-072), which needs the order edit capability like a gift card.
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
		register_rest_route(
			self::NAMESPACE,
			'/admin/customers/(?P<id>\d+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'id'         => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
						'tax_exempt' => [
							'type'     => 'boolean',
							'required' => true,
						],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/customers/(?P<id>\d+)/credit',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'credit' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'adjust_credit' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => [
						'id'     => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
						'amount' => [
							'type'     => 'integer',
							'required' => true,
						],
						'note'   => [
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 500,
							'required'  => true,
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
		$profile    = new CustomerProfile();
		$can_manage = Capabilities::can_manage();
		$credits    = Plugin::instance()->gift_cards()->repository()->for_users( array_map( 'intval', $ids ) );
		$items      = [];
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
				'tax_exempt'  => $user instanceof \WP_User && $profile->tax_exempt( $user->ID ),
				'can_exempt'  => $user instanceof \WP_User && $can_manage,
				'credit'      => $user instanceof \WP_User ? ( $credits[ $user->ID ]->balance ?? 0 ) : null,
			];
		}

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = get_userdata( (int) $request->get_param( 'id' ) );
		if ( ! $user instanceof \WP_User || ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) ) {
			return new WP_Error( 'flexacommerce_customer_not_found', __( 'No such account.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		$profile = new CustomerProfile();
		$profile->set_tax_exempt( $user->ID, (bool) $request->get_param( 'tax_exempt' ) );
		return new WP_REST_Response(
			[
				'customer_id' => $user->ID,
				'tax_exempt'  => $profile->tax_exempt( $user->ID ),
			],
			200
		);
	}

	/**
	 * A customer's store credit: the balance and its history. An account
	 * that never had credit shows a zero balance and no history.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function credit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = $this->account( (int) $request->get_param( 'id' ) );
		if ( $user instanceof WP_Error ) {
			return $user;
		}
		return new WP_REST_Response( $this->credit_view( $user ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function adjust_credit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = $this->account( (int) $request->get_param( 'id' ) );
		if ( $user instanceof WP_Error ) {
			return $user;
		}
		$note = trim( (string) $request->get_param( 'note' ) );
		if ( $note === '' ) {
			return new WP_Error( 'flexacommerce_credit_note', __( 'Enter a reason. The customer sees it in their account.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		try {
			Plugin::instance()->gift_cards()->adjust_credit( $user->ID, (int) $request->get_param( 'amount' ), $note, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'flexacommerce_credit_invalid', $e->getMessage(), [ 'status' => 400 ] );
		}
		return new WP_REST_Response( $this->credit_view( $user ), 200 );
	}

	private function account( int $id ): \WP_User|WP_Error {
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User || ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) ) {
			return new WP_Error( 'flexacommerce_customer_not_found', __( 'No such account.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		return $user;
	}

	/** @return array<string,mixed> */
	private function credit_view( \WP_User $user ): array {
		$service  = Plugin::instance()->gift_cards();
		$card     = $service->credit_account( $user->ID );
		$names    = [];
		$activity = [];
		foreach ( $card !== null ? $service->repository()->activity( $card->id ) : [] as $row ) {
			if ( $row->type === GiftCardActivity::ISSUED && $row->amount === 0 ) {
				continue; // The account opening, nothing the merchant needs to see.
			}
			$name = null;
			if ( $row->user_id !== null ) {
				if ( ! array_key_exists( $row->user_id, $names ) ) {
					$found                  = get_userdata( $row->user_id );
					$names[ $row->user_id ] = $found instanceof \WP_User ? $found->display_name : '';
				}
				$name = $names[ $row->user_id ];
			}
			$activity[] = [
				'user'       => $name,
				'created_at' => str_replace( ' ', 'T', $row->created_at ) . 'Z',
			] + $row->to_array();
		}
		return [
			'customer_id' => $user->ID,
			'balance'     => $card->balance ?? 0,
			'currency'    => $card->currency ?? Settings::string( 'currency' ),
			'activity'    => $activity,
		];
	}
}
