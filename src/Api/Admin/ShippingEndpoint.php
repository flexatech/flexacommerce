<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\FieldValues;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping zones (doc 01, shipping zones). The screen saves the whole list
 * in match order, as ZoneRepository stores it; "everywhere else" (id 0) is
 * always last. Method settings are sanitized against the method's fields.
 */
final class ShippingEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/shipping/zones',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'zones' => [
							'type'     => 'array',
							'required' => true,
							'items'    => [ 'type' => 'object' ],
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( $this->data(), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$shipping = Plugin::instance()->shipping()->service();
		$methods  = $shipping->methods();
		$stored   = [];
		foreach ( $shipping->zones()->all() as $zone ) {
			foreach ( $zone->methods as $method ) {
				$stored[ $method->instance_id ] = $method->settings;
			}
		}

		$zones = [];
		$rows  = array_values( (array) $request->get_param( 'zones' ) );
		$last  = array_key_last( $rows );
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$zone = Zone::from_array( $row );
			// New zones also arrive with id 0; only the last row is "everywhere else".
			$rest = $zone->id === Zone::REST_OF_WORLD && $index === $last;
			if ( ! $rest && $zone->name === '' ) {
				return self::invalid( sprintf( 'zones.%d.name', $index ), __( 'Give every zone a name.', 'flexacommerce' ) );
			}
			if ( ! $rest && $zone->regions === [] ) {
				return self::invalid( sprintf( 'zones.%d.regions', $index ), __( 'Pick at least one country or state for every zone.', 'flexacommerce' ) );
			}
			foreach ( $zone->regions as $region ) {
				$parts = explode( ':', $region );
				if ( ! in_array( $parts[0], [ 'country', 'state' ], true ) || ! Countries::exists( $parts[1] ?? '' ) ) {
					return self::invalid( sprintf( 'zones.%d.regions', $index ), __( 'One of the regions is not a known country or state.', 'flexacommerce' ) );
				}
			}
			$instances = [];
			foreach ( $zone->methods as $method ) {
				$handler = $methods->get( $method->type );
				if ( $handler === null ) {
					// A method whose plugin is inactive keeps its stored settings until it is removed.
					if ( ! isset( $stored[ $method->instance_id ] ) ) {
						return self::invalid( sprintf( 'zones.%d.methods', $index ), __( 'One of the shipping methods is not installed.', 'flexacommerce' ) );
					}
					$instances[] = new MethodInstance( $method->instance_id, $method->type, $method->enabled, $stored[ $method->instance_id ] );
					continue;
				}
				$settings    = FieldValues::sanitize( $handler->settings_fields(), $method->settings, $stored[ $method->instance_id ] ?? [] );
				$instances[] = new MethodInstance( $method->instance_id, $method->type, $method->enabled, $settings );
			}
			$zones[] = $rest
				? new Zone( Zone::REST_OF_WORLD, $zone->name, [], [], $instances )
				: new Zone( $zone->id, $zone->name, $zone->regions, $zone->postcodes, $instances );
		}

		$shipping->zones()->save( $zones );
		return new WP_REST_Response( $this->data(), 200 );
	}

	/** @return array<string,mixed> */
	private function data(): array {
		$shipping = Plugin::instance()->shipping()->service();
		$registry = $shipping->methods();
		$types    = [];
		foreach ( $registry->types() as $type ) {
			$method = $registry->get( $type );
			if ( $method !== null ) {
				$types[] = [
					'type'   => $type,
					'title'  => $method->title(),
					'fields' => array_map( static fn ( SettingField $f ): array => $f->to_array(), $method->settings_fields() ),
				];
			}
		}

		$zones = [];
		foreach ( $shipping->zones()->all() as $zone ) {
			$row            = $zone->to_array();
			$row['methods'] = [];
			foreach ( $zone->methods as $instance ) {
				$method           = $registry->get( $instance->type );
				$data             = $instance->to_array();
				$data['settings'] = $method !== null
					? FieldValues::masked( $method->settings_fields(), $shipping->settings_of( $method, $instance ) )
					: [];
				$data['missing']  = $method === null;
				$row['methods'][] = $data;
			}
			$zones[] = $row;
		}

		return [
			'zones'     => $zones,
			'methods'   => $types,
			'countries' => self::countries(),
		];
	}

	/** @return list<array{code:string,name:string,states:list<array{code:string,name:string}>}> */
	private static function countries(): array {
		$out = [];
		foreach ( Countries::all() as $code => $name ) {
			$states = [];
			foreach ( Countries::states( $code ) as $state => $label ) {
				$states[] = [
					'code' => $state,
					'name' => $label,
				];
			}
			$out[] = [
				'code'   => $code,
				'name'   => $name,
				'states' => $states,
			];
		}
		return $out;
	}

	private static function invalid( string $field, string $message ): WP_Error {
		return new WP_Error(
			'flexacommerce_invalid_zone',
			$message,
			[
				'status' => 400,
				'field'  => $field,
			]
		);
	}
}
