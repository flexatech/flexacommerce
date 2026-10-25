<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\FieldValues;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Payment gateway settings (doc 04 §3.4). Secrets leave only masked
 * (`••••last4`) and a masked or empty value sent back keeps the stored
 * secret, so the screen can save without ever holding it (doc 06 §2.10).
 */
final class GatewaysEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/payments/gateways',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/payments/gateways/(?P<id>[a-z0-9_]+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'id'     => [
							'type'     => 'string',
							'required' => true,
						],
						'values' => [
							'type'     => 'object',
							'required' => true,
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$registry = Plugin::instance()->payments()->gateways();
		$out      = [];
		foreach ( $registry->ids() as $id ) {
			$gateway = $registry->get( $id );
			if ( $gateway !== null ) {
				$out[] = $this->gateway_data( $gateway );
			}
		}
		return new WP_REST_Response( $out, 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gateway = Plugin::instance()->payments()->gateways()->get( (string) $request->get_param( 'id' ) );
		if ( $gateway === null ) {
			return new WP_Error( 'flexacommerce_gateway_not_found', __( 'Payment method not found.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		$values = $request->get_param( 'values' );
		Plugin::instance()->payments()->gateways()->settings()->save( $gateway, is_array( $values ) ? $values : [] );
		return new WP_REST_Response( $this->gateway_data( $gateway ), 200 );
	}

	/** @return array<string,mixed> */
	private function gateway_data( PaymentGateway $gateway ): array {
		$settings = Plugin::instance()->payments()->gateways()->settings();
		$fields   = $settings->fields( $gateway );
		return [
			'id'       => $gateway->id(),
			'title'    => $gateway->title(),
			'enabled'  => $settings->enabled( $gateway ),
			'supports' => [
				'refunds' => $gateway->supports( 'refunds' ),
			],
			'fields'   => array_map( static fn ( SettingField $f ): array => $f->to_array(), $fields ),
			'values'   => FieldValues::masked( $fields, $settings->values( $gateway ) ),
		];
	}
}
