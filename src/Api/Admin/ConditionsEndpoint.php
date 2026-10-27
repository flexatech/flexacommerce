<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Countries;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * What the conditions editor of pricing rules and coupons offers to pick
 * (ADR-041): countries, payment gateways, and shipping methods, both by
 * type (every zone) and one method in one zone.
 */
final class ConditionsEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/conditions/choices',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'choices' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function choices( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$countries = [];
		foreach ( Countries::all() as $code => $name ) {
			$countries[] = [
				'id'    => $code,
				'title' => $name,
			];
		}

		$gateways = Plugin::instance()->payments()->gateways();
		$payments = [];
		foreach ( $gateways->ids() as $id ) {
			$gateway = $gateways->get( $id );
			if ( $gateway !== null ) {
				$payments[] = [
					'id'    => $id,
					'title' => $gateways->title( $gateway ),
				];
			}
		}

		$shipping = Plugin::instance()->shipping()->service();
		$registry = $shipping->methods();
		$methods  = [];
		$titles   = [];
		foreach ( $registry->types() as $type ) {
			$method = $registry->get( $type );
			if ( $method !== null ) {
				$titles[ $type ] = $method->title();
				$methods[]       = [
					'id'    => $type,
					/* translators: %s: a shipping method type such as Flat rate */
					'title' => sprintf( __( '%s (any zone)', 'flexacommerce' ), $method->title() ),
				];
			}
		}
		foreach ( $shipping->zones()->all() as $zone ) {
			foreach ( $zone->methods as $instance ) {
				$name      = is_string( $instance->settings['title'] ?? null ) ? trim( $instance->settings['title'] ) : '';
				$methods[] = [
					'id'    => $instance->type . ':' . $instance->instance_id,
					'title' => ( $zone->name !== '' ? $zone->name : __( 'Everywhere else', 'flexacommerce' ) ) . ': ' . ( $name !== '' ? $name : ( $titles[ $instance->type ] ?? $instance->type ) ),
				];
			}
		}

		return new WP_REST_Response(
			[
				'countries'        => $countries,
				'payment_methods'  => $payments,
				'shipping_methods' => $methods,
			],
			200
		);
	}
}
