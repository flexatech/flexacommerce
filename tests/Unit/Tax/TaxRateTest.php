<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Tax;

use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRate;
use Flexa\Commerce\Tests\Unit\TestCase;

final class TaxRateTest extends TestCase {
	public function test_percent_text_round_trips_without_floats(): void {
		self::assertSame( 72500, TaxRate::from_percent( '7.25' ) );
		self::assertSame( 72500, TaxRate::from_percent( '7.2500' ) );
		self::assertSame( 1, TaxRate::from_percent( '0.0001' ) );
		self::assertSame( TaxRate::SCALE, TaxRate::from_percent( '100' ) );
		self::assertSame( '7.25', TaxRate::to_percent( 72500 ) );
		self::assertSame( '20', TaxRate::to_percent( 200000 ) );
		self::assertSame( '0.0001', TaxRate::to_percent( 1 ) );
	}

	public function test_bad_percent_text_is_null(): void {
		foreach ( [ '', '7.25001', '100.0001', '-1', 'abc', '1e2', '7,25' ] as $text ) {
			self::assertNull( TaxRate::from_percent( $text ), $text );
		}
	}

	public function test_rate_outside_zero_to_hundred_percent_is_rejected(): void {
		new TaxRate( 1, 'X', 'X', 0 );
		new TaxRate( 1, 'X', 'X', TaxRate::SCALE );
		$this->expectException( \InvalidArgumentException::class );
		new TaxRate( 1, 'X', 'X', TaxRate::SCALE + 1 );
	}

	public function test_negative_rate_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new TaxRate( 1, 'X', 'X', -1 );
	}

	public function test_code_and_key_follow_woocommerce(): void {
		self::assertSame( 'US-CA-CA TAX-1', TaxRate::code_for( 'us', 'ca', 'CA Tax', 1 ) );
		self::assertSame( 'TAX-2', TaxRate::code_for( '', '', '', 2 ) );
		self::assertSame( '12', ( new TaxRate( 12, 'US-TAX-1', 'Tax', 1 ) )->key() );
		self::assertSame( 'avalara-ca', ( new TaxRate( 0, 'avalara-ca', 'Tax', 1 ) )->key() );
	}

	public function test_location_is_normalized(): void {
		$location = new TaxLocation( ' us', 'ca ', '94103-1234', ' san francisco' );
		self::assertSame( 'US', $location->country );
		self::assertSame( 'CA', $location->state );
		self::assertSame( '94103', $location->postcode );
		self::assertSame( 'SAN FRANCISCO', $location->city );
		self::assertSame( 'SW1A1AA', ( new TaxLocation( 'GB', '', 'sw1a 1aa' ) )->postcode );
		self::assertSame( '941031234', TaxLocation::postcode( 'CA', '94103-1234' ) );
		self::assertTrue( $location->equals( new TaxLocation( 'US', 'CA', '94103', 'San Francisco' ) ) );
	}
}
