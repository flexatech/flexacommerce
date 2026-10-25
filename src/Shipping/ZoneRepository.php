<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Zones in one option, `flexacommerce_shipping_zones`, not autoloaded (doc 03
 * §1). Shape: `{ v, next_zone_id, next_instance_id, zones: [...] }`, zones in
 * match order. Zone 0 ("everywhere else") always exists and is matched last.
 */
final class ZoneRepository {
	public const OPTION = 'flexacommerce_shipping_zones';

	/** @var list<Zone>|null */
	private ?array $zones = null;

	/** @return list<Zone> zones in match order, zone 0 last */
	public function all(): array {
		if ( $this->zones !== null ) {
			return $this->zones;
		}
		$data  = $this->data();
		$zones = [];
		$rest  = null;
		foreach ( $data['zones'] as $row ) {
			$zone = Zone::from_array( $row );
			if ( $zone->id === Zone::REST_OF_WORLD ) {
				$rest = $zone;
			} else {
				$zones[] = $zone;
			}
		}
		$zones[]     = $rest ?? new Zone( Zone::REST_OF_WORLD, __( 'Everywhere else', 'flexacommerce' ) );
		$this->zones = $zones;

		return $zones;
	}

	public function find( int $id ): ?Zone {
		foreach ( $this->all() as $zone ) {
			if ( $zone->id === $id ) {
				return $zone;
			}
		}
		return null;
	}

	/**
	 * Replace every zone. New zones (id 0 except the last) and new method
	 * instances (instance_id 0) get fresh ids; ids are never reused, so a
	 * rate id stored on an old order keeps pointing at nothing rather than
	 * at a different method.
	 *
	 * @param list<Zone> $zones in match order; a zone with id 0 is "everywhere else"
	 * @return list<Zone>
	 */
	public function save( array $zones ): array {
		$data      = $this->data();
		$next_zone = $data['next_zone_id'];
		$next_inst = $data['next_instance_id'];
		$known     = [];
		foreach ( $this->all() as $zone ) {
			$known[ $zone->id ] = true;
		}
		$rows = [];
		$rest = null;
		foreach ( $zones as $zone ) {
			$methods = [];
			foreach ( $zone->methods as $method ) {
				$id = $method->instance_id;
				if ( $id <= 0 || $id >= $next_inst ) {
					$id = $next_inst++;
				}
				$methods[] = new MethodInstance( $id, $method->type, $method->enabled, $method->settings );
			}
			$id = $zone->id;
			if ( $id === Zone::REST_OF_WORLD && $rest === null && $zone->regions === [] ) {
				$rest = new Zone( Zone::REST_OF_WORLD, $zone->name, [], [], $methods );
				continue;
			}
			if ( $id <= 0 || ! isset( $known[ $id ] ) ) {
				$id = $next_zone++;
			}
			$rows[] = ( new Zone( $id, $zone->name, $zone->regions, $zone->postcodes, $methods ) )->to_array();
		}
		$rows[] = ( $rest ?? $this->find( Zone::REST_OF_WORLD ) ?? new Zone( Zone::REST_OF_WORLD, '' ) )->to_array();
		update_option(
			self::OPTION,
			[
				'v'                => 1,
				'next_zone_id'     => $next_zone,
				'next_instance_id' => $next_inst,
				'zones'            => $rows,
			],
			false
		);
		$this->zones = null;
		return $this->all();
	}

	/** @return array{next_zone_id:int,next_instance_id:int,zones:list<array<string,mixed>>} */
	private function data(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$zones  = [];
		foreach ( is_array( $stored['zones'] ?? null ) ? $stored['zones'] : [] as $row ) {
			if ( is_array( $row ) ) {
				$zones[] = $row;
			}
		}
		return [
			'next_zone_id'     => max( 1, (int) ( $stored['next_zone_id'] ?? 1 ) ),
			'next_instance_id' => max( 1, (int) ( $stored['next_instance_id'] ?? 1 ) ),
			'zones'            => $zones,
		];
	}
}
