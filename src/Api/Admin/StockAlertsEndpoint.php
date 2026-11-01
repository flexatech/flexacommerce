<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\StockAlerts\StockAlert;
use Flexa\Commerce\StockAlerts\StockAlertSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Back-in-stock signups (ADR-065): the list, the products most asked for,
 * the email settings and a test send. Rows hold shopper addresses, so
 * reading them takes the order capability.
 */
final class StockAlertsEndpoint extends Endpoint {
	private const STATS_DAYS = 30;
	private const WANTED     = 10;

	/** @var array<int,?Product> */
	private array $products = [];

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/stock-alerts',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
					'args'                => [
						'status'     => [
							'type'    => 'string',
							'enum'    => [ '', ...StockAlert::STATUSES ],
							'default' => '',
						],
						'product_id' => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
						'page'       => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'per_page'   => [
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
			'/admin/stock-alerts/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/stock-alerts/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'settings' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'enabled' => [ 'type' => 'boolean' ],
						'guests'  => [ 'type' => 'boolean' ],
						'subject' => [
							'type'      => 'string',
							'maxLength' => 200,
						],
						'heading' => [
							'type'      => 'string',
							'maxLength' => 200,
						],
						'message' => [
							'type'      => 'string',
							'maxLength' => 200,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/stock-alerts/test',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'test' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'product_id' => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
						'to'         => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		$alerts   = Plugin::instance()->stock_alerts()->repository();
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $alerts->page( (string) $request->get_param( 'status' ), (int) $request->get_param( 'product_id' ), (int) $request->get_param( 'page' ), $per_page );
		$counts   = $alerts->counts( gmdate( 'Y-m-d H:i:s', time() - self::STATS_DAYS * DAY_IN_SECONDS ) );
		$items    = [];
		foreach ( $result['rows'] as $alert ) {
			$items[] = $this->item( $alert );
		}
		$wanted = [];
		foreach ( $alerts->wanted( self::WANTED ) as $row ) {
			$product  = $this->product( $row['product_id'] );
			$wanted[] = [
				'product_id' => $row['product_id'],
				'name'       => $product->title ?? __( '(deleted product)', 'flexacommerce' ),
				'waiting'    => $row['waiting'],
				'in_stock'   => $product !== null && Plugin::instance()->stock_alerts()->available( $product, 0 ),
			];
		}
		return new WP_REST_Response(
			[
				'items'   => $items,
				'total'   => $result['total'],
				'pages'   => (int) ceil( $result['total'] / max( 1, $per_page ) ),
				'wanted'  => $wanted,
				'stats'   => [
					'days'    => self::STATS_DAYS,
					'waiting' => $counts['waiting'],
					'sent'    => $counts['sent'],
				],
				'enabled' => StockAlertSettings::enabled(),
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Plugin::instance()->stock_alerts()->repository()->delete( (int) $request->get_param( 'id' ) ) ) {
			return new WP_Error( 'flexacommerce_stock_alert_not_found', __( 'This signup is no longer in the list.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function settings( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( self::settings_data(), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function save( WP_REST_Request $request ): WP_REST_Response {
		$partial = [];
		foreach ( array_keys( StockAlertSettings::get() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$partial[ $key ] = $request->get_param( $key );
			}
		}
		if ( $partial !== [] ) {
			StockAlertSettings::update( $partial );
		}
		return new WP_REST_Response( self::settings_data(), 200 );
	}

	/**
	 * A sample email for a product: the one given, else the most wanted,
	 * else the newest published product.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$given = trim( (string) $request->get_param( 'to' ) );
		$to    = $given !== '' ? sanitize_email( $given ) : (string) wp_get_current_user()->user_email;
		if ( ! is_email( $to ) ) {
			return new WP_Error(
				'flexacommerce_invalid_email',
				__( 'Enter a valid email address.', 'flexacommerce' ),
				[
					'status' => 400,
					'field'  => 'to',
				]
			);
		}
		$service    = Plugin::instance()->stock_alerts();
		$product_id = (int) $request->get_param( 'product_id' );
		if ( $product_id === 0 ) {
			$product_id = $service->repository()->wanted( 1 )[0]['product_id'] ?? 0;
		}
		if ( $product_id === 0 ) {
			$newest     = get_posts(
				[
					'post_type'      => PostTypes::PRODUCT,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				]
			);
			$product_id = (int) ( $newest[0] ?? 0 );
		}
		if ( $product_id === 0 ) {
			return new WP_Error( 'flexacommerce_no_product', __( 'Publish a product first; the sample email shows one.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		if ( ! $service->test( $product_id, $to ) ) {
			return new WP_Error( 'flexacommerce_email_failed', __( 'The email could not be sent. Check the email log for the reason.', 'flexacommerce' ), [ 'status' => 502 ] );
		}
		return new WP_REST_Response( [ 'sent' => $to ], 200 );
	}

	/** @return array<string,mixed> */
	private function item( StockAlert $alert ): array {
		$product = $this->product( $alert->product_id );
		$user    = $alert->user_id !== null ? get_userdata( $alert->user_id ) : false;
		return [
			'id'         => $alert->id,
			'product_id' => $alert->product_id,
			'variant_id' => $alert->variant_id,
			'product'    => $product !== null ? Plugin::instance()->stock_alerts()->label( $product, $alert->variant_id ) : __( '(deleted product)', 'flexacommerce' ),
			'email'      => $alert->email,
			'customer'   => $user instanceof \WP_User ? $user->display_name : '',
			'user_id'    => $alert->user_id,
			'status'     => $alert->status,
			'created_at' => self::iso( $alert->created_at ),
			'sent_at'    => self::iso( $alert->sent_at ),
		];
	}

	private function product( int $id ): ?Product {
		if ( ! array_key_exists( $id, $this->products ) ) {
			$this->products[ $id ] = Plugin::instance()->catalog()->products()->find( $id );
		}
		return $this->products[ $id ];
	}

	/** @return array<string,mixed> */
	private static function settings_data(): array {
		return [
			'settings' => StockAlertSettings::get(),
			'defaults' => StockAlertSettings::default_texts(),
			'email'    => (string) wp_get_current_user()->user_email,
		];
	}

	private static function iso( ?string $date ): ?string {
		return $date !== null && $date !== '' ? str_replace( ' ', 'T', $date ) . 'Z' : null;
	}
}
