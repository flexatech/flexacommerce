<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\GiftCards\GiftCard;
use Flexa\Commerce\GiftCards\GiftCardService;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The Gift cards screen (ADR-069): the list, one card with its activity,
 * a card made by hand, edits, balance adjustments and a resend. Amounts are
 * in the minor unit of the store currency. Dates come in as store-local
 * days (YYYY-MM-DD). Codes spend like cash, so the list takes the order
 * capability and changes take the edit one.
 */
final class GiftCardsEndpoint extends Endpoint {
	private const STATUSES = [ '', GiftCard::ACTIVE, GiftCard::DISABLED, 'used', 'expired' ];

	public function register_routes(): void {
		$text = [
			'type'      => 'string',
			'maxLength' => 200,
		];
		$card = [
			'recipient_email' => $text,
			'recipient_name'  => $text,
			'sender_name'     => $text,
			'message'         => [
				'type'      => 'string',
				'maxLength' => 1000,
			],
			'expires_on'      => [
				'type'    => 'string',
				'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
			],
		];

		register_rest_route(
			self::NAMESPACE,
			'/admin/gift-cards',
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
							'enum'    => self::STATUSES,
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
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => $card + [
						'amount'     => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
						'deliver_on' => [
							'type'    => 'string',
							'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
						],
						'send'       => [
							'type'    => 'boolean',
							'default' => true,
						],
						'note'       => [
							'type'      => 'string',
							'maxLength' => 500,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/gift-cards/(?P<id>\d+)',
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
					'args'                => $card + [
						'status' => [
							'type' => 'string',
							'enum' => GiftCard::STATUSES,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/gift-cards/(?P<id>\d+)/adjust',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'adjust' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
					'args'                => [
						'amount' => [
							'type'     => 'integer',
							'required' => true,
						],
						'note'   => [
							'type'      => 'string',
							'maxLength' => 500,
							'default'   => '',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/gift-cards/(?P<id>\d+)/send',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'send' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
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
			gmdate( 'Y-m-d H:i:s' )
		);
		return new WP_REST_Response(
			[
				'items'         => array_map( fn ( GiftCard $card ): array => $this->item( $card ), $result['rows'] ),
				'total'         => $result['total'],
				'pages'         => (int) ceil( $result['total'] / max( 1, $per_page ) ),
				'currency'      => Settings::string( 'currency' ),
				'expiry_months' => Settings::int( 'gift_card_expiry_months' ),
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$card = $this->service()->repository()->find( (int) $request->get_param( 'id' ) );
		return $card !== null ? new WP_REST_Response( $this->detail( $card ), 200 ) : self::not_found();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = trim( (string) $request->get_param( 'recipient_email' ) );
		if ( $email !== '' && ! is_email( $email ) ) {
			return self::bad_email();
		}
		$input = [
			'amount'          => (int) $request->get_param( 'amount' ),
			'recipient_email' => $email,
			'recipient_name'  => (string) $request->get_param( 'recipient_name' ),
			'sender_name'     => (string) $request->get_param( 'sender_name' ),
			'message'         => (string) $request->get_param( 'message' ),
			'deliver_at'      => GiftCardService::deliver_at( (string) $request->get_param( 'deliver_on' ), new \DateTimeImmutable( 'now', wp_timezone() ) ),
			'send'            => (bool) $request->get_param( 'send' ),
			'note'            => (string) $request->get_param( 'note' ),
		];
		if ( $request->has_param( 'expires_on' ) ) {
			$input['expires_at'] = self::end_of( (string) $request->get_param( 'expires_on' ) );
		}
		$card = $this->service()->create( $input, get_current_user_id() );
		return new WP_REST_Response( $this->detail( $card ), 201 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$changes = [];
		foreach ( [ 'status', 'recipient_email', 'recipient_name', 'sender_name', 'message' ] as $key ) {
			if ( $request->has_param( $key ) ) {
				$changes[ $key ] = (string) $request->get_param( $key );
			}
		}
		if ( isset( $changes['recipient_email'] ) && trim( $changes['recipient_email'] ) !== '' && ! is_email( trim( $changes['recipient_email'] ) ) ) {
			return self::bad_email();
		}
		if ( $request->has_param( 'expires_on' ) ) {
			$changes['expires_at'] = self::end_of( (string) $request->get_param( 'expires_on' ) );
		}
		$card = $this->service()->update( (int) $request->get_param( 'id' ), $changes );
		return $card !== null ? new WP_REST_Response( $this->detail( $card ), 200 ) : self::not_found();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function adjust( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$card = $this->service()->adjust( (int) $request->get_param( 'id' ), (int) $request->get_param( 'amount' ), (string) $request->get_param( 'note' ), get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error(
				'flexacommerce_invalid_gift_card',
				$e->getMessage(),
				[
					'status' => 400,
					'field'  => 'amount',
				]
			);
		}
		return $card !== null ? new WP_REST_Response( $this->detail( $card ), 200 ) : self::not_found();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function send( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$card = $this->service()->repository()->find( (int) $request->get_param( 'id' ) );
		if ( $card === null ) {
			return self::not_found();
		}
		if ( $card->recipient_email === '' || $card->status !== GiftCard::ACTIVE ) {
			return new WP_Error( 'flexacommerce_gift_card_not_sendable', __( 'Only an active card with a recipient email can be sent.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		if ( ! $this->service()->send( $card ) ) {
			return new WP_Error( 'flexacommerce_email_failed', __( 'The email could not be sent. Check the email log for the reason.', 'flexacommerce' ), [ 'status' => 502 ] );
		}
		$card = $this->service()->repository()->find( $card->id ) ?? $card;
		return new WP_REST_Response( $this->detail( $card ), 200 );
	}

	private function service(): GiftCardService {
		return Plugin::instance()->gift_cards();
	}

	/** @return array<string,mixed> */
	private function item( GiftCard $card ): array {
		$now = gmdate( 'Y-m-d H:i:s' );
		return [
			'id'              => $card->id,
			'code'            => GiftCard::format( $card->code ),
			'initial_amount'  => $card->initial_amount,
			'balance'         => $card->balance,
			'currency'        => $card->currency,
			'status'          => $card->status,
			'state'           => match ( true ) {
				$card->status === GiftCard::DISABLED => 'disabled',
				$card->is_expired( $now )            => 'expired',
				$card->balance <= 0                  => 'used',
				default                              => 'active',
			},
			'recipient_email' => $card->recipient_email,
			'recipient_name'  => $card->recipient_name,
			'sender_name'     => $card->sender_name,
			'message'         => $card->message,
			'order_id'        => $card->order_id,
			'deliver_at'      => self::iso( $card->deliver_at ),
			'delivered_at'    => self::iso( $card->delivered_at ),
			'expires_at'      => self::iso( $card->expires_at ),
			'expires_on'      => self::local_day( $card->expires_at ),
			'created_at'      => self::iso( $card->created_at ),
		];
	}

	/** @return array<string,mixed> */
	private function detail( GiftCard $card ): array {
		$users    = [];
		$activity = [];
		foreach ( $this->service()->repository()->activity( $card->id ) as $row ) {
			$user = null;
			if ( $row->user_id !== null ) {
				if ( ! array_key_exists( $row->user_id, $users ) ) {
					$found                  = get_userdata( $row->user_id );
					$users[ $row->user_id ] = $found instanceof \WP_User ? $found->display_name : '';
				}
				$user = $users[ $row->user_id ];
			}
			$activity[] = [
				'user'       => $user,
				'created_at' => self::iso( $row->created_at ),
			] + $row->to_array();
		}
		return $this->item( $card ) + [ 'activity' => $activity ];
	}

	/** The end of a store-local day as a UTC moment; null for an empty value (the card does not expire). */
	private static function end_of( string $day ): ?string {
		$local = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) === 1 ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, wp_timezone() ) : false;
		if ( $local === false ) {
			return null;
		}
		return $local->modify( '+1 day' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** The store-local day an expiry belongs to, so the form shows what was entered. */
	private static function local_day( ?string $utc ): string {
		if ( $utc === null ) {
			return '';
		}
		$moment = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
		return $moment->setTimezone( wp_timezone() )->modify( '-1 second' )->format( 'Y-m-d' );
	}

	private static function iso( ?string $date ): ?string {
		return $date !== null && $date !== '' ? str_replace( ' ', 'T', $date ) . 'Z' : null;
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'flexacommerce_gift_card_not_found', __( 'This gift card does not exist.', 'flexacommerce' ), [ 'status' => 404 ] );
	}

	private static function bad_email(): WP_Error {
		return new WP_Error(
			'flexacommerce_invalid_email',
			__( 'Enter a valid email address.', 'flexacommerce' ),
			[
				'status' => 400,
				'field'  => 'recipient_email',
			]
		);
	}
}
