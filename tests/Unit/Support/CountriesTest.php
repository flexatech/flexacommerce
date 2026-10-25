<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Unit\TestCase;

final class CountriesTest extends TestCase {
	public function test_the_list_is_iso_codes_sorted_by_name(): void {
		$all = Countries::all();
		self::assertGreaterThan( 200, count( $all ) );
		foreach ( array_keys( $all ) as $code ) {
			self::assertMatchesRegularExpression( '/^[A-Z]{2}$/', $code );
		}
		$names  = array_map( 'remove_accents', array_values( $all ) );
		$sorted = $names;
		usort( $sorted, 'strcasecmp' );
		self::assertSame( $sorted, $names );

		self::assertTrue( Countries::exists( 'VN' ) );
		self::assertFalse( Countries::exists( 'vn' ), 'codes are upper case' );
		self::assertSame( 'XX', Countries::name( 'XX' ), 'an unknown code is shown as is' );
	}

	public function test_allowed_follows_the_sell_to_setting(): void {
		self::assertSame( Countries::all(), Countries::allowed() );

		\WP_Test_State::$options[ Settings::OPTION ] = [ 'sell_to_countries' => [ 'US', 'VN', 'ZZ' ] ];
		self::assertSame( [ 'US', 'VN' ], array_keys( Countries::allowed() ), 'unknown codes are dropped, name order is kept' );
	}

	public function test_states_exist_only_where_the_form_shows_a_list(): void {
		self::assertArrayHasKey( 'CA', Countries::states( 'US' ) );
		self::assertArrayHasKey( 'DC', Countries::states( 'US' ) );
		self::assertArrayHasKey( 'QC', Countries::states( 'CA' ) );
		self::assertArrayHasKey( 'NSW', Countries::states( 'AU' ) );
		self::assertSame( [], Countries::states( 'VN' ) );
	}

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function postcodes(): array {
		return [
			'US zip'           => [ 'US', '95014', true ],
			'US zip+4'         => [ 'US', '95014-1234', true ],
			'US short'         => [ 'US', '9501', false ],
			'CA lower spaced'  => [ 'CA', 'k1a  0b1', true ],
			'CA no space'      => [ 'CA', 'K1A0B1', true ],
			'GB'               => [ 'GB', 'sw1a 1aa', true ],
			'GB bad'           => [ 'GB', '12345', false ],
			'NL'               => [ 'NL', '1012 ab', true ],
			'JP dash optional' => [ 'JP', '1000001', true ],
			'VN'               => [ 'VN', '700000', true ],
			'VN short'         => [ 'VN', '70000', false ],
			'no pattern'       => [ 'TH', '10110', true ],
			'no pattern long'  => [ 'TH', str_repeat( '1', 17 ), false ],
			'empty'            => [ 'TH', '   ', false ],
		];
	}

	/** @dataProvider postcodes */
	public function test_postcodes( string $country, string $postcode, bool $valid ): void {
		self::assertSame( $valid, Countries::valid_postcode( $country, $postcode ) );
	}

	public function test_postcode_rules(): void {
		self::assertSame( 'K1A 0B1', Countries::normalize_postcode( "  k1a \t 0b1 " ) );
		self::assertTrue( Countries::uses_postcode( 'US' ) );
		self::assertFalse( Countries::uses_postcode( 'HK' ) );
		self::assertFalse( Countries::uses_postcode( 'AE' ) );
	}
}
