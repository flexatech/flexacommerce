<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Tax;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Tax\TaxLine;
use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRate;
use Flexa\Commerce\Contracts\Tax\TaxRequest;
use Flexa\Commerce\Tax\TaxEngine;
use Flexa\Commerce\Tests\Unit\TestCase;

final class TaxEngineTest extends TestCase {
	private static function rate( int $id, string $percent, bool $compound = false, bool $shipping = true, int $priority = 1 ): TaxRate {
		return new TaxRate( $id, 'R' . $id, 'Rate ' . $id, (int) TaxRate::from_percent( $percent ), $priority, $compound, $shipping );
	}

	/** @param array<string,list<TaxRate>> $by_class */
	private static function engine( array $by_class, mixed ...$args ): TaxEngine {
		return new TaxEngine( static fn ( TaxLocation $l, string $class ): array => $by_class[ $class ] ?? [], ...$args );
	}

	private static function line( string $key, int $amount, string $type = TaxLine::PRODUCT, string $class = '', string $status = TaxLine::TAXABLE ): TaxLine {
		return new TaxLine( $key, $type, new Money( $amount, 'USD' ), 1, $class, $status );
	}

	/** @param list<TaxLine> $lines */
	private static function request( array $lines, bool $inclusive = false, ?TaxLocation $at = null, bool $exempt = false ): TaxRequest {
		return new TaxRequest( 'USD', $inclusive, $at ?? new TaxLocation( 'US', 'CA' ), $lines, exempt: $exempt );
	}

	public function test_exclusive_tax_rounds_half_up_per_line(): void {
		$result = self::engine( [ '' => [ self::rate( 1, '7.25' ) ] ] )->calculate( self::request( [ self::line( 'a', 1000 ) ] ) );
		self::assertSame( 73, $result->total()->amount );
		self::assertSame( 1000, $result->line( 'a' )?->net->amount );
		self::assertSame( [ '1' => 73 ], $result->line( 'a' )?->by_rate() );
	}

	public function test_inclusive_tax_is_taken_out_of_the_price(): void {
		$result = self::engine( [ '' => [ self::rate( 1, '10' ) ] ] )->calculate( self::request( [ self::line( 'a', 1000 ) ], true ) );
		self::assertSame( 91, $result->total()->amount );
		self::assertSame( 909, $result->line( 'a' )?->net->amount );
	}

	public function test_compound_rate_taxes_the_tax_before_it(): void {
		$rates   = [ '' => [ self::rate( 1, '5' ), self::rate( 2, '10', true, true, 2 ) ] ];
		$outside = self::engine( $rates )->calculate( self::request( [ self::line( 'a', 1000 ) ] ) );
		self::assertSame( [ '1' => 50, '2' => 105 ], $outside->line( 'a' )?->by_rate() );

		$inside = self::engine( $rates )->calculate( self::request( [ self::line( 'a', 1155 ) ], true ) );
		self::assertSame( [ '1' => 50, '2' => 105 ], $inside->line( 'a' )?->by_rate() );
		self::assertSame( 1000, $inside->line( 'a' )?->net->amount );
	}

	public function test_shipping_uses_only_rates_that_tax_shipping(): void {
		$rates  = [ '' => [ self::rate( 1, '5' ), self::rate( 2, '2', false, false, 2 ) ] ];
		$result = self::engine( $rates )->calculate( self::request( [ self::line( 'a', 1000 ), self::line( 's', 500, TaxLine::SHIPPING ) ], true ) );
		self::assertSame( [ '1' => 25 ], $result->line( 's' )?->by_rate() );
		// Shipping is always exclusive of tax, even when prices include it.
		self::assertSame( 500, $result->line( 's' )?->net->amount );
		self::assertSame( 25, $result->shipping_total()->amount );
	}

	public function test_shipping_inherits_the_class_of_a_single_class_cart(): void {
		$rates  = [
			''        => [ self::rate( 1, '10' ) ],
			'reduced' => [ self::rate( 2, '5' ) ],
		];
		$result = self::engine( $rates )->calculate( self::request( [ self::line( 'a', 1000, TaxLine::PRODUCT, 'reduced' ), self::line( 's', 1000, TaxLine::SHIPPING ) ] ) );
		self::assertSame( [ '2' => 50 ], $result->line( 's' )?->by_rate() );

		$fixed = self::engine( $rates, 'reduced' )->calculate( self::request( [ self::line( 'a', 1000 ), self::line( 's', 1000, TaxLine::SHIPPING ) ] ) );
		self::assertSame( [ '2' => 50 ], $fixed->line( 's' )?->by_rate() );
	}

	public function test_mixed_cart_with_a_standard_item_taxes_shipping_at_standard(): void {
		$rates  = [
			''        => [ self::rate( 1, '10' ) ],
			'reduced' => [ self::rate( 2, '5' ) ],
		];
		$lines  = [ self::line( 'a', 1000 ), self::line( 'b', 1000, TaxLine::PRODUCT, 'reduced' ), self::line( 's', 1000, TaxLine::SHIPPING ) ];
		$result = self::engine( $rates, 'inherit', [ 'reduced' ] )->calculate( self::request( $lines ) );
		self::assertSame( [ '1' => 100 ], $result->line( 's' )?->by_rate() );
	}

	public function test_untaxed_status_skips_the_line(): void {
		$rates  = [ '' => [ self::rate( 1, '10' ) ] ];
		$result = self::engine( $rates )->calculate(
			self::request( [ self::line( 'a', 1000, TaxLine::PRODUCT, '', TaxLine::NONE ), self::line( 'b', 1000, TaxLine::PRODUCT, '', TaxLine::SHIPPING_ONLY ), self::line( 's', 100, TaxLine::SHIPPING ) ] )
		);
		self::assertSame( [], $result->line( 'a' )?->by_rate() );
		self::assertSame( [], $result->line( 'b' )?->by_rate() );
		// A shipping-only item still decides the shipping class.
		self::assertSame( [ '1' => 10 ], $result->line( 's' )?->by_rate() );
	}

	public function test_rounding_at_subtotal_rounds_once_per_rate(): void {
		$rates = [ '' => [ self::rate( 1, '10' ) ] ];
		$lines = [ self::line( 'a', 333 ), self::line( 'b', 333 ) ];

		self::assertSame( 66, self::engine( $rates )->calculate( self::request( $lines ) )->total()->amount );

		$result = self::engine( $rates, 'inherit', [], true )->calculate( self::request( $lines ) );
		self::assertSame( 67, $result->total()->amount );
		$a = $result->line( 'a' )?->total()->amount;
		$b = $result->line( 'b' )?->total()->amount;
		self::assertSame( 67, $a + $b );
		self::assertContains( $a, [ 33, 34 ] );
	}

	public function test_inclusive_price_keeps_the_net_outside_the_base_location(): void {
		$base   = new TaxLocation( 'GB' );
		$rates  = static fn ( TaxLocation $l, string $class ): array => $l->country === 'GB' ? [ self::rate( 1, '20' ) ] : [ self::rate( 2, '10' ) ];
		$engine = new TaxEngine( $rates, base: $base );
		$result = $engine->calculate( self::request( [ self::line( 'a', 1200 ) ], true, new TaxLocation( 'FR' ) ) );
		self::assertSame( 1000, $result->line( 'a' )?->net->amount );
		self::assertSame( [ '2' => 100 ], $result->line( 'a' )?->by_rate() );

		$kept = ( new TaxEngine( $rates, base: $base, adjust_non_base: false ) )->calculate( self::request( [ self::line( 'a', 1200 ) ], true, new TaxLocation( 'FR' ) ) );
		self::assertSame( 109, $kept->total()->amount );
	}

	public function test_exempt_and_unknown_location_charge_nothing(): void {
		$rates  = [ '' => [ self::rate( 1, '10' ) ] ];
		$exempt = self::engine( $rates )->calculate( self::request( [ self::line( 'a', 1000 ) ], false, null, true ) );
		self::assertSame( 0, $exempt->total()->amount );
		self::assertTrue( $exempt->exempt );

		$none = self::engine( $rates )->calculate( new TaxRequest( 'USD', false, null, [ self::line( 'a', 1000 ) ] ) );
		self::assertSame( 0, $none->total()->amount );
		self::assertSame( 1000, $none->line( 'a' )?->net->amount );
	}

	public function test_line_sums_match_the_rate_totals(): void {
		$rates  = [ '' => [ self::rate( 1, '6.25' ), self::rate( 2, '1.5', false, true, 2 ), self::rate( 3, '0.75', true, false, 3 ) ] ];
		$lines  = [ self::line( 'a', 1999 ), self::line( 'b', 4567 ), self::line( 'c', 1 ), self::line( 's', 899, TaxLine::SHIPPING ) ];
		foreach ( [ false, true ] as $subtotal ) {
			$result = self::engine( $rates, 'inherit', [], $subtotal )->calculate( self::request( $lines ) );
			$sum    = 0;
			foreach ( $result->lines as $line ) {
				$sum += $line->total()->amount;
			}
			self::assertSame( $result->total()->amount, $sum );
			self::assertSame( $result->total()->amount, $result->items_total()->amount + $result->shipping_total()->amount );
		}
	}
}
