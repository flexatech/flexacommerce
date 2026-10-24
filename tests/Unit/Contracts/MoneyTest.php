<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Contracts;

use Flexa\Commerce\Contracts\Exceptions\CurrencyMismatch;
use Flexa\Commerce\Contracts\Exceptions\InvalidMoney;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Tests\Unit\TestCase;

final class MoneyTest extends TestCase {
	/** @return iterable<string,array{string,int,int}> */
	public static function decimals(): iterable {
		yield 'plain'               => [ '19.99', 2, 1999 ];
		yield 'no fraction'         => [ '20', 2, 2000 ];
		yield 'short fraction'      => [ '0.5', 2, 50 ];
		yield 'leading dot'         => [ '.25', 2, 25 ];
		yield 'negative'            => [ '-0.5', 2, -50 ];
		yield 'rounds half up'      => [ '1.005', 2, 101 ];
		yield 'rounds down'         => [ '1.004', 2, 100 ];
		yield 'negative half away'  => [ '-1.005', 2, -101 ];
		yield 'zero exponent'       => [ '1500', 0, 1500 ];
		yield 'zero exponent round' => [ '1499.5', 0, 1500 ];
		yield 'three decimals'      => [ '1.234', 3, 1234 ];
		yield 'negative zero'       => [ '-0.001', 2, 0 ];
		yield 'whitespace'          => [ ' 3.10 ', 2, 310 ];
		yield 'plus sign'           => [ '+7', 2, 700 ];
	}

	/** @dataProvider decimals */
	public function test_parses_decimal_strings( string $input, int $exponent, int $expected ): void {
		self::assertSame( $expected, Money::from_decimal_string( $input, 'USD', $exponent )->amount );
	}

	/** @return iterable<string,array{string}> */
	public static function invalid_decimals(): iterable {
		yield 'empty'      => [ '' ];
		yield 'letters'    => [ '12a' ];
		yield 'thousands'  => [ '1,000.00' ];
		yield 'two dots'   => [ '1.2.3' ];
		yield 'only sign'  => [ '-' ];
		yield 'only dot'   => [ '.' ];
		yield 'exponent'   => [ '1e5' ];
		yield 'too large'  => [ '99999999999999999999' ];
	}

	/** @dataProvider invalid_decimals */
	public function test_rejects_invalid_decimals( string $input ): void {
		$this->expectException( InvalidMoney::class );
		Money::from_decimal_string( $input, 'USD', 2 );
	}

	public function test_formats_back_to_decimal_string(): void {
		self::assertSame( '19.99', ( new Money( 1999, 'USD' ) )->to_decimal_string( 2 ) );
		self::assertSame( '0.05', ( new Money( 5, 'USD' ) )->to_decimal_string( 2 ) );
		self::assertSame( '-0.05', ( new Money( -5, 'USD' ) )->to_decimal_string( 2 ) );
		self::assertSame( '1500', ( new Money( 1500, 'JPY' ) )->to_decimal_string( 0 ) );
		self::assertSame( '1.234', ( new Money( 1234, 'KWD' ) )->to_decimal_string( 3 ) );
	}

	public function test_is_exact_decimal(): void {
		self::assertTrue( Money::is_exact_decimal( '1.50', 2 ) );
		self::assertTrue( Money::is_exact_decimal( '1.5000', 2 ) );
		self::assertFalse( Money::is_exact_decimal( '1.505', 2 ) );
		self::assertFalse( Money::is_exact_decimal( '1.5', 0 ) );
	}

	public function test_arithmetic(): void {
		$a = new Money( 1000, 'EUR' );
		$b = new Money( 250, 'EUR' );
		self::assertSame( 1250, $a->add( $b )->amount );
		self::assertSame( 750, $a->subtract( $b )->amount );
		self::assertSame( 3000, $a->multiply( 3 )->amount );
		self::assertSame( -1000, $a->negate()->amount );
		self::assertSame( 1, $a->compare( $b ) );
		self::assertTrue( $a->equals( new Money( 1000, 'EUR' ) ) );
		self::assertFalse( $a->equals( new Money( 1000, 'USD' ) ) );
	}

	public function test_currency_mismatch_throws(): void {
		$this->expectException( CurrencyMismatch::class );
		( new Money( 1, 'USD' ) )->add( new Money( 1, 'EUR' ) );
	}

	public function test_overflow_throws(): void {
		$this->expectException( InvalidMoney::class );
		( new Money( PHP_INT_MAX, 'USD' ) )->add( new Money( 1, 'USD' ) );
	}

	public function test_invalid_currency_throws(): void {
		$this->expectException( InvalidMoney::class );
		new Money( 1, 'usd' );
	}

	public function test_allocate_sums_exactly_and_breaks_ties_by_order(): void {
		$parts = ( new Money( 100, 'USD' ) )->allocate( [ 1, 1, 1 ] );
		self::assertSame( [ 34, 33, 33 ], array_map( static fn ( Money $m ): int => $m->amount, $parts ) );

		// 1.5 and 3.5: equal remainders, so the earlier part gets the unit.
		$parts = ( new Money( 5, 'USD' ) )->allocate( [ 3, 7 ] );
		self::assertSame( [ 2, 3 ], array_map( static fn ( Money $m ): int => $m->amount, $parts ) );

		// 3.33 and 6.67: the larger remainder wins.
		$parts = ( new Money( 10, 'USD' ) )->allocate( [ 1, 2 ] );
		self::assertSame( [ 3, 7 ], array_map( static fn ( Money $m ): int => $m->amount, $parts ) );
	}

	public function test_allocate_negative_and_zero_ratio(): void {
		$parts = ( new Money( -100, 'USD' ) )->allocate( [ 1, 0, 2 ] );
		self::assertSame( [ -33, 0, -67 ], array_map( static fn ( Money $m ): int => $m->amount, $parts ) );
	}

	public function test_allocate_large_amounts_do_not_overflow(): void {
		$total = intdiv( PHP_INT_MAX, 2 );
		$parts = ( new Money( $total, 'USD' ) )->allocate( [ 1, 1, 1 ] );
		self::assertSame( $total, array_sum( array_map( static fn ( Money $m ): int => $m->amount, $parts ) ) );
	}

	public function test_allocate_rejects_bad_ratios(): void {
		$this->expectException( InvalidMoney::class );
		( new Money( 1, 'USD' ) )->allocate( [ 0, 0 ] );
	}

	public function test_json(): void {
		self::assertSame( '{"amount":1999,"currency":"USD"}', json_encode( new Money( 1999, 'USD' ) ) );
	}
}
