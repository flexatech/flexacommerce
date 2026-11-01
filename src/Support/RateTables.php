<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitized values of the two structured shipping settings (ADR-068):
 * flat rate costs per shipping class, and the rows of a table rate.
 * Amounts are minor units; table weights are in the table's own unit.
 */
final class RateTables {
	public const MAX_ROWS = 200;

	/** Row class meaning "any class"; '' means "no shipping class". */
	public const ANY = '*';

	public const CONDITIONS = [ 'none', 'weight', 'items', 'subtotal' ];

	public const CALCULATIONS = [ 'per_class', 'per_order' ];

	/** @return array{calculation:string,costs:array<string,array{cost:int,per_item:int}>} */
	public static function class_costs( mixed $value ): array {
		$value       = is_array( $value ) ? $value : [];
		$calculation = in_array( $value['calculation'] ?? null, self::CALCULATIONS, true ) ? (string) $value['calculation'] : 'per_class';
		$costs       = [];
		foreach ( is_array( $value['costs'] ?? null ) ? $value['costs'] : [] as $slug => $cost ) {
			$slug = substr( sanitize_title( (string) $slug ), 0, 50 );
			if ( ! is_array( $cost ) || count( $costs ) >= self::MAX_ROWS ) {
				continue;
			}
			$entry = [
				'cost'     => self::minor( $cost['cost'] ?? 0 ),
				'per_item' => self::minor( $cost['per_item'] ?? 0 ),
			];
			if ( $entry['cost'] > 0 || $entry['per_item'] > 0 ) {
				$costs[ $slug ] = $entry;
			}
		}
		return [
			'calculation' => $calculation,
			'costs'       => $costs,
		];
	}

	/**
	 * @return list<array{class:string,condition:string,min:int|float|null,max:int|float|null,cost:int,item_cost:int,weight_cost:int,percent:float,abort:bool}>
	 */
	public static function rows( mixed $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $row ) {
			if ( ! is_array( $row ) || count( $out ) >= self::MAX_ROWS ) {
				continue;
			}
			$class     = is_scalar( $row['class'] ?? null ) ? (string) $row['class'] : self::ANY;
			$class     = $class === self::ANY ? self::ANY : substr( sanitize_title( $class ), 0, 50 );
			$condition = in_array( $row['condition'] ?? null, self::CONDITIONS, true ) ? (string) $row['condition'] : 'none';
			$min       = $condition === 'none' ? null : self::bound( $row['min'] ?? null, $condition );
			$max       = $condition === 'none' ? null : self::bound( $row['max'] ?? null, $condition );
			if ( $min !== null && $max !== null && $max < $min ) {
				[ $min, $max ] = [ $max, $min ];
			}
			$percent = is_numeric( $row['percent'] ?? null ) ? round( max( 0.0, min( 100.0, (float) $row['percent'] ) ), 2 ) : 0.0;
			$out[]   = [
				'class'       => $class,
				'condition'   => $condition,
				'min'         => $min,
				'max'         => $max,
				'cost'        => self::minor( $row['cost'] ?? 0 ),
				'item_cost'   => self::minor( $row['item_cost'] ?? 0 ),
				'weight_cost' => self::minor( $row['weight_cost'] ?? 0 ),
				'percent'     => $percent,
				'abort'       => in_array( $row['abort'] ?? false, [ true, 1, '1', 'yes', 'true', 'on' ], true ),
			];
		}
		return $out;
	}

	private static function minor( mixed $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/** Weights keep three decimals; item counts and subtotals (minor units) are whole. */
	private static function bound( mixed $value, string $condition ): int|float|null {
		if ( $value === null || $value === '' || ! is_numeric( $value ) ) {
			return null;
		}
		$number = max( 0.0, (float) $value );
		return $condition === 'weight' ? round( $number, 3 ) : (int) round( $number );
	}
}
