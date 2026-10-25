<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\Tax\TaxRate;

defined( 'ABSPATH' ) || exit;

/**
 * Coercion of the structured tax settings (classes, markets, compiled base
 * rates). Kept out of Settings so pages that read one plain setting do not
 * load it (P1 memory).
 */
final class TaxSettings {
	/** Keys a tax market may override (doc 02 §7.12.6), with their schema types. */
	public const MARKET_OVERRIDES = [
		'tax_display_shop'      => 'enum:excl|incl',
		'tax_display_cart'      => 'enum:excl|incl',
		'price_display_suffix'  => 'string',
		'tax_total_display'     => 'enum:itemized|single',
		'tax_round_at_subtotal' => 'bool',
		'tax_based_on'          => 'enum:shipping|billing|base',
		'intrastate_sourcing'   => 'enum:destination|origin',
		'origin_states'         => 'states',
	];

	/** @return list<array{slug:string,name:string}> */
	public static function classes( mixed $value, mixed $default ): array {
		if ( ! is_array( $value ) ) {
			return is_array( $default ) ? array_values( $default ) : [];
		}
		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = is_scalar( $item['name'] ?? null ) ? mb_substr( sanitize_text_field( (string) $item['name'] ), 0, 100 ) : '';
			$slug = is_scalar( $item['slug'] ?? null ) && (string) $item['slug'] !== '' ? sanitize_title( (string) $item['slug'] ) : sanitize_title( $name );
			$slug = substr( $slug, 0, 50 );
			// '' is Standard and 'parent' means "same as parent" on variations; neither can be a class.
			if ( $slug === '' || $slug === 'parent' || $slug === 'inherit' || isset( $out[ $slug ] ) ) {
				continue;
			}
			$out[ $slug ] = [
				'slug' => $slug,
				'name' => $name !== '' ? $name : $slug,
			];
		}
		return array_values( $out );
	}

	/** @return list<array{id:string,name:string,countries:list<string>,overrides:array<string,mixed>}> */
	public static function markets( mixed $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = is_scalar( $item['id'] ?? null ) ? substr( sanitize_key( (string) $item['id'] ), 0, 32 ) : '';
			if ( $id === '' || isset( $out[ $id ] ) ) {
				continue;
			}
			$overrides = [];
			foreach ( is_array( $item['overrides'] ?? null ) ? $item['overrides'] : [] as $key => $override ) {
				if ( isset( self::MARKET_OVERRIDES[ $key ] ) ) {
					$coerced = Settings::coerce( self::MARKET_OVERRIDES[ $key ], $override, null );
					if ( $coerced !== null ) {
						$overrides[ $key ] = $coerced;
					}
				}
			}
			$countries  = Settings::coerce( 'countries', $item['countries'] ?? [], [] );
			$out[ $id ] = [
				'id'        => $id,
				'name'      => is_scalar( $item['name'] ?? null ) ? mb_substr( sanitize_text_field( (string) $item['name'] ), 0, 100 ) : $id,
				'countries' => is_array( $countries ) ? array_values( $countries ) : [],
				'overrides' => $overrides,
			];
		}
		return array_values( $out );
	}

	/** @return array<string,list<array<string,mixed>>> class slug => TaxRate::to_array() rows */
	public static function rates( mixed $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $class => $rates ) {
			if ( ! is_array( $rates ) ) {
				continue;
			}
			$list = [];
			foreach ( $rates as $rate ) {
				if ( ! is_array( $rate ) ) {
					continue;
				}
				try {
					$list[] = TaxRate::from_array( $rate )->to_array();
				} catch ( \InvalidArgumentException ) {
					continue;
				}
			}
			$out[ (string) $class ] = $list;
		}
		return $out;
	}
}
