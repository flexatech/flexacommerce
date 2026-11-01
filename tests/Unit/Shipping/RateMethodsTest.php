<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Shipping;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\Shipping\PackageLine;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Shipping\Methods\FlatRate;
use Flexa\Commerce\Shipping\Methods\TableRate;
use Flexa\Commerce\Support\RateTables;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Unit\TestCase;

/** Shipping classes, flat rate class costs and table rates (ADR-068). */
final class RateMethodsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Settings::update(
			[
				'shipping_classes' => [
					[
						'slug' => 'bulky',
						'name' => 'Bulky',
					],
					[
						'slug' => 'small',
						'name' => 'Small',
					],
				],
			]
		);
	}

	public function test_flat_rate_adds_a_cost_for_each_class_in_the_cart(): void {
		$package  = $this->package( [ 2, 1000, 500, 'bulky' ], [ 1, 2000, 100, 'small' ], [ 3, 500, 0, '' ] );
		$settings = [
			'cost'          => 500,
			'cost_per_item' => 100,
			'class_costs'   => [
				'calculation' => 'per_class',
				'costs'       => [
					'bulky' => [
						'cost'     => 1000,
						'per_item' => 200,
					],
					'small' => [
						'cost'     => 100,
						'per_item' => 0,
					],
					''      => [
						'cost'     => 50,
						'per_item' => 0,
					],
				],
			],
		];
		// 500 + 6 items * 100, bulky 1000 + 2 * 200, small 100, no class 50.
		self::assertSame( 1100 + 1400 + 100 + 50, $this->flat( $package, $settings ) );

		$settings['class_costs']['calculation'] = 'per_order';
		self::assertSame( 1100 + 1400, $this->flat( $package, $settings ), 'Only the most expensive class counts.' );
	}

	public function test_flat_rate_ignores_class_costs_once_the_store_has_no_classes(): void {
		$package  = $this->package( [ 1, 1000, 500, 'bulky' ] );
		$settings = [
			'cost'        => 500,
			'class_costs' => [
				'calculation' => 'per_class',
				'costs'       => [
					'bulky' => [
						'cost'     => 1000,
						'per_item' => 0,
					],
				],
			],
		];
		self::assertSame( 1500, $this->flat( $package, $settings ) );
		Settings::update( [ 'shipping_classes' => [] ] );
		self::assertSame( 500, $this->flat( $package, $settings ) );
	}

	public function test_table_rate_uses_the_first_row_that_matches_the_weight(): void {
		$rows = [
			self::row( 'weight', null, 1, 500 ),
			self::row( 'weight', 1, 5, 900, [ 'weight_cost' => 100 ] ),
			self::row( 'weight', 5, null, 0, [ 'abort' => true ] ),
		];
		// 2 x 400 g = 0.8 kg.
		self::assertSame( 500, $this->table( $this->package( [ 2, 1000, 400, '' ] ), $rows ) );
		// 3 x 1 kg = 3 kg: 900 + 3 * 100.
		self::assertSame( 1200, $this->table( $this->package( [ 3, 1000, 1000, '' ] ), $rows ) );
		// Exactly 1 kg matches the first row, the bounds are inclusive.
		self::assertSame( 500, $this->table( $this->package( [ 1, 1000, 1000, '' ] ), $rows ) );
		self::assertNull( $this->table( $this->package( [ 6, 1000, 1000, '' ] ), $rows ), 'A matching no-shipping row hides the method.' );
		self::assertNull( $this->table( $this->package( [ 1, 1000, 1000, '' ] ), [ self::row( 'weight', 2, 3, 500 ) ] ), 'No matching row hides the method.' );
	}

	public function test_table_rate_weight_unit_items_subtotal_and_percent(): void {
		$package = $this->package( [ 2, 2500, 454, '' ], [ 1, 5000, 0, '' ] );
		self::assertSame( 700, $this->table( $package, [ self::row( 'weight', 1.9, 2.1, 700 ) ], [ 'weight_unit' => 'lb' ] ), '908 g is 2 lb.' );
		self::assertSame( 300 + 3 * 50, $this->table( $package, [ self::row( 'items', 3, 3, 300, [ 'item_cost' => 50 ] ) ] ) );
		self::assertSame( 1000, $this->table( $package, [ self::row( 'subtotal', 0, 9999, 9 ), self::row( 'subtotal', 10000, null, 0, [ 'percent' => 10 ] ) ] ) );
	}

	public function test_table_rate_class_rows_per_order_and_per_class(): void {
		$package = $this->package( [ 1, 1000, 1000, 'bulky' ], [ 2, 1000, 100, '' ] );
		$rows    = [
			self::row( 'none', null, null, 2000, [ 'class' => 'bulky' ] ),
			self::row( 'items', null, null, 300, [ 'item_cost' => 100 ] ),
		];
		self::assertSame( 2000, $this->table( $package, $rows ), 'A bulky item picks the bulky row for the order.' );
		self::assertSame( 2000 + 300 + 2 * 100, $this->table( $package, $rows, [ 'calculation' => 'per_class' ] ), 'Each class is priced by its own row.' );
		self::assertNull( $this->table( $package, [ self::row( 'none', null, null, 2000, [ 'class' => 'bulky' ] ) ], [ 'calculation' => 'per_class' ] ), 'Every class needs a row.' );
		self::assertSame( 100, $this->table( $package, [ self::row( 'none', null, null, 100, [ 'class' => '' ] ) ] ), 'A no-class row matches when a line has no class.' );
	}

	public function test_table_rate_handling_fee_minimum_and_maximum(): void {
		$package = $this->package( [ 1, 1000, 100, '' ] );
		$rows    = [ self::row( 'none', null, null, 500 ) ];
		self::assertSame( 650, $this->table( $package, $rows, [ 'handling_fee' => 150 ] ) );
		self::assertSame( 800, $this->table( $package, $rows, [ 'min_cost' => 800 ] ) );
		self::assertSame( 400, $this->table( $package, $rows, [ 'max_cost' => 400 ] ) );
	}

	public function test_rate_tables_sanitize(): void {
		$rows = RateTables::rows(
			[
				[
					'class'     => 'Big Box',
					'condition' => 'weight',
					'min'       => '5',
					'max'       => 2,
					'cost'      => '-3',
					'percent'   => 150,
					'abort'     => 'yes',
				],
				[ 'condition' => 'bogus' ],
				'nope',
			]
		);
		self::assertCount( 2, $rows );
		self::assertSame( [ 'big-box', 'weight', 2.0, 5.0, 0, 100.0, true ], [ $rows[0]['class'], $rows[0]['condition'], $rows[0]['min'], $rows[0]['max'], $rows[0]['cost'], $rows[0]['percent'], $rows[0]['abort'] ] );
		self::assertSame( [ '*', 'none', null, null ], [ $rows[1]['class'], $rows[1]['condition'], $rows[1]['min'], $rows[1]['max'] ] );
		self::assertCount( RateTables::MAX_ROWS, RateTables::rows( array_fill( 0, RateTables::MAX_ROWS + 5, [] ) ) );

		$costs = RateTables::class_costs(
			[
				'calculation' => 'weird',
				'costs'       => [
					'bulky' => [
						'cost'     => 100,
						'per_item' => 5,
					],
					'empty' => [
						'cost'     => 0,
						'per_item' => 0,
					],
				],
			]
		);
		self::assertSame(
			[
				'calculation' => 'per_class',
				'costs'       => [
					'bulky' => [
						'cost'     => 100,
						'per_item' => 5,
					],
				],
			],
			$costs
		);
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private static function row( string $condition, int|float|null $min, int|float|null $max, int $cost, array $extra = [] ): array {
		return $extra + [
			'class'     => '*',
			'condition' => $condition,
			'min'       => $min,
			'max'       => $max,
			'cost'      => $cost,
		];
	}

	/** @param array{0:int,1:int,2:int,3:string} ...$lines qty, unit price, unit weight in grams, class */
	private function package( array ...$lines ): ShippingPackage {
		$out   = [];
		$total = 0;
		foreach ( $lines as $i => [ $qty, $price, $grams, $class ] ) {
			$out[]  = new PackageLine( $i + 1, $qty, $grams, null, new Money( $qty * $price, 'USD' ), $class );
			$total += $qty * $price;
		}
		return new ShippingPackage( new Destination( 'US' ), $out, new Money( $total, 'USD' ) );
	}

	/** @param array<string,mixed> $settings */
	private function flat( ShippingPackage $package, array $settings ): int {
		return ( new FlatRate() )->rates( $package, $settings, 1 )[0]->cost->amount;
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param array<string,mixed>       $settings
	 */
	private function table( ShippingPackage $package, array $rows, array $settings = [] ): ?int {
		$rates = ( new TableRate() )->rates( $package, [ 'rows' => $rows ] + $settings + [ 'weight_unit' => 'kg' ], 1 );
		return $rates === [] ? null : $rates[0]->cost->amount;
	}
}
