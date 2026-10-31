<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Recovery\Recovery;
use Flexa\Commerce\Recovery\RecoverySettings;
use Flexa\Commerce\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Abandoned carts (ADR-063): the list with the last 30 days in numbers,
 * the reminder settings and a test send. Rows hold customer addresses, so
 * reading them takes the order capability.
 */
final class RecoveryEndpoint extends Endpoint {
	private const STATS_DAYS = 30;

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/recovery',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
					'args'                => [
						'status'   => [
							'type'    => 'string',
							'enum'    => [ '', ...Recovery::STATUSES ],
							'default' => '',
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
			'/admin/recovery/(?P<id>\d+)',
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
			'/admin/recovery/settings',
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
						'enabled'         => [ 'type' => 'boolean' ],
						'abandon_minutes' => [
							'type'    => 'integer',
							'minimum' => RecoverySettings::MIN_ABANDON,
							'maximum' => RecoverySettings::MAX_ABANDON,
						],
						'capture_guests'  => [ 'type' => 'boolean' ],
						'reminders'       => [
							'type'     => 'array',
							'maxItems' => RecoverySettings::REMINDERS,
							'items'    => [ 'type' => 'object' ],
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/recovery/test',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'test' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'reminder' => [
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => RecoverySettings::REMINDERS - 1,
							'default' => 0,
						],
						'to'       => [
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
		$service  = Plugin::instance()->recovery();
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $service->repository()->page( (string) $request->get_param( 'status' ), (int) $request->get_param( 'page' ), $per_page );
		$stats    = $service->repository()->stats( gmdate( 'Y-m-d H:i:s', time() - self::STATS_DAYS * DAY_IN_SECONDS ) );
		$settled  = $stats['recovered'] + $stats['lost'];
		$items    = [];
		foreach ( $result['rows'] as $row ) {
			$items[] = $this->item( $row['recovery'], $row['order_number'] );
		}
		return new WP_REST_Response(
			[
				'items'   => $items,
				'total'   => $result['total'],
				'pages'   => (int) ceil( $result['total'] / max( 1, $per_page ) ),
				'stats'   => [
					'days'      => self::STATS_DAYS,
					'open'      => $stats['open'],
					'recovered' => $stats['recovered'],
					'lost'      => $stats['lost'],
					'rate'      => $settled > 0 ? (int) round( $stats['recovered'] * 100 / $settled ) : null,
					'revenue'   => self::money( $stats['revenue'], Settings::string( 'currency' ) ),
				],
				'enabled' => RecoverySettings::enabled(),
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Plugin::instance()->recovery()->repository()->delete( (int) $request->get_param( 'id' ) ) ) {
			return new WP_Error( 'flexacommerce_recovery_not_found', __( 'This cart is no longer in the list.', 'flexacommerce' ), [ 'status' => 404 ] );
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
		foreach ( [ 'enabled', 'abandon_minutes', 'capture_guests', 'reminders' ] as $key ) {
			if ( $request->has_param( $key ) ) {
				$partial[ $key ] = $request->get_param( $key );
			}
		}
		if ( $partial !== [] ) {
			RecoverySettings::update( $partial );
		}
		return new WP_REST_Response( self::settings_data(), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
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
		if ( ! Plugin::instance()->recovery()->test( (int) $request->get_param( 'reminder' ), $to ) ) {
			return new WP_Error( 'flexacommerce_email_failed', __( 'The email could not be sent. Check the email log for the reason.', 'flexacommerce' ), [ 'status' => 502 ] );
		}
		return new WP_REST_Response( [ 'sent' => $to ], 200 );
	}

	/** @return array<string,mixed> */
	private function item( Recovery $row, ?string $order_number ): array {
		$names = [];
		foreach ( $row->cart['items'] as $item ) {
			$names[] = [
				'name'     => $item['name'],
				'quantity' => $item['quantity'],
			];
		}
		$user = $row->user_id !== null ? get_userdata( $row->user_id ) : false;
		return [
			'id'               => $row->id,
			'email'            => $row->email,
			'customer'         => $user instanceof \WP_User ? $user->display_name : '',
			'user_id'          => $row->user_id,
			'status'           => $row->status,
			'total'            => self::money( $row->total, $row->currency ),
			'item_count'       => $row->item_count,
			'items'            => $names,
			'emails_sent'      => $row->emails_sent,
			'reminders_done'   => $row->reminder >= RecoverySettings::REMINDERS,
			'next_send_at'     => self::iso( $row->next_send_at ),
			'last_activity_at' => self::iso( $row->last_activity_at ),
			'clicked_at'       => self::iso( $row->clicked_at ),
			'coupon_code'      => $row->coupon_code,
			'order_id'         => $row->order_id,
			'order_number'     => $order_number,
			'recovered_at'     => self::iso( $row->recovered_at ),
			'unsubscribed'     => $row->unsubscribed_at !== null,
		];
	}

	/** @return array<string,mixed> */
	private static function settings_data(): array {
		$defaults = [];
		for ( $i = 0; $i < RecoverySettings::REMINDERS; $i++ ) {
			$defaults[] = RecoverySettings::default_texts( $i );
		}
		return [
			'settings' => RecoverySettings::get(),
			'defaults' => $defaults,
			'currency' => Settings::string( 'currency' ),
			'email'    => (string) wp_get_current_user()->user_email,
		];
	}

	/** @return array{amount:int,currency:string,formatted:string} */
	private static function money( int $amount, string $currency ): array {
		return [
			'amount'    => $amount,
			'currency'  => $currency,
			'formatted' => $currency !== '' ? Plugin::instance()->cart()->cart()->format( new Money( $amount, $currency ) ) : '',
		];
	}

	private static function iso( ?string $date ): ?string {
		return $date !== null && $date !== '' ? str_replace( ' ', 'T', $date ) . 'Z' : null;
	}
}
