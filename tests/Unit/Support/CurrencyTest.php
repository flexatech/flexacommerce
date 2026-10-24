<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Tests\Unit\TestCase;

final class CurrencyTest extends TestCase {
	public function test_exponents(): void {
		self::assertSame( 2, Currency::exponent( 'USD' ) );
		self::assertSame( 0, Currency::exponent( 'JPY' ) );
		self::assertSame( 0, Currency::exponent( 'VND' ) );
		self::assertSame( 3, Currency::exponent( 'KWD' ) );
	}

	public function test_validity(): void {
		self::assertTrue( Currency::is_valid( 'EUR' ) );
		self::assertFalse( Currency::is_valid( 'XXX' ) );
		self::assertFalse( Currency::is_valid( 'eur' ) );
		self::assertContains( 'GBP', Currency::codes() );
	}

	public function test_exponent_filter_is_bounded(): void {
		add_filter( 'flexacommerce.currency.exponent', static fn (): int => 9 );
		self::assertSame( 2, Currency::exponent( 'USD' ) );

		\WP_Test_State::reset();
		add_filter( 'flexacommerce.currency.exponent', static fn (): int => 4 );
		self::assertSame( 4, Currency::exponent( 'USD' ) );
	}

	public function test_symbol_falls_back_to_code(): void {
		self::assertSame( '$', Currency::symbol( 'USD' ) );
		self::assertNotSame( '', Currency::symbol( 'ISK' ) );
	}
}
