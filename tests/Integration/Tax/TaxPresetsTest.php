<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxPreset;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tax\RateRow;
use Flexa\Commerce\Tax\TaxPresets;

final class TaxPresetsTest extends TaxTestCase {
	public function tear_down(): void {
		remove_all_filters( 'flexacommerce.tax.presets' );
		parent::tear_down();
	}

	private function presets(): TaxPresets {
		return new TaxPresets( $this->tax() );
	}

	public function test_the_us_preset_writes_picked_states_and_skips_untaxed_ones(): void {
		$preset = $this->presets()->get( 'us' );
		self::assertNotNull( $preset );
		$written = $this->presets()->apply( $preset, [ 'CA', 'TX', 'OR' ], [] );
		self::assertSame( 2, $written );

		$rows = [];
		foreach ( $this->rates()->all() as $row ) {
			$rows[ $row->state ] = [ $row->rate, $row->shipping, $row->name, $row->source ];
		}
		ksort( $rows );
		self::assertSame(
			[
				'CA' => [ 72500, false, 'CA State Tax', 'preset:us' ],
				'TX' => [ 62500, true, 'TX State Tax', 'preset:us' ],
			],
			$rows
		);
	}

	public function test_applying_again_replaces_only_the_preset_rows(): void {
		$preset = $this->presets()->get( 'us' );
		self::assertNotNull( $preset );
		$this->presets()->apply( $preset, [ 'CA', 'TX' ], [] );
		$local = $this->rate( [ 'state' => 'CA', 'rate' => '1', 'priority' => 2, 'postcodes' => '90001' ] );

		$preview = $this->presets()->preview( $preset, [ 'NY' ] );
		self::assertCount( 1, $preview['add'] );
		self::assertCount( 2, $preview['remove'] );

		$this->presets()->apply( $preset, [ 'NY' ], [] );
		$states = array_map( static fn ( RateRow $r ): string => $r->state . ':' . $r->source, $this->rates()->all() );
		sort( $states );
		self::assertSame( [ 'CA:manual', 'NY:preset:us' ], $states );
		self::assertNotNull( $this->rates()->find( $local->id ) );
	}

	public function test_only_the_ticked_settings_and_the_market_are_saved(): void {
		$this->enable( [ 'tax_default_location' => 'base' ] );
		$preset = $this->presets()->get( 'us' );
		self::assertNotNull( $preset );
		$this->presets()->apply( $preset, [ 'CA' ], [ 'tax_based_on', 'market' ] );

		self::assertSame( 'base', Settings::string( 'tax_default_location' ), 'not ticked' );
		$markets = (array) Settings::get( 'tax_markets' );
		self::assertCount( 1, $markets );
		self::assertSame( 'us', $markets[0]['id'] );
		self::assertSame( 'origin', $markets[0]['overrides']['intrastate_sourcing'] );
		self::assertContains( 'CA', $markets[0]['overrides']['origin_states'] );

		// In-state sale in an origin state: the store address decides.
		$at = $this->tax()->location( new TaxLocation( 'US', 'CA', '90210' ), null );
		self::assertSame( '95014', $at?->postcode );
		$at = $this->tax()->location( new TaxLocation( 'US', 'NY', '10001' ), null );
		self::assertSame( 'NY', $at?->state );

		$this->presets()->apply( $preset, [ 'CA' ], [ 'market' ] );
		self::assertCount( 1, (array) Settings::get( 'tax_markets' ), 'applying again replaces the market' );
	}

	public function test_an_add_on_can_add_a_preset(): void {
		add_filter(
			'flexacommerce.tax.presets',
			static fn ( array $list ): array => [
				...$list,
				TaxPreset::from_array(
					[
						'id'      => 'ca',
						'name'    => 'Canada',
						'country' => 'CA',
						'as_of'   => '2025-01-01',
						'label'   => '{STATE} HST',
						'regions' => [ [ 'state' => 'on', 'rate' => '13', 'shipping' => true ] ],
					]
				),
			]
		);
		$preset = $this->presets()->get( 'ca' );
		self::assertNotNull( $preset );
		self::assertNotNull( $this->presets()->get( 'us' ) );
		self::assertSame( 1, $this->presets()->apply( $preset, [ 'ON' ], [] ) );
		$row = $this->rates()->all()[0];
		self::assertSame( [ 'CA', 'ON', 130000, 'ON HST', 'preset:ca' ], [ $row->country, $row->state, $row->rate, $row->name, $row->source ] );
	}
}
