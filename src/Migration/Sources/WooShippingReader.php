<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;

defined( 'ABSPATH' ) || exit;

/**
 * Every WooCommerce shipping zone as one record (doc 05 §5.7). A method whose
 * price FlexaCommerce cannot reproduce is marked `supported: false` with a
 * note; the writer imports it disabled so checkout never charges a
 * different amount than the old store did.
 */
final class WooShippingReader {
	public function shipping(): ?Record {
		if ( ! class_exists( \WC_Shipping_Zones::class ) ) {
			return null;
		}
		$zones = [];
		foreach ( \WC_Shipping_Zones::get_shipping_zones() as $zone ) {
			$zones[] = $this->zone( $zone );
		}
		return new Record(
			Record::SHIPPING,
			Ref::make( 'wc', Record::SHIPPING, 1 ),
			[
				'currency' => (string) get_option( 'woocommerce_currency', 'USD' ),
				'zones'    => $zones,
				'rest'     => [ 'methods' => $this->methods( new \WC_Shipping_Zone( 0 ) ) ],
			]
		);
	}

	/** @return array<string,mixed> */
	private function zone( \WC_Shipping_Zone $zone ): array {
		$regions    = [];
		$postcodes  = [];
		$continents = function_exists( 'WC' ) ? WC()->countries->get_continents() : [];
		foreach ( $zone->get_zone_locations( 'edit' ) as $location ) {
			$code = (string) $location->code;
			match ( (string) $location->type ) {
				'country'   => $regions[]   = 'country:' . $code,
				'state'     => $regions[]   = 'state:' . $code,
				'postcode'  => $postcodes[] = $code,
				'continent' => array_push( $regions, ...array_map( static fn ( $country ): string => 'country:' . $country, (array) ( $continents[ $code ]['countries'] ?? [] ) ) ),
				default     => null,
			};
		}
		return [
			'id'        => (string) $zone->get_id(),
			'name'      => (string) $zone->get_zone_name( 'edit' ),
			'regions'   => array_values( array_unique( $regions ) ),
			'postcodes' => $postcodes,
			'methods'   => $this->methods( $zone ),
		];
	}

	/** @return list<array<string,mixed>> */
	private function methods( \WC_Shipping_Zone $zone ): array {
		$out = [];
		foreach ( $zone->get_shipping_methods( false, 'json' ) as $method ) {
			$out[] = self::method( $method );
		}
		return $out;
	}

	/**
	 * Canonical form of one zone method, from the public properties of a
	 * WC_Shipping_Method (the "json" context of get_shipping_methods).
	 *
	 * @return array<string,mixed>
	 */
	public static function method( object $method ): array {
		$type     = (string) ( $method->id ?? '' );
		$settings = is_array( $method->instance_settings ?? null ) ? $method->instance_settings : [];
		$text     = static fn ( string $key ): string => is_scalar( $settings[ $key ] ?? null ) ? trim( (string) $settings[ $key ] ) : '';
		$out      = [
			'id'        => (string) ( $method->instance_id ?? '' ),
			'type'      => $type,
			'enabled'   => ( $method->enabled ?? 'yes' ) === 'yes',
			'title'     => $text( 'title' ),
			'taxable'   => $text( 'tax_status' ) !== 'none',
			'supported' => true,
			'notes'     => [],
		];
		if ( $type === 'flat_rate' || $type === 'local_pickup' ) {
			$cost = self::cost( $text( 'cost' ) );
			if ( $cost === null ) {
				$out['supported'] = false;
				$out['notes'][]   = sprintf( 'The cost formula "%s" cannot be converted; only a fixed cost plus a cost per item is supported.', $text( 'cost' ) );
				$cost             = [ '0', '0' ];
			}
			$out['cost'] = $cost[0];
			if ( $type === 'flat_rate' ) {
				$out['cost_per_item'] = $cost[1];
				foreach ( $settings as $key => $value ) {
					if ( ( str_starts_with( (string) $key, 'class_cost_' ) || $key === 'no_class_cost' ) && is_scalar( $value ) && trim( (string) $value ) !== '' ) {
						$out['supported'] = false;
						$out['notes'][]   = 'Shipping class costs are not supported.';
						break;
					}
				}
			} elseif ( $cost[1] !== '0' ) {
				$out['supported'] = false;
				$out['notes'][]   = 'Local pickup has no cost per item in FlexaCommerce.';
			}
		} elseif ( $type === 'free_shipping' ) {
			// The canonical values are FlexaCommerce's: '' is the minimum amount when one is set.
			$requires          = $text( 'requires' );
			$out['min_amount'] = in_array( $requires, [ 'min_amount', 'either', 'both' ], true ) ? ( self::amount( $text( 'min_amount' ) ) ?? '0' ) : '0';
			$out['requires']   = in_array( $requires, [ 'coupon', 'either', 'both' ], true ) ? $requires : '';
			// WooCommerce reads `ignore_discounts` only when a minimum applies; the canonical flag keeps the stored value.
			$out['ignore_discounts'] = $text( 'ignore_discounts' ) === 'yes';
		} else {
			$out['settings'] = $settings;
		}
		return $out;
	}

	/**
	 * A flat rate cost: a number, `N * [qty]`, or the two added. Null for
	 * any other formula ([fee], [cost], subtraction, more terms).
	 *
	 * @return array{0:string,1:string}|null fixed cost and cost per item
	 */
	public static function cost( string $formula ): ?array {
		$separator = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		$formula   = (string) preg_replace( '/\s+/', '', $formula );
		if ( $separator !== '.' && $separator !== '' ) {
			$formula = str_replace( $separator, '.', $formula );
		}
		$fixed = null;
		$item  = null;
		if ( $formula === '' ) {
			return [ '0', '0' ];
		}
		foreach ( explode( '+', $formula ) as $term ) {
			if ( preg_match( '/^(?:(\d*\.?\d+)\*)?\[qty\](?:\*(\d*\.?\d+))?$/i', $term, $m ) === 1 ) {
				if ( $item !== null || ( ( $m[1] ?? '' ) !== '' && ( $m[2] ?? '' ) !== '' ) ) {
					return null;
				}
				$item = self::amount( ( $m[1] ?? '' ) !== '' ? $m[1] : ( ( $m[2] ?? '' ) !== '' ? $m[2] : '1' ) );
				if ( $item === null ) {
					return null;
				}
				continue;
			}
			$number = self::amount( $term );
			if ( $number === null || $fixed !== null ) {
				return null;
			}
			$fixed = $number;
		}
		return [ $fixed ?? '0', $item ?? '0' ];
	}

	private static function amount( string $value ): ?string {
		$clean = Decimal::clean( $value );
		return $clean === null || str_starts_with( $clean, '-' ) ? null : $clean;
	}
}
