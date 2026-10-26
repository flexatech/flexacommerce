<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingService;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\FieldValues;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping zones into FlexaCommerce (doc 05 §5.7). Zones and methods made by
 * an earlier import are replaced in place; zones and methods set up in
 * FlexaCommerce stay as they are. New zones go after the existing ones,
 * before "everywhere else".
 */
final class FxcShippingWriter {
	public const ZONE   = 'shipping_zone';
	public const METHOD = 'shipping_method';

	/** @var list<Problem> */
	private array $problems = [];

	private string $currency = '';

	public function __construct( private readonly ShippingService $shipping ) {}

	public function shipping( Record $record, Mapping $map ): WriteResult {
		$this->problems = [];
		$this->currency = Settings::string( 'currency' );
		$data           = $record->data;
		$source         = strtoupper( (string) ( $data['currency'] ?? '' ) );
		if ( $source !== '' && $source !== $this->currency ) {
			$this->problems[] = Problem::warning( 'shipping_currency', sprintf( 'Shipping amounts are in %1$s and the store uses %2$s. The numbers are copied as they are.', $source, $this->currency ) );
		}

		// A rolled back write leaves the cached zones ahead of the option.
		$this->shipping->zones()->flush();
		$existing = $this->shipping->zones()->all();
		$rest     = array_pop( $existing );
		$rest     = $rest ?? new Zone( Zone::REST_OF_WORLD, '' );
		$by_id    = [];
		foreach ( $existing as $index => $zone ) {
			$by_id[ $zone->id ] = $index;
		}
		// Source ids of the zones and methods in save order, to map the ids save() assigns.
		$zone_sources   = array_fill( 0, count( $existing ), null );
		$method_sources = [];

		foreach ( (array) ( $data['zones'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source_id = (string) ( $row['id'] ?? '' );
			$regions   = $this->regions( $row, $source_id );
			if ( $regions === [] ) {
				$this->problems[] = Problem::warning( 'zone_without_region', sprintf( 'The shipping zone "%s" has no country or state FlexaCommerce knows and was not imported.', (string) ( $row['name'] ?? '' ) ), [ 'zone' => $source_id ] );
				continue;
			}
			$target                = $map->target_id( self::ZONE, $source_id );
			$index                 = $target > 0 && isset( $by_id[ $target ] ) ? $by_id[ $target ] : null;
			$old                   = $index === null ? null : $existing[ $index ];
			[ $methods, $sources ] = $this->methods( (array) ( $row['methods'] ?? [] ), $old, $map );
			$zone                  = new Zone(
				$old->id ?? 0,
				sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				$regions,
				array_values( array_filter( array_map( 'strval', (array) ( $row['postcodes'] ?? [] ) ), static fn ( string $code ): bool => trim( $code ) !== '' ) ),
				$methods,
			);
			if ( $index === null ) {
				$existing[]     = $zone;
				$zone_sources[] = $source_id;
				$index          = array_key_last( $existing );
			} else {
				$existing[ $index ]     = $zone;
				$zone_sources[ $index ] = $source_id;
			}
			$method_sources[ $index ] = $sources;
		}

		$rest_row                             = is_array( $data['rest'] ?? null ) ? $data['rest'] : [];
		[ $rest_methods, $rest_sources ]      = $this->methods( (array) ( $rest_row['methods'] ?? [] ), $rest, $map );
		$saved                                = $this->shipping->zones()->save( [ ...$existing, new Zone( Zone::REST_OF_WORLD, $rest->name, [], [], $rest_methods ) ] );
		$method_sources[ count( $existing ) ] = $rest_sources;

		foreach ( $saved as $index => $zone ) {
			if ( isset( $zone_sources[ $index ] ) ) {
				$map->remember( self::ZONE, (string) $zone_sources[ $index ], $zone->id );
			}
			foreach ( $method_sources[ $index ] ?? [] as $position => $method_source ) {
				if ( $method_source !== null && isset( $zone->methods[ $position ] ) ) {
					$map->remember( self::METHOD, $method_source, $zone->methods[ $position ]->instance_id );
				}
			}
		}

		$action = $map->row( Record::SHIPPING, $record->source_id() ) === null ? WriteResult::CREATED : WriteResult::UPDATED;
		$map->remember( Record::SHIPPING, $record->source_id(), 1, $record->checksum );
		return new WriteResult( $action, 1, $this->problems );
	}

	/**
	 * @param array<array-key,mixed> $row
	 * @return list<string>
	 */
	private function regions( array $row, string $source_id ): array {
		$out = [];
		foreach ( (array) ( $row['regions'] ?? [] ) as $region ) {
			$parts = explode( ':', strtoupper( (string) $region ) );
			if ( in_array( $parts[0], [ 'COUNTRY', 'STATE' ], true ) && Countries::exists( $parts[1] ?? '' ) && ( $parts[0] === 'COUNTRY' || ( $parts[2] ?? '' ) !== '' ) ) {
				$out[] = strtolower( $parts[0] ) . ':' . implode( ':', array_slice( $parts, 1 ) );
			} else {
				$this->problems[] = Problem::warning( 'unknown_region', sprintf( 'The region "%s" is not a known country or state and was left out.', (string) $region ), [ 'zone' => $source_id ] );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Methods of one zone: the imported ones replace their earlier copy in
	 * place or are appended, every other method of the zone stays.
	 *
	 * @param array<array-key,mixed> $rows
	 * @return array{0:list<MethodInstance>,1:list<?string>} methods and the source id of each
	 */
	private function methods( array $rows, ?Zone $old, Mapping $map ): array {
		$methods = $old->methods ?? [];
		$sources = array_fill( 0, count( $methods ), null );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source_id = (string) ( $row['id'] ?? '' );
			$type      = sanitize_key( (string) ( $row['type'] ?? '' ) );
			$handler   = $this->shipping->methods()->get( $type );
			if ( $handler === null ) {
				$this->problems[] = Problem::warning( 'unknown_shipping_method', sprintf( 'The shipping method "%1$s" (%2$s) has no FlexaCommerce equivalent and was not imported.', (string) ( $row['title'] ?? '' ), $type ), [ 'method' => $source_id ] );
				continue;
			}
			foreach ( (array) ( $row['notes'] ?? [] ) as $note ) {
				$this->problems[] = Problem::warning( 'shipping_method_changed', sprintf( '%1$s: %2$s', (string) ( $row['title'] ?? $type ), (string) $note ), [ 'method' => $source_id ] );
			}
			$supported = ! empty( $row['supported'] ) || ! array_key_exists( 'supported', $row );
			if ( ! $supported && ! empty( $row['enabled'] ) ) {
				$this->problems[] = Problem::warning( 'shipping_method_disabled', sprintf( 'The shipping method "%s" was imported turned off. Check its cost, then turn it on.', (string) ( $row['title'] ?? $type ) ), [ 'method' => $source_id ] );
			}
			$target   = $map->target_id( self::METHOD, $source_id );
			$position = null;
			foreach ( $methods as $i => $method ) {
				if ( $target > 0 && $method->instance_id === $target ) {
					$position = $i;
				}
			}
			$input = [
				'title'      => (string) ( $row['title'] ?? '' ),
				'tax_status' => array_key_exists( 'taxable', $row ) && ! $row['taxable'] ? 'none' : 'taxable',
				'location'   => (string) ( $row['location'] ?? '' ),
				'requires'   => (string) ( $row['requires'] ?? '' ),
			];
			foreach ( [ 'cost', 'cost_per_item', 'min_amount' ] as $key ) {
				if ( isset( $row[ $key ] ) ) {
					$input[ $key ] = $this->minor( (string) $row[ $key ], $key );
				}
			}
			if ( $input['title'] === '' ) {
				unset( $input['title'] );
			}
			$stored   = $position === null ? [] : $methods[ $position ]->settings;
			$instance = new MethodInstance(
				$position === null ? 0 : $methods[ $position ]->instance_id,
				$type,
				! empty( $row['enabled'] ) && $supported,
				FieldValues::sanitize( $handler->settings_fields(), $input, $stored ),
			);
			if ( $position === null ) {
				$methods[] = $instance;
				$sources[] = $source_id;
			} else {
				$methods[ $position ] = $instance;
				$sources[ $position ] = $source_id;
			}
		}
		return [ $methods, $sources ];
	}

	private function minor( string $value, string $field ): int {
		$clean              = Decimal::clean( $value ) ?? '0';
		[ $amount, $exact ] = Decimal::to_minor( $clean, $this->currency, Currency::exponent( $this->currency ) );
		if ( ! $exact ) {
			$this->problems[] = Problem::warning( 'amount_rounded', sprintf( 'The amount %1$s (%2$s) was rounded to the currency precision.', $clean, $field ) );
		}
		return max( 0, $amount );
	}
}
