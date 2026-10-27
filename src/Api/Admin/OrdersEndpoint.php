<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderFields;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderQuery;
use Flexa\Commerce\Orders\OrderStateMachine;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\RefundLine;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin order API (doc 02 §6.2): the list over indexed columns only, the
 * detail with lines, history and transactions, and the actions an admin
 * takes on an order. Every action goes through OrderService or
 * PaymentService, so the state machine and the locks apply as everywhere
 * else. Amounts are integers in the order currency's minor unit.
 */
final class OrdersEndpoint extends Endpoint {
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
			'/admin/orders',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
					'args'                => self::collection_args(),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/counts',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'counts' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
					'args'                => $id,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/transitions',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'transition' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $id + [
						'status'          => [
							'type'     => 'string',
							'enum'     => OrderStatus::ALL,
							'required' => true,
						],
						'note'            => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'notify_customer' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/notes',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'note' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $id + [
						'note'             => [
							'type'     => 'string',
							'required' => true,
						],
						'customer_visible' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/payments',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'payment' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $id + [
						'amount' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
						'note'   => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/refunds',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'refund' ],
					'permission_callback' => [ $this, 'refund_orders_permission' ],
					'args'                => $id + [
						'amount'      => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
						'lines'       => [
							'type'    => 'array',
							'default' => [],
							'items'   => [
								'type'       => 'object',
								'properties' => [
									'item_id' => [
										'type'    => 'integer',
										'minimum' => 1,
									],
									'qty'     => [
										'type'    => 'integer',
										'minimum' => 0,
									],
									'amount'  => [
										'type'    => 'integer',
										'minimum' => 0,
									],
									'restock' => [ 'type' => 'boolean' ],
								],
							],
						],
						'reason'      => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'via_gateway' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/attention',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'resolve' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $id,
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$query  = OrderQuery::from_params( $request->get_params() );
		$result = Plugin::instance()->orders()->orders()->search( $query );

		$response = new WP_REST_Response( array_map( [ $this, 'summary' ], $result['orders'] ), 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / $query->per_page ) );
		return $response;
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function counts( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$orders = Plugin::instance()->orders()->orders();
		$counts = $orders->count_by_status();
		$status = [];
		foreach ( OrderStatus::ALL as $name ) {
			$status[ $name ] = $counts[ $name ] ?? 0;
		}
		return new WP_REST_Response(
			[
				'all'             => array_sum( $status ),
				'status'          => $status,
				'needs_attention' => $orders->count_needs_attention(),
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run( $request, static fn ( int $id ): int => $id );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function transition( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$to      = (string) $request->get_param( 'status' );
		$note    = (string) $request->get_param( 'note' );
		$context = (bool) $request->get_param( 'notify_customer' ) ? [ 'notify_customer' => true ] : [];
		return $this->run(
			$request,
			static function ( int $id ) use ( $to, $note, $context ): int {
				$ctx = new TransitionContext( 'admin', get_current_user_id(), $note, false, $context );
				Plugin::instance()->orders()->service()->transition( $id, $to, $ctx );
				return $id;
			}
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function note( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$note    = (string) $request->get_param( 'note' );
		$visible = (bool) $request->get_param( 'customer_visible' );
		return $this->run(
			$request,
			static function ( int $id ) use ( $note, $visible ): int {
				Plugin::instance()->orders()->service()->add_note( $id, $note, $visible, TransitionContext::admin() );
				return $id;
			},
			201
		);
	}

	/**
	 * Money received outside the site: cash on delivery, a bank transfer.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function payment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$amount = (int) $request->get_param( 'amount' );
		$note   = (string) $request->get_param( 'note' );
		return $this->run(
			$request,
			static function ( int $id ) use ( $amount, $note ): int {
				Plugin::instance()->payments()->service()->record_manual_payment( $id, $amount, $note, TransitionContext::admin() );
				return $id;
			},
			201
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function refund( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$amount  = (int) $request->get_param( 'amount' );
		$lines   = RefundLine::from_array( (array) $request->get_param( 'lines' ) );
		$reason  = (string) $request->get_param( 'reason' );
		$gateway = (bool) $request->get_param( 'via_gateway' );
		return $this->run(
			$request,
			static function ( int $id ) use ( $amount, $lines, $reason, $gateway ): int {
				Plugin::instance()->payments()->service()->refund( $id, $amount, $lines, $reason, $gateway, TransitionContext::admin() );
				return $id;
			},
			201
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function resolve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			$request,
			static function ( int $id ): int {
				Plugin::instance()->orders()->service()->clear_attention( $id, TransitionContext::admin() );
				return $id;
			}
		);
	}

	/** @return array<string,mixed> one row of the list; items are not loaded */
	public function summary( Order $order ): array {
		return [
			'id'                   => $order->id,
			'number'               => $order->order_number,
			'status'               => $order->status,
			'payment_status'       => $order->payment_status,
			'needs_attention'      => $order->needs_attention,
			'currency'             => $order->currency,
			'total'                => $order->total,
			'amount_paid'          => $order->amount_paid,
			'amount_refunded'      => $order->amount_refunded,
			'customer'             => [
				'id'    => $order->customer_id,
				'email' => $order->customer_email,
				'name'  => $order->customer_name,
			],
			'payment_method'       => $order->payment_method,
			'payment_method_title' => $order->payment_method_title,
			'created_at'           => self::date( $order->created_at ),
		];
	}

	/**
	 * Load the order after the action, so the response is the state the
	 * action left behind. An unknown id is a 404 before any action runs.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 * @param callable(int):int                    $action
	 */
	private function run( WP_REST_Request $request, callable $action, int $status = 200 ): WP_REST_Response|WP_Error {
		$service = Plugin::instance()->orders()->service();
		try {
			$id = $service->get( (int) $request->get_param( 'id' ) )->id;
			$action( $id );
			return new WP_REST_Response( $this->detail( $service->get( $id ) ), $status );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
	}

	/** @return array<string,mixed> */
	private function detail( Order $order ): array {
		$payments = Plugin::instance()->payments();
		$gateway  = $order->payment_method !== '' ? $payments->gateways()->get( $order->payment_method ) : null;
		$pages    = Plugin::instance()->checkout()->pages();

		return $this->summary( $order ) + [
			'subtotal'       => $order->subtotal,
			'discount_total' => $order->discount_total,
			'shipping_total' => $order->shipping_total,
			'fee_total'      => $order->fee_total,
			'tax_total'      => $order->tax_total,
			'refundable'     => $order->refundable(),
			'balance_due'    => $order->balance_due(),
			'customer_note'  => $order->customer_note,
			'fields'         => array_map(
				static fn ( array $answer ): array => [
					'label' => $answer['label'],
					'value' => $answer['value'],
				],
				OrderFields::for_order( Plugin::instance()->orders()->orders(), $order->id )
			),
			'customer_ip'    => $order->customer_ip,
			'created_via'    => $order->created_via,
			'paid_at'        => self::date( $order->paid_at ),
			'completed_at'   => self::date( $order->completed_at ),
			'cancelled_at'   => self::date( $order->cancelled_at ),
			'anonymized'     => $order->anonymized_at !== null,
			'billing'        => self::address( $order->billing ),
			'shipping'       => self::address( $order->shipping ),
			'items'          => array_map( [ self::class, 'item' ], $order->items ),
			'transitions'    => OrderStateMachine::targets( $order->status ),
			'gateway'        => [
				'id'      => $order->payment_method,
				'title'   => $order->payment_method_title,
				'refunds' => $gateway !== null && $gateway->supports( 'refunds' ),
			],
			'can'            => [
				'edit'   => Capabilities::can_edit_orders(),
				'refund' => Capabilities::can_refund_orders(),
			],
			'links'          => [
				'view' => $pages->confirmation_url( $order ),
				'pay'  => $order->status === OrderStatus::PENDING && $order->balance_due() > 0 ? $pages->pay_url( $order ) : '',
			],
			'transactions'   => array_map( [ self::class, 'transaction' ], $payments->transactions()->for_order( $order->id ) ),
			'history'        => self::history( $order->id ),
		];
	}

	/** @return array<string,mixed> */
	private static function item( OrderItem $item ): array {
		return [
			'id'             => $item->id,
			'type'           => $item->type,
			'product_id'     => $item->product_id,
			'variant_id'     => $item->variant_id,
			'code'           => $item->code,
			'name'           => $item->name,
			'sku'            => $item->sku,
			'attributes'     => $item->attributes,
			'quantity'       => $item->quantity,
			'unit_price'     => $item->unit_price,
			'subtotal'       => $item->subtotal,
			'tax_total'      => $item->tax_total,
			'total'          => $item->total,
			'refunded_qty'   => $item->refunded_qty,
			'refunded_total' => $item->refunded_total,
			'restocked_qty'  => $item->restocked_qty,
			'stock_managed'  => $item->stock_id() > 0,
		];
	}

	/** @return array<string,mixed> */
	private static function transaction( Transaction $txn ): array {
		return [
			'id'                => $txn->id,
			'type'              => $txn->type,
			'status'            => $txn->status,
			'method'            => $txn->method,
			'gateway'           => $txn->gateway,
			'amount'            => $txn->amount,
			'gateway_reference' => $txn->gateway_reference,
			'failure_message'   => $txn->failure_message,
			'created_at'        => self::date( $txn->created_at ),
			'completed_at'      => self::date( $txn->completed_at ),
		];
	}

	/** @return list<array<string,mixed>> newest first, with the admin's display name */
	private static function history( int $order_id ): array {
		$names = [];
		$out   = [];
		foreach ( Plugin::instance()->orders()->history()->for_order( $order_id ) as $row ) {
			$actor = $row['actor_id'];
			if ( $actor !== null && ! isset( $names[ $actor ] ) ) {
				$user            = get_userdata( $actor );
				$names[ $actor ] = $user !== false ? $user->display_name : '';
			}
			$out[] = [
				'id'               => $row['id'],
				'type'             => $row['type'],
				'from_status'      => $row['from_status'],
				'to_status'        => $row['to_status'],
				'message'          => $row['message'],
				'customer_visible' => $row['customer_visible'],
				'actor_type'       => $row['actor_type'],
				'actor_name'       => $actor !== null ? $names[ $actor ] : '',
				'created_at'       => self::date( $row['created_at'] ),
			];
		}
		return $out;
	}

	/** @return array<string,string>|null */
	private static function address( ?AddressView $address ): ?array {
		return $address?->to_array();
	}

	/** Stored dates are UTC without a zone; the client gets ISO 8601. */
	private static function date( ?string $value ): ?string {
		return $value === null || $value === '' ? null : str_replace( ' ', 'T', $value ) . 'Z';
	}

	/** @return array<string,array<string,mixed>> */
	private static function collection_args(): array {
		return [
			'status'          => [
				'type' => 'string',
				'enum' => OrderStatus::ALL,
			],
			'payment_status'  => [
				'type' => 'string',
				'enum' => OrderStatus::PAYMENT_STATUSES,
			],
			'needs_attention' => [ 'type' => 'boolean' ],
			'customer_id'     => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'search'          => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'page'            => [
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			],
			'per_page'        => [
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			],
			'orderby'         => [
				'type'    => 'string',
				'enum'    => OrderQuery::ORDER_BY,
				'default' => 'created_at',
			],
			'order'           => [
				'type'    => 'string',
				'enum'    => [ 'asc', 'desc' ],
				'default' => 'desc',
			],
		];
	}
}
