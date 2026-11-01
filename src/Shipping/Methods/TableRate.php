<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping\Methods;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Contracts\Shipping\PackageLine;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;
use Flexa\Commerce\Shipping\ShippingClasses;
use Flexa\Commerce\Support\RateTables;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\Units;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of conditions and costs (ADR-068). Each row may name a shipping
 * class and a range of weight, item count or subtotal; the first row that
 * matches prices the order, or each class in the cart when the method
 * prices per class. No matching row, or a matching "no shipping" row,
 * hides the method.
 */
final class TableRate implements ShippingMethod {
	public function type(): string {
		return 'table_rate';
	}

	public function title(): string {
		return __( 'Table rate', 'flexacommerce' );
	}

	public function settings_fields(): array {
		$units = [
			'kg' => 'kg',
			'g'  => 'g',
			'lb' => 'lb',
			'oz' => 'oz',
		];
		$unit  = Settings::string( 'weight_unit' );
		return [
			new SettingField( 'title', 'text', __( 'Name shown at checkout', 'flexacommerce' ), __( 'Table rate', 'flexacommerce' ) ),
			new SettingField(
				'calculation',
				'select',
				__( 'Calculation', 'flexacommerce' ),
				'per_order',
				__( 'Per class: each shipping class in the cart is priced by its own row and the costs are added up.', 'flexacommerce' ),
				[
					'per_order' => __( 'One row for the whole order', 'flexacommerce' ),
					'per_class' => __( 'One row per shipping class', 'flexacommerce' ),
				]
			),
			new SettingField( 'weight_unit', 'select', __( 'Weight unit of the table', 'flexacommerce' ), isset( $units[ $unit ] ) ? $unit : 'kg', '', $units ),
			new SettingField( 'rows', 'rate_table', __( 'Rates', 'flexacommerce' ), [], __( 'Rows are checked from the top; the first one that matches is used.', 'flexacommerce' ) ),
			new SettingField( 'handling_fee', 'money', __( 'Handling fee per order', 'flexacommerce' ), 0 ),
			new SettingField( 'min_cost', 'money', __( 'Minimum cost', 'flexacommerce' ), 0, __( '0 for no minimum.', 'flexacommerce' ) ),
			new SettingField( 'max_cost', 'money', __( 'Maximum cost', 'flexacommerce' ), 0, __( '0 for no maximum.', 'flexacommerce' ) ),
			Label::tax_status(),
		];
	}

	public function rates( ShippingPackage $package, array $settings, int $instance_id ): array {
		$rows = RateTables::rows( $settings['rows'] ?? [] );
		$unit = Units::GRAMS[ (string) ( $settings['weight_unit'] ?? 'kg' ) ] ?? 1000.0;
		if ( $rows === [] || $package->lines === [] ) {
			return [];
		}
		if ( ( $settings['calculation'] ?? 'per_order' ) === 'per_class' ) {
			$cost = 0;
			foreach ( ShippingClasses::groups( $package ) as $class => $lines ) {
				$part = self::price( $rows, $lines, [ (string) $class ], $unit );
				if ( $part === null ) {
					return [];
				}
				$cost += $part;
			}
		} else {
			$cost = self::price( $rows, $package->lines, array_map( 'strval', array_keys( ShippingClasses::groups( $package ) ) ), $unit );
			if ( $cost === null ) {
				return [];
			}
		}
		$cost += max( 0, (int) ( $settings['handling_fee'] ?? 0 ) );
		$cost  = max( $cost, max( 0, (int) ( $settings['min_cost'] ?? 0 ) ) );
		$max   = max( 0, (int) ( $settings['max_cost'] ?? 0 ) );
		if ( $max > 0 ) {
			$cost = min( $cost, $max );
		}
		return [
			new ShippingRate(
				'table_rate:' . $instance_id,
				Label::of( $settings, $this->title() ),
				new Money( $cost, $package->currency() ),
				[],
				Label::taxable( $settings ),
			),
		];
	}

	/**
	 * The cost of the first row that matches these lines, null when none
	 * does or the match says not to ship.
	 *
	 * @param list<array{class:string,condition:string,min:int|float|null,max:int|float|null,cost:int,item_cost:int,weight_cost:int,percent:float,abort:bool}> $rows
	 * @param list<PackageLine>                                                                                                                                 $lines
	 * @param list<string>                                                                                                                                      $classes the classes these lines carry
	 */
	private static function price( array $rows, array $lines, array $classes, float $unit ): ?int {
		$items    = 0;
		$grams    = 0;
		$subtotal = 0;
		foreach ( $lines as $line ) {
			$items    += $line->qty;
			$grams    += ( $line->weight_g ?? 0 ) * $line->qty;
			$subtotal += $line->line_total->amount;
		}
		$weight = $grams / $unit;
		foreach ( $rows as $row ) {
			if ( $row['class'] !== RateTables::ANY && ! in_array( $row['class'], $classes, true ) ) {
				continue;
			}
			$value = match ( $row['condition'] ) {
				'weight'   => $weight,
				'items'    => $items,
				'subtotal' => $subtotal,
				default    => null,
			};
			// A small tolerance so 2.5 kg typed in the table matches 2500 g in the cart.
			if ( $value !== null && ( ( $row['min'] !== null && $value < $row['min'] - 1e-9 ) || ( $row['max'] !== null && $value > $row['max'] + 1e-9 ) ) ) {
				continue;
			}
			if ( $row['abort'] ) {
				return null;
			}
			return $row['cost'] + $row['item_cost'] * $items + (int) round( $row['weight_cost'] * $weight ) + (int) round( $subtotal * $row['percent'] / 100 );
		}
		return null;
	}
}
