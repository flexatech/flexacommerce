<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Checkout\AdminDraft;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\InvalidOrderRequest;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSet;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Countries;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The order editor (ADR-061): price a draft, create an order from it,
 * change an unpaid order's lines, change any order's addresses and send
 * the customer the order's details. Prices come from AdminCheckout, the
 * code a shopper's cart is priced with; responses about an order are the
 * order detail of OrdersEndpoint.
 */
final class OrderEditorEndpoint extends Endpoint {
	public function register_routes(): void {
		$id = [
			'id' => [
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			],
		];

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/quote',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'quote' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/product-form',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'product_form' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => [
						'product_id' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/customer-addresses',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'customer_addresses' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => [
						'customer_id' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/draft',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'draft' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $id,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $id,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/addresses',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'addresses' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $id,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/details-email',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'details_email' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $id,
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( Plugin::instance()->checkout()->admin()->quote( AdminDraft::from_array( (array) $request->get_json_params() ) ), 200 );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		try {
			$order = Plugin::instance()->checkout()->admin()->place( AdminDraft::from_array( $body ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		// Sent first, so the answer's history shows the note.
		$sent   = ! empty( $body['send_details'] ) ? $this->send_details( $order ) : null;
		$detail = $this->detail( Plugin::instance()->orders()->service()->get( $order->id ) );
		if ( $sent !== null ) {
			$detail['details_sent'] = $sent;
		}
		return new WP_REST_Response( $detail, 201 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function draft( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$order = Plugin::instance()->orders()->service()->get( (int) $request->get_param( 'id' ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( [ 'id' => $order->id ] + Plugin::instance()->checkout()->admin()->draft_of( $order ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$order = Plugin::instance()->checkout()->admin()->edit( (int) $request->get_param( 'id' ), AdminDraft::from_array( (array) $request->get_json_params() ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( $this->detail( $order ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function addresses( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body     = (array) $request->get_json_params();
		$billing  = AdminDraft::address( 'billing', is_array( $body['billing'] ?? null ) ? $body['billing'] : [] );
		$shipping = is_array( $body['shipping'] ?? null ) ? AdminDraft::address( 'shipping', $body['shipping'] ) : null;
		if ( $shipping !== null && $shipping->address_1 . $shipping->city . $shipping->country === '' ) {
			$shipping = null;
		}
		try {
			foreach ( [ $billing, $shipping ] as $address ) {
				if ( $address !== null && $address->country !== '' && ! Countries::exists( $address->country ) ) {
					throw new InvalidOrderRequest( __( 'Choose a country from the list.', 'flexacommerce' ), $address->type . '.country' );
				}
			}
			if ( $billing->email !== '' && ! is_email( $billing->email ) ) {
				throw new InvalidOrderRequest( __( 'Enter a valid email address.', 'flexacommerce' ), 'billing.email' );
			}
			$order = Plugin::instance()->orders()->service()->update_addresses(
				(int) $request->get_param( 'id' ),
				$billing,
				$shipping,
				TransitionContext::admin( __( 'Addresses changed by the admin.', 'flexacommerce' ) )
			);
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( $this->detail( $order ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function details_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$order = Plugin::instance()->orders()->service()->get( (int) $request->get_param( 'id' ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		if ( $order->customer_email === '' && (string) $order->billing?->email === '' ) {
			return new WP_Error( 'flexacommerce_no_email', __( 'The order has no email address to send to.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		if ( ! $this->send_details( $order ) ) {
			return new WP_Error( 'flexacommerce_email_failed', __( 'The email could not be sent. The log has the reason.', 'flexacommerce' ), [ 'status' => 500 ] );
		}
		return new WP_REST_Response( $this->detail( Plugin::instance()->orders()->service()->get( $order->id ) ), 200 );
	}

	/**
	 * What the line configurator offers for a product: its variations and
	 * attributes as the product page shows them, and its option fields.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function product_form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product = Plugin::instance()->catalog()->products()->find( (int) $request->get_param( 'product_id' ) );
		if ( $product === null ) {
			return new WP_Error( 'flexacommerce_product_not_found', __( 'The product was not found.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		$sets = [];
		foreach ( ProductOptions::in_use() ? Plugin::instance()->product_options()->sets_for( $product ) : [] as $set ) {
			$sets[] = self::option_set( $set );
		}
		return new WP_REST_Response(
			[
				'name'    => $product->title,
				'sku'     => (string) $product->base->sku,
				'options' => $sets,
			] + Plugin::instance()->cart()->purchase_options()->for_product( $product ),
			200
		);
	}

	/**
	 * The customer's saved addresses, to fill the editor's forms. Without a
	 * saved billing address the account's name and email stand in.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function customer_addresses( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = get_userdata( (int) $request->get_param( 'customer_id' ) );
		if ( ! $user instanceof \WP_User ) {
			return new WP_Error( 'flexacommerce_customer_not_found', __( 'The customer was not found.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		$profile = new CustomerProfile();
		$billing = $profile->address( $user->ID, 'billing' )?->to_array() ?? [
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
			'email'      => $user->user_email,
		];
		if ( ( $billing['email'] ?? '' ) === '' ) {
			$billing['email'] = $user->user_email;
		}
		return new WP_REST_Response(
			[
				'billing'  => $billing,
				'shipping' => $profile->address( $user->ID, 'shipping' )?->to_array(),
			],
			200
		);
	}

	/** @return array<string,mixed> */
	private function detail( Order $order ): array {
		return ( new OrdersEndpoint() )->detail( $order );
	}

	private function send_details( Order $order ): bool {
		$sent = Plugin::instance()->notifications()->notifier()->send_now( 'customer_invoice', $order->view() );
		if ( $sent ) {
			Plugin::instance()->orders()->service()->add_note( $order->id, __( 'Order details sent to the customer.', 'flexacommerce' ), false, TransitionContext::admin() );
		}
		return $sent;
	}

	/** @return array<string,mixed> */
	private static function option_set( OptionSet $set ): array {
		$fields = [];
		foreach ( $set->fields as $field ) {
			$fields[] = [ 'key' => ProductOptions::key( $set->id, $field->id ) ] + $field->to_array();
		}
		return [
			'id'     => $set->id,
			'name'   => $set->name,
			'fields' => array_values( array_filter( $fields, static fn ( array $f ): bool => $f['type'] !== OptionField::HEADING || $f['label'] !== '' ) ),
		];
	}
}
