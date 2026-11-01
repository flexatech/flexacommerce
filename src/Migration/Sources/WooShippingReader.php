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
	/** @var array<int,array{slug:string,name:string}> shipping class terms by id */
	private array $classes = [];

	public function shipping(): ?Record {
		if ( ! class_exists( \WC_Shipping_Zones::class ) ) {
			return null;
		}
		$classes = [];
		$terms   = get_terms(
			[
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			]
		);
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$classes[ $term->term_id ] = [
				'slug' => $term->slug,
				'name' => $term->name,
			];
		}
		$this->classes = $classes;
		$zones         = [];
		foreach ( \WC_Shipping_Zones::get_shipping_zones() as $zone ) {
			$zones[] = $this->zone( $zone );
		}
		return new Record(
			Record::SHIPPING,
			Ref::make( 'wc', Record::SHIPPING, 1 ),
			[
				'currency' => (string) get_option( 'woocommerce_currency', 'USD' ),
				'classes'  => array_values( $classes ),
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
			$out[] = self::method( $method, $this->classes );
		}
		return $out;
	}

	/**
	 * Canonical form of one zone method, from the public properties of a
	 * WC_Shipping_Method (the "json" context of get_shipping_methods).
	 *
	 * @param array<int,array{slug:string,name:string}> $classes shipping class terms by id
	 * @return array<string,mixed>
	 */
	public static function method( object $method, array $classes = [] ): array {
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
				$out                  = self::class_costs( $out, $settings, $classes );
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
	 * Flat rate class costs: `class_cost_{term id}` (or the older
	 * `class_cost_{slug}`) and `no_class_cost`, each a cost formula where
	 * [qty] counts the items of that class. A cost for a class that no
	 * longer exists is dropped, as WooCommerce never charges it.
	 *
	 * @param array<string,mixed>                       $out
	 * @param array<array-key,mixed>                    $settings
	 * @param array<int,array{slug:string,name:string}> $classes
	 * @return array<string,mixed>
	 */
	private static function class_costs( array $out, array $settings, array $classes ): array {
		$by_slug = array_column( $classes, 'slug', 'slug' );
		$rows    = [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( ! is_scalar( $value ) || trim( (string) $value ) === '' ) {
				continue;
			}
			if ( $key === 'no_class_cost' ) {
				$slug = '';
			} elseif ( str_starts_with( $key, 'class_cost_' ) ) {
				$suffix = substr( $key, strlen( 'class_cost_' ) );
				$slug   = ctype_digit( $suffix ) && isset( $classes[ (int) $suffix ] ) ? $classes[ (int) $suffix ]['slug'] : ( $by_slug[ $suffix ] ?? null );
				if ( $slug === null || isset( $rows[ $slug ] ) ) {
					continue;
				}
			} else {
				continue;
			}
			$cost = self::cost( (string) $value );
			if ( $cost === null ) {
				$out['supported'] = false;
				$out['notes'][]   = sprintf( 'The class cost formula "%s" cannot be converted; only a fixed cost plus a cost per item is supported.', trim( (string) $value ) );
				continue;
			}
			$rows[ $slug ] = [
				'class'         => $slug,
				'cost'          => $cost[0],
				'cost_per_item' => $cost[1],
			];
		}
		$out['class_costs']       = array_values( $rows );
		$out['class_calculation'] = ( $settings['type'] ?? 'class' ) === 'order' ? 'per_order' : 'per_class';
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
