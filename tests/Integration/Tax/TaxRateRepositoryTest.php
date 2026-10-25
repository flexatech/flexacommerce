<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRate;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Database\Upgrades\V2VariationTaxClass;
use Flexa\Commerce\Tax\RateRow;

final class TaxRateRepositoryTest extends TaxTestCase {
	/** @return list<int> */
	private function matched( TaxLocation $at, string $class = '' ): array {
		return array_map( static fn ( TaxRate $r ): int => $r->id, $this->rates()->matching( $at, [ $class ] )[ $class ] );
	}

	public function test_the_most_specific_rate_wins_per_priority(): void {
		$country = $this->rate( [ 'rate' => '5' ] );
		$state   = $this->rate( [ 'state' => 'CA', 'rate' => '7.25' ] );
		$zip     = $this->rate( [ 'state' => 'CA', 'rate' => '8', 'postcodes' => '940*' ] );
		$city    = $this->rate( [ 'state' => 'CA', 'rate' => '9', 'postcodes' => '94016...94099', 'cities' => 'Daly City' ] );
		$county  = $this->rate( [ 'state' => 'CA', 'rate' => '1', 'priority' => 2 ] );

		self::assertSame( [ $country->id ], $this->matched( new TaxLocation( 'US', 'NY', '10001' ) ) );
		self::assertSame( [ $state->id, $county->id ], $this->matched( new TaxLocation( 'US', 'CA', '95014' ) ) );
		self::assertSame( [ $zip->id, $county->id ], $this->matched( new TaxLocation( 'US', 'CA', '94016' ) ) );
		self::assertSame( [ $city->id, $county->id ], $this->matched( new TaxLocation( 'US', 'CA', '94016-1234', 'daly city' ) ) );
		self::assertSame( [], $this->matched( new TaxLocation( 'CA', 'ON' ) ) );
	}

	public function test_compound_rates_come_after_simple_ones_and_classes_are_apart(): void {
		$compound = $this->rate( [ 'rate' => '2', 'compound' => true, 'priority' => 1 ] );
		$simple   = $this->rate( [ 'rate' => '5', 'priority' => 2 ] );
		$reduced  = $this->rate( [ 'rate' => '1', 'tax_class' => 'reduced-rate' ] );
		self::assertSame( [ $simple->id, $compound->id ], $this->matched( new TaxLocation( 'US', 'TX' ) ) );
		self::assertSame( [ $reduced->id ], $this->matched( new TaxLocation( 'US', 'TX' ), 'reduced-rate' ) );
	}

	public function test_a_write_drops_the_cached_match(): void {
		$at = new TaxLocation( 'US', 'WA' );
		self::assertSame( [], $this->matched( $at ) );
		$row = $this->rate( [ 'state' => 'WA', 'rate' => '6.5' ] );
		self::assertSame( [ $row->id ], $this->matched( $at ) );
		$this->rates()->delete( [ $row->id ] );
		self::assertSame( [], $this->matched( $at ) );
	}

	public function test_rows_round_trip_with_their_locations(): void {
		$row  = $this->rate( [ 'state' => 'NY', 'rate' => '8.875', 'name' => 'NYC', 'postcodes' => '100*;10451...10475', 'cities' => 'New York', 'source' => 'import' ] );
		$back = $this->rates()->find( $row->id );
		self::assertNotNull( $back );
		self::assertSame( $row->to_array(), $back->to_array() );
		self::assertSame( 1, $this->rates()->page( '', 'nyc' )['total'] );
	}

	public function test_replacing_a_preset_source_keeps_other_rows(): void {
		$manual = $this->rate( [ 'state' => 'CA', 'rate' => '1', 'name' => 'Local' ] );
		$this->rates()->replace_source( 'preset:us', [ RateRow::from_input( [ 'country' => 'US', 'state' => 'CA', 'rate' => '7.25', 'source' => 'preset:us' ] ) ] );
		$this->rates()->replace_source( 'preset:us', [ RateRow::from_input( [ 'country' => 'US', 'state' => 'TX', 'rate' => '6.25', 'source' => 'preset:us' ] ) ] );
		$states = array_map( static fn ( RateRow $r ): string => $r->state . ':' . $r->source, $this->rates()->all() );
		sort( $states );
		self::assertSame( [ 'CA:manual', 'TX:preset:us' ], $states );
		self::assertNotNull( $this->rates()->find( $manual->id ) );
	}

	public function test_removing_a_class_deletes_its_rates(): void {
		$this->enable(
			[
				'tax_classes' => [
					[ 'slug' => 'reduced-rate', 'name' => 'Reduced rate' ],
					[ 'slug' => 'zero-rate', 'name' => 'Zero rate' ],
				],
			]
		);
		$this->rate( [ 'rate' => '1', 'tax_class' => 'reduced-rate' ] );
		$keep  = $this->rate( [ 'rate' => '0', 'tax_class' => 'zero-rate' ] );
		$old   = \Flexa\Commerce\Support\Settings::all();
		$new   = \Flexa\Commerce\Support\Settings::update( [ 'tax_classes' => [ [ 'slug' => 'zero-rate', 'name' => 'Zero rate' ] ] ] );
		$this->tax()->on_settings_updated( $new, $old );
		self::assertSame( [ $keep->id ], array_map( static fn ( RateRow $r ): int => $r->id, $this->rates()->all() ) );
	}

	public function test_base_rates_are_compiled_for_display(): void {
		$this->enable();
		$this->rate( [ 'state' => 'CA', 'rate' => '7.25' ] );
		$price = new \Flexa\Commerce\Contracts\Money( 1000, 'USD' );
		self::assertSame( 1073, $this->tax()->display_price( $price, '', 'incl' )->amount );
		self::assertSame( 1000, $this->tax()->display_price( $price, '', 'excl' )->amount );
		self::assertSame( 1073, $this->tax()->display_price( $price, 'missing-class', 'incl' )->amount, 'an unknown class is Standard' );
	}

	public function test_v2_gives_variations_the_product_class(): void {
		global $wpdb;
		[ $id ] = $this->sized();
		$table  = Schema::table( 'variants' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET tax_class = %s WHERE product_id = %d', $table, '', $id ) ); // phpcs:ignore WordPress.DB
		( new V2VariationTaxClass() )->run();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT is_base, tax_class FROM %i WHERE product_id = %d', $table, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			self::assertSame( (int) $row['is_base'] === 1 ? '' : 'parent', $row['tax_class'] );
		}
		self::assertCount( 4, $rows );
	}
}
