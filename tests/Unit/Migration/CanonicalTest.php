<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Migration;

use Flexa\Commerce\Migration\Canonical\Checksum;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Tests\Unit\TestCase;

final class CanonicalTest extends TestCase {
	public function test_clean_reads_source_numbers_without_floats(): void {
		self::assertSame( '12', Decimal::clean( 12 ) );
		self::assertSame( '1.5', Decimal::clean( 1.5 ) );
		self::assertSame( '0.5', Decimal::clean( '.5' ) );
		self::assertSame( '7.5', Decimal::clean( ' 007.50 ' ) );
		self::assertSame( '-2', Decimal::clean( '-2.0' ) );
		self::assertSame( '0', Decimal::clean( '-0.00' ) );
		self::assertNull( Decimal::clean( '1e3' ) );
		self::assertNull( Decimal::clean( '' ) );
		self::assertNull( Decimal::clean( null ) );
		self::assertNull( Decimal::clean( '1,5' ) );
	}

	public function test_arithmetic(): void {
		self::assertSame( '3.75', Decimal::multiply( '1.5', '2.5' ) );
		self::assertSame( '3.33', Decimal::divide( '10', '3', 2 ) );
		self::assertSame( '0.67', Decimal::divide( '2', '3', 2 ) );
		self::assertSame( '2.35', Decimal::round( '2.345', 2 ) );
		self::assertSame( '10.00', Decimal::round( '9.995', 2 ) );
		self::assertSame( '1.5', Decimal::trim( '001.500' ) );
		self::assertSame( '0', Decimal::trim( '-0' ) );

		$this->expectException( \DivisionByZeroError::class );
		Decimal::divide( '1', '0', 2 );
	}

	public function test_units_convert_to_base_and_back(): void {
		self::assertSame( '453.592', Decimal::to_base( '1', Decimal::GRAMS['lb'] ) );
		self::assertSame( '1', Decimal::from_base( '453.592', Decimal::GRAMS['lb'] ) );
		self::assertSame( '2500', Decimal::to_base( '2.5', Decimal::GRAMS['kg'] ) );
		self::assertSame( '2.5', Decimal::from_base( '2500', Decimal::GRAMS['kg'] ) );
		self::assertSame( '254', Decimal::to_base( '10', Decimal::MILLIMETRES['in'] ) );
		self::assertSame( '10', Decimal::from_base( '254', Decimal::MILLIMETRES['in'] ) );
		self::assertNull( Decimal::to_base( null, '1' ) );
		self::assertNull( Decimal::to_base( '-1', '1' ) );
	}

	public function test_money_to_minor_units_and_back(): void {
		self::assertSame( [ 1050, true ], Decimal::to_minor( '10.50', 'USD', 2 ) );
		self::assertSame( [ 1001, false ], Decimal::to_minor( '10.005', 'USD', 2 ) );
		self::assertSame( '10.50', Decimal::from_minor( 1050, 'USD', 2 ) );
		self::assertSame( '1500', Decimal::from_minor( 1500, 'JPY', 0 ) );
	}

	public function test_dates_are_utc(): void {
		self::assertSame( '2026-01-02T03:04:05Z', Dates::from_db( '2026-01-02 03:04:05' ) );
		self::assertNull( Dates::from_db( '0000-00-00 00:00:00' ) );
		self::assertNull( Dates::from_db( '' ) );
		self::assertSame( '2026-01-02 01:04:05', Dates::to_db( '2026-01-02T03:04:05+02:00' ) );
		self::assertSame( '2026-01-01T20:04:05Z', Dates::from_wc( new \DateTimeImmutable( '2026-01-02T03:04:05+07:00' ) ) );
		self::assertSame( '2026-01-02T04:04:05Z', Dates::shift( '2026-01-02T03:04:05Z', 3600 ) );
		self::assertNull( Dates::to_timestamp( 'not a date' ) );
		self::assertTrue( Dates::is_valid( null ) );
		self::assertTrue( Dates::is_valid( '2026-01-02T03:04:05Z' ) );
		self::assertFalse( Dates::is_valid( '2026-01-02 03:04:05' ) );
		self::assertFalse( Dates::is_valid( '2026-01-02T03:04:05+02:00' ) );
	}

	public function test_checksum_ignores_key_order_but_not_list_order(): void {
		$a = [
			'name'  => 'Shirt',
			'price' => '10',
			'meta'  => [
				'b' => 1,
				'a' => 2,
			],
		];
		$b = [
			'meta'  => [
				'a' => 2,
				'b' => 1,
			],
			'price' => '10',
			'name'  => 'Shirt',
		];
		self::assertSame( Checksum::of( $a ), Checksum::of( $b ) );
		self::assertNotSame( Checksum::of( [ 'ids' => [ 1, 2 ] ] ), Checksum::of( [ 'ids' => [ 2, 1 ] ] ) );
		self::assertNotSame( Checksum::of( [ 'price' => '10' ] ), Checksum::of( [ 'price' => '10.0' ] ) );
	}
}
