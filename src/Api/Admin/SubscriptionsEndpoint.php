<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Subscriptions\Subscription;
use Flexa\Commerce\Subscriptions\SubscriptionService;
use Flexa\Commerce\Subscriptions\SubscriptionStatus;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The Subscriptions screen (ADR-073): the list, one subscription with its
 * orders and history, the merchant's actions, and a new next payment day.
 * The day comes in store-local (YYYY-MM-DD) and keeps the time of day the
 * payment had.
 */
final class SubscriptionsEndpoint extends Endpoint {
	private const ACTIONS = [ 'cancel', 'cancel_end', 'reactivate', 'hold', 'resume', 'renew' ];

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/subscriptions',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
					'args'                => [
						'search'   => [
							'type'    => 'string',
							'default' => '',
						],
						'status'   => [
							'type'    => 'string',
							'enum'    => [ '', ...SubscriptionStatus::ALL ],
							'default' => '',
						],
						'customer' => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
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
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/subscriptions/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => [
						'next_payment_on' => [
							'type'     => 'string',
							'pattern'  => '^\d{4}-\d{2}-\d{2}$',
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/subscriptions/(?P<id>\d+)/actions',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'act' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => [
						'action' => [
							'type'     => 'string',
							'enum'     => self::ACTIONS,
							'required' => true,
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $this->service()->repository()->page(
			trim( (string) $request->get_param( 'search' ) ),
			(string) $request->get_param( 'status' ),
			(int) $request->get_param( 'page' ),
			$per_page,
			(int) $request->get_param( 'customer' )
		);
		return new WP_REST_Response(
			[
				'items'  => array_map( fn ( Subscription $row ): array => $this->item( $row ), $result['rows'] ),
				'total'  => $result['total'],
				'pages'  => (int) ceil( $result['total'] / max( 1, $per_page ) ),
				'counts' => (object) $this->service()->repository()->counts(),
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->service()->find( (int) $request->get_param( 'id' ) );
		return $row !== null ? new WP_REST_Response( $this->detail( $row ), 200 ) : self::not_found();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->service()->find( (int) $request->get_param( 'id' ) );
		if ( $row === null ) {
			return self::not_found();
		}
		$day  = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $request->get_param( 'next_payment_on' ), wp_timezone() );
		$time = $row->next_payment_at !== null ? ( new \DateTimeImmutable( $row->next_payment_at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() ) : null;
		if ( $day === false ) {
			return self::refused( __( 'Choose a date in the future.', 'flexacommerce' ), 'next_payment_on' );
		}
		if ( $time !== null ) {
			$day = $day->setTime( (int) $time->format( 'G' ), (int) $time->format( 'i' ), (int) $time->format( 's' ) );
		}
		try {
			$row = $this->service()->reschedule( $row->id, $day->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), TransitionContext::admin() );
		} catch ( \InvalidArgumentException $e ) {
			return self::refused( $e->getMessage(), 'next_payment_on' );
		}
		return new WP_REST_Response( $this->detail( $row ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function act( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$service = $this->service();
		if ( $service->find( $id ) === null ) {
			return self::not_found();
		}
		$ctx = TransitionContext::admin();
		try {
			match ( (string) $request->get_param( 'action' ) ) {
				'cancel'     => $service->cancel( $id, false, $ctx ),
				'cancel_end' => $service->cancel( $id, true, $ctx ),
				'reactivate' => $service->reactivate( $id, $ctx ),
				'hold'       => $service->set_hold( $id, true, $ctx ),
				'resume'     => $service->set_hold( $id, false, $ctx ),
				default      => $service->renew( $id, $ctx ),
			};
		} catch ( \InvalidArgumentException $e ) {
			return self::refused( $e->getMessage(), 'action' );
		}
		// Read again: the action changed the row.
		$row = $service->repository()->find( $id );
		return $row !== null ? new WP_REST_Response( $this->detail( $row ), 200 ) : self::not_found();
	}

	private function service(): SubscriptionService {
		return Plugin::instance()->subscriptions();
	}

	/** @return array<string,mixed> */
	private function item( Subscription $row ): array {
		$user = $row->customer_id > 0 ? get_userdata( $row->customer_id ) : false;
		return $row->to_array() + [
			'price'         => $this->service()->price_text( $row ),
			'customer_name' => $user instanceof \WP_User ? $user->display_name : trim( ( $row->billing->first_name ?? '' ) . ' ' . ( $row->billing->last_name ?? '' ) ),
		];
	}

	/** @return array<string,mixed> */
	private function detail( Subscription $row ): array {
		$orders = [];
		$repo   = Plugin::instance()->orders()->orders();
		foreach ( array_reverse( $this->service()->repository()->orders( $row->id ) ) as $link ) {
			$order = $repo->find( $link['order_id'] );
			if ( $order === null ) {
				continue;
			}
			$orders[] = [
				'id'           => $order->id,
				'number'       => $order->order_number,
				'relation'     => $link['relation'],
				'status'       => $order->status,
				'status_label' => OrderStatus::label( $order->status ),
				'total'        => $order->total,
				'created_at'   => str_replace( ' ', 'T', $order->created_at ) . 'Z',
			];
		}
		$events = [];
		foreach ( $this->service()->repository()->events( $row->id ) as $event ) {
			$who      = $event['actor_id'] !== null ? get_userdata( $event['actor_id'] ) : false;
			$events[] = [
				'actor'      => $who instanceof \WP_User ? $who->display_name : '',
				'created_at' => str_replace( ' ', 'T', $event['created_at'] ) . 'Z',
			] + $event;
		}
		$snapshot = $row->snapshot;
		$unpaid   = $this->service()->unpaid_renewal( $row );
		return $this->item( $row ) + [
			'items'           => $snapshot !== null ? array_map(
				static fn ( $item ): array => [
					'name'       => $item->name,
					'quantity'   => $item->quantity,
					'total'      => $item->total,
					'product_id' => $item->product_id,
				],
				array_values( array_filter( $snapshot->products(), static fn ( $item ): bool => ! isset( $item->meta['bundled_by'] ) ) )
			) : [],
			'subtotal'        => $snapshot->subtotal ?? 0,
			'shipping_total'  => $snapshot->shipping_total ?? 0,
			'tax_total'       => $snapshot->tax_total ?? 0,
			'billing'         => self::address( $row->billing ),
			'shipping'        => self::address( $row->shipping ),
			'orders'          => $orders,
			'events'          => $events,
			'unpaid_order_id' => $unpaid?->id,
			'next_payment_on' => $row->next_payment_at !== null ? ( new \DateTimeImmutable( $row->next_payment_at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' ) : '',
		];
	}

	/** @return array<string,string>|null */
	private static function address( ?AddressView $address ): ?array {
		return $address?->to_array();
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'flexacommerce_subscription_not_found', __( 'This subscription does not exist.', 'flexacommerce' ), [ 'status' => 404 ] );
	}

	private static function refused( string $message, string $field ): WP_Error {
		return new WP_Error(
			'flexacommerce_invalid_subscription',
			$message,
			[
				'status' => 400,
				'field'  => $field,
			]
		);
	}
}
