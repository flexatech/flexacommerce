<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Support\IntMath;
use Flexa\Commerce\Tests\Unit\TestCase;

final class IntMathTest extends TestCase {
	public function test_small_products_round_half_away_from_zero(): void {
		self::assertSame( 3, IntMath::mul_div_half_up( 5, 1, 2 ) );
		self::assertSame( -3, IntMath::mul_div_half_up( -5, 1, 2 ) );
		self::assertSame( 2, IntMath::mul_div_half_up( 7, 1, 4 ) );
		self::assertSame( 0, IntMath::mul_div_half_up( 0, -9, 7 ) );
	}

	public function test_a_product_past_the_int_range_is_exact(): void {
		self::assertSame( [ 4611686018427387903, 2 ], IntMath::mul_div( PHP_INT_MAX, 2, 4 ) );
		self::assertSame( 4611686018427387904, IntMath::mul_div_half_up( PHP_INT_MAX, 2, 4 ) );
		self::assertSame( [ PHP_INT_MAX, 0 ], IntMath::mul_div( PHP_INT_MAX, 1000000, 1000000 ) );
	}

	public function test_a_result_past_the_int_range_fails(): void {
		$this->expectException( \OverflowException::class );
		IntMath::mul_div( PHP_INT_MAX, 4, 2 );
	}

	public function test_rounding_up_past_the_int_range_fails(): void {
		$this->expectException( \OverflowException::class );
		// (2^32 - 1)(2^32 + 1) / 2 = PHP_INT_MAX + 0.5.
		IntMath::mul_div_half_up( 4294967295, 4294967297, 2 );
	}

	public function test_a_zero_divisor_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		IntMath::mul_div( 1, 1, 0 );
	}

	public function test_int_min_is_rejected(): void {
		$this->expectException( \OverflowException::class );
		IntMath::mul_div_half_up( PHP_INT_MIN, 1, 1 );
	}
}
