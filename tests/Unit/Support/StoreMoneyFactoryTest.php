<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\StoreMoneyFactory;
use Flexa\Commerce\Tests\Unit\TestCase;

final class StoreMoneyFactoryTest extends TestCase {
	public function test_uses_store_currency_and_its_exponent(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = [ 'currency' => 'JPY' ];
		$money                                       = ( new StoreMoneyFactory() )->from_decimal_string( '1500' );
		self::assertSame( 1500, $money->amount );
		self::assertSame( 'JPY', $money->currency );
	}

	public function test_format_default(): void {
		$f = new StoreMoneyFactory();
		self::assertSame( '$1,234,567.50', $f->format( $f->from_minor( 123456750 ) ) );
		self::assertSame( '-$0.05', $f->format( $f->from_minor( -5 ) ) );
		self::assertSame( '$0.00', $f->format( $f->from_minor( 0 ) ) );
	}

	public function test_format_european_style(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = [
			'currency'           => 'EUR',
			'currency_position'  => 'right_space',
			'thousand_separator' => '.',
			'decimal_separator'  => ',',
		];
		$f = new StoreMoneyFactory();
		self::assertSame( '1.234,50 €', $f->format( $f->from_minor( 123450 ) ) );
	}

	public function test_format_zero_decimal_currency(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = [ 'currency' => 'JPY' ];
		$f = new StoreMoneyFactory();
		self::assertSame( '¥1,500', $f->format( $f->from_minor( 1500 ) ) );
	}
}
