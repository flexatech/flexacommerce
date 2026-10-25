<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Tax;

use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxPreset;
use Flexa\Commerce\Tax\RateRow;
use Flexa\Commerce\Tax\TaxCsv;
use Flexa\Commerce\Tests\Unit\TestCase;

final class RateRowTest extends TestCase {
	public function test_input_is_normalized(): void {
		$row = RateRow::from_input(
			[
				'country'   => 'us',
				'state'     => '*',
				'rate'      => '8.875',
				'name'      => 'NY Tax',
				'postcodes' => '100*; 10001-2345 ;10100...10199;100*',
				'cities'    => [ 'new york', 'New York', '' ],
				'tax_class' => 'Reduced Rate',
				'compound'  => 'yes',
				'shipping'  => '0',
				'source'    => 'evil',
			]
		);
		self::assertSame( 'US', $row->country );
		self::assertSame( '', $row->state );
		self::assertSame( 88750, $row->rate );
		self::assertSame( [ '100*', '10001', '10100...10199' ], $row->postcodes );
		self::assertSame( [ 'NEW YORK' ], $row->cities );
		self::assertSame( 'reduced-rate', $row->tax_class );
		self::assertTrue( $row->compound );
		self::assertFalse( $row->shipping );
		self::assertSame( 'manual', $row->source );
		self::assertSame( '8.875', $row->to_array()['rate'] );
		self::assertSame( 'preset:us', RateRow::from_input( [ 'rate' => '1', 'source' => 'preset:us' ] )->source );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public static function bad_input(): array {
		return [
			'country'        => [ [ 'country' => 'USA', 'rate' => '1' ] ],
			'rate text'      => [ [ 'rate' => 'ten' ] ],
			'rate too high'  => [ [ 'rate' => '100.5' ] ],
			'int rate'       => [ [ 'rate' => 1000001 ] ],
			'priority'       => [ [ 'rate' => '1', 'priority' => 0 ] ],
			'range reversed' => [ [ 'rate' => '1', 'postcodes' => '200...100' ] ],
			'range letters'  => [ [ 'rate' => '1', 'postcodes' => 'A1...B2' ] ],
			'postcode chars' => [ [ 'rate' => '1', 'postcodes' => '12$45' ] ],
			'inner star'     => [ [ 'rate' => '1', 'postcodes' => '1*5' ] ],
		];
	}

	/**
	 * @dataProvider bad_input
	 * @param array<string,mixed> $input
	 */
	public function test_bad_input_is_rejected( array $input ): void {
		$this->expectException( \InvalidArgumentException::class );
		RateRow::from_input( $input );
	}

	public function test_matching_follows_the_location_rules(): void {
		$row = RateRow::from_input(
			[
				'country'   => 'US',
				'state'     => 'NY',
				'rate'      => '4',
				'postcodes' => [ '100*', '14000...14999' ],
			]
		);
		self::assertTrue( $row->matches( new TaxLocation( 'US', 'NY', '10012' ) ) );
		self::assertTrue( $row->matches( new TaxLocation( 'US', 'NY', '14500-1111' ) ) );
		self::assertFalse( $row->matches( new TaxLocation( 'US', 'NY', '15000' ) ) );
		self::assertFalse( $row->matches( new TaxLocation( 'US', 'NJ', '10012' ) ) );
		self::assertFalse( $row->matches( new TaxLocation( 'US', 'NY' ) ) );

		$city = RateRow::from_input( [ 'rate' => '1', 'cities' => 'Paris' ] );
		self::assertTrue( $city->matches( new TaxLocation( 'FR', '', '', 'paris' ) ) );
		self::assertFalse( $city->matches( new TaxLocation( 'FR', '', '', 'Lyon' ) ) );
	}

	public function test_location_rows_round_trip_through_from_row(): void {
		$row  = RateRow::from_input( [ 'country' => 'US', 'rate' => '1', 'postcodes' => '90210;90000...90099', 'cities' => 'LA' ] );
		$back = RateRow::from_row(
			[
				'id'         => 5,
				'tax_class'  => '',
				'country'    => 'US',
				'state'      => '',
				'rate'       => 10000,
				'name'       => '',
				'priority'   => 1,
				'compound'   => '0',
				'shipping'   => '1',
				'rate_order' => 0,
				'source'     => 'manual',
			],
			$row->location_rows()
		);
		self::assertSame( $row->postcodes, $back->postcodes );
		self::assertSame( $row->cities, $back->cities );
		self::assertSame( 'US-TAX-1', $back->to_rate()->code );
	}

	public function test_csv_parse_reports_lines_and_known_classes(): void {
		$rows   = [
			[ 'US', 'CA', '', '', '7.2500', 'CA Tax', '1', '0', '1', '' ],
			[ 'US', 'NY', '', '', '4', 'NY Tax', '', '', '', 'reduced-rate' ],
			[ 'US', 'TX', '', '', '6.25', 'TX', '1', '0', '1', 'luxury' ],
			[ 'US' ],
			[ 'XX1', '', '', '', '1', '', '1', '0', '1', '' ],
		];
		$parsed = TaxCsv::parse( $rows, [ '', 'reduced-rate' ], null, 2, 10 );
		self::assertCount( 2, $parsed['rows'] );
		self::assertSame( 'import', $parsed['rows'][0]->source );
		self::assertSame( 11, $parsed['rows'][1]->rate_order );
		self::assertTrue( $parsed['rows'][1]->shipping );
		self::assertSame( [ 4, 5, 6 ], array_column( $parsed['errors'], 'line' ) );

		$only = TaxCsv::parse( [ $rows[1] ], [ '', 'reduced-rate' ], '', 1 );
		self::assertSame( [], $only['rows'] );
		self::assertSame( 1, $only['errors'][0]['line'] );
	}

	public function test_csv_export_quotes_and_guards_formulas(): void {
		$row = RateRow::from_input( [ 'country' => 'US', 'state' => 'CA', 'rate' => '7.25', 'name' => '=HYPERLINK("x")', 'postcodes' => '90210;91*' ] );
		$csv = TaxCsv::export( [ $row ] );
		$lines = explode( "\n", trim( $csv ) );
		self::assertSame( '"Country code","State code","Postcode / ZIP","City","Rate %","Tax name","Priority","Compound","Shipping","Tax class"', $lines[0] );
		self::assertSame( '"US","CA","90210;91*","","7.2500","\'=HYPERLINK(""x"")","1","0","1",""', $lines[1] );
	}

	public function test_preset_rows_skip_untaxed_and_unpicked_states(): void {
		$preset = TaxPreset::from_array(
			[
				'id'      => 'us',
				'name'    => 'United States',
				'country' => 'us',
				'as_of'   => '2026-01-01',
				'regions' => [
					[ 'state' => 'ca', 'rate' => '7.25', 'shipping' => false ],
					[ 'state' => 'TX', 'rate' => '6.25', 'shipping' => true ],
					[ 'state' => 'OR', 'rate' => null ],
				],
			]
		);
		$rows = $preset->rows( [ 'ca', 'OR' ] );
		self::assertCount( 1, $rows );
		self::assertSame( 'CA', $rows[0]['state'] );
		self::assertSame( 'CA Tax', $rows[0]['name'] );
		self::assertSame( 'preset:us', $rows[0]['source'] );
		self::assertFalse( $rows[0]['shipping'] );
		self::assertSame( 72500, RateRow::from_input( $rows[0] )->rate );
	}

	public function test_preset_id_and_country_are_checked(): void {
		$this->expectException( \InvalidArgumentException::class );
		TaxPreset::from_array( [ 'id' => 'Bad Id', 'country' => 'US' ] );
	}
}
