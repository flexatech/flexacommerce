<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Migration\Sources\WooShippingReader;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;

/** The shipping stage (doc 05 §5.7): zones and methods, both directions. */
final class ShippingMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;
	private ZoneRepository $zones;

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
		$this->zones   = Plugin::instance()->shipping()->service()->zones();
	}

	public function test_zones_and_methods_are_imported(): void {
		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertSame( JobStatus::COMPLETED, $job->status, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 1, $job->counter( 'shipping', 'created' ) );

		[ $california, $canada, $rest ] = $this->zones->all();
		$this->assertSame( 'California', $california->name );
		$this->assertSame( [ 'state:US:CA' ], $california->regions );
		$this->assertSame( [ '90*' ], $california->postcodes );
		$this->assertSame( [ 'flat_rate', 'free_shipping' ], array_map( static fn ( MethodInstance $m ): string => $m->type, $california->methods ) );
		$this->assertSame( 500, $california->methods[0]->settings['cost'] );
		$this->assertSame( 150, $california->methods[0]->settings['cost_per_item'] );
		$this->assertSame( 'Ground', $california->methods[0]->settings['title'] );
		$this->assertSame( 10000, $california->methods[1]->settings['min_amount'] );

		$this->assertSame( [ 'country:CA' ], $canada->regions );
		$this->assertSame( 'none', $canada->methods[0]->settings['tax_status'] );
		$this->assertSame( 300, $canada->methods[0]->settings['cost'] );
		$this->assertFalse( $canada->methods[1]->enabled, 'A method turned off in WooCommerce stays off.' );

		$this->assertSame( Zone::REST_OF_WORLD, $rest->id );
		$this->assertSame( 0, $rest->methods[0]->settings['cost'] );
		$this->assertSame( 200, $rest->methods[0]->settings['cost_per_item'] );
	}

	public function test_a_method_with_a_price_fxc_cannot_reproduce_is_imported_off(): void {
		$europe = new \WC_Shipping_Zone();
		$europe->set_zone_name( 'Europe' );
		$europe->set_zone_order( 3 );
		$europe->add_location( 'EU', 'continent' );
		$europe->save();
		GoldenStore::method(
			$europe,
			'flat_rate',
			[
				'title' => 'Percent',
				'cost'  => '10 + [fee percent="10"]',
			]
		);
		GoldenStore::method(
			$europe,
			'flat_rate',
			[
				'title'         => 'By class',
				'cost'          => '4',
				'class_cost_12' => '2',
			]
		);
		GoldenStore::method(
			$europe,
			'free_shipping',
			[
				'title'    => 'Coupon only',
				'requires' => 'coupon',
			]
		);
		GoldenStore::method(
			$europe,
			'free_shipping',
			[
				'title'            => 'Either',
				'requires'         => 'either',
				'min_amount'       => '80',
				'ignore_discounts' => 'yes',
			]
		);

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertSame( JobStatus::COMPLETED_WITH_WARNINGS, $job->status );
		$europe = $this->zones->all()[2];
		$this->assertContains( 'country:FR', $europe->regions, 'A continent becomes its countries.' );
		$this->assertContains( 'country:DE', $europe->regions );
		$this->assertSame( [ false, false, true, true ], array_map( static fn ( MethodInstance $m ): bool => $m->enabled, $europe->methods ) );
		$this->assertSame( 400, $europe->methods[1]->settings['cost'] );
		$this->assertSame( 'coupon', $europe->methods[2]->settings['requires'], 'Free shipping with a coupon is kept.' );
		$this->assertSame( 'either', $europe->methods[3]->settings['requires'] );
		$this->assertSame( 8000, $europe->methods[3]->settings['min_amount'] );
		$this->assertTrue( $europe->methods[3]->settings['ignore_discounts'], 'The minimum before coupons is kept.' );
		$this->assertFalse( $europe->methods[2]->settings['ignore_discounts'] );

		$codes = array_count_values( array_column( $this->service->log( $job->id )['rows'], 'code' ) );
		$this->assertSame( 2, $codes['shipping_method_disabled'] ?? 0 );
		$this->assertSame( 2, $codes['shipping_method_changed'] ?? 0 );
	}

	public function test_a_second_import_replaces_its_zones_and_keeps_the_ones_made_in_fxc(): void {
		$this->run_job( Direction::WC_TO_FXC );
		[ $california, $canada, $rest ] = $this->zones->all();
		$native_method                  = new MethodInstance(
			0,
			'flat_rate',
			true,
			[
				'title' => 'Courier',
				'cost'  => 900,
			]
		);
		$this->zones->save(
			[
				$california,
				new Zone( 0, 'Texas', [ 'state:US:TX' ], [], [ new MethodInstance( 0, 'free_shipping' ) ] ),
				new Zone( $canada->id, $canada->name, $canada->regions, [], [ ...$canada->methods, $native_method ] ),
				$rest,
			]
		);

		$zone = \WC_Shipping_Zones::get_zone( $this->store->zones['california'] );
		$zone->set_zone_name( 'California and Nevada' );
		$zone->add_location( 'US:NV', 'state' );
		$zone->save();
		$ground = array_key_first( $zone->get_shipping_methods() );
		update_option(
			'woocommerce_flat_rate_' . $ground . '_settings',
			[
				'title'      => 'Ground',
				'tax_status' => 'taxable',
				'cost'       => '6',
			]
		);

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertSame( 1, $job->counter( 'shipping', 'updated' ) );
		$zones = $this->zones->all();
		$this->assertSame( [ 'California and Nevada', 'Texas', 'Canada', 'Everywhere else' ], array_map( static fn ( Zone $z ): string => $z->name, $zones ) );
		$this->assertSame( $california->id, $zones[0]->id, 'The imported zone is updated in place.' );
		$this->assertSame( [ 'state:US:CA', 'state:US:NV' ], $zones[0]->regions );
		$this->assertSame( $california->methods[0]->instance_id, $zones[0]->methods[0]->instance_id );
		$this->assertSame( 600, $zones[0]->methods[0]->settings['cost'] );
		$this->assertSame( 0, $zones[0]->methods[0]->settings['cost_per_item'] );
		$this->assertCount( 3, $zones[2]->methods, 'A method added in FlexaCommerce stays.' );
		$this->assertSame( 'Courier', $zones[2]->methods[2]->settings['title'] );
	}

	public function test_zones_go_back_to_woocommerce(): void {
		$this->run_job( Direction::WC_TO_FXC );
		$zones = $this->zones->all();
		$this->zones->save(
			[
				...array_slice( $zones, 0, 2 ),
				new Zone(
					0,
					'Texas',
					[ 'state:US:TX' ],
					[],
					[
						new MethodInstance(
							0,
							'local_pickup',
							true,
							[
								'title'    => 'Warehouse',
								'cost'     => 250,
								'location' => '1 Dock Rd',
							]
						),
						new MethodInstance(
							0,
							'free_shipping',
							true,
							[
								'requires'         => 'either',
								'min_amount'       => 5000,
								'ignore_discounts' => true,
							]
						),
					]
				),
				$zones[2],
			]
		);

		$job = $this->run_job( Direction::FXC_TO_WC );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$names = array_values( array_map( static fn ( \WC_Shipping_Zone $z ): string => $z->get_zone_name(), \WC_Shipping_Zones::get_shipping_zones() ) );
		$this->assertSame( [ 'California', 'Canada', 'Texas' ], $names, 'Imported zones are updated, the new one is added.' );
		$this->assertCount( 2, \WC_Shipping_Zones::get_zone( $this->store->zones['california'] )->get_shipping_methods(), 'No method is added twice.' );

		$texas  = array_values( \WC_Shipping_Zones::get_shipping_zones() )[2];
		$pickup = array_values( $texas->get_shipping_methods() )[0];
		$this->assertSame( 'local_pickup', $pickup->id );
		$this->assertSame( '2.5', $pickup->get_instance_option( 'cost' ) );
		$free = array_values( $texas->get_shipping_methods() )[1];
		$this->assertSame( [ 'either', '50' ], [ $free->get_instance_option( 'requires' ), $free->get_instance_option( 'min_amount' ) ] );
		$this->assertSame( 'yes', $free->get_instance_option( 'ignore_discounts' ) );
		$codes = array_column( $this->service->log( $job->id )['rows'], 'code' );
		$this->assertContains( 'pickup_location', $codes );
	}

	public function test_cost_formulas(): void {
		$this->assertSame( [ '5', '0' ], WooShippingReader::cost( '5' ) );
		$this->assertSame( [ '0', '0' ], WooShippingReader::cost( '' ) );
		$this->assertSame( [ '5', '1.5' ], WooShippingReader::cost( '5 + 1.50 * [qty]' ) );
		$this->assertSame( [ '0', '2' ], WooShippingReader::cost( '[qty] * 2' ) );
		$this->assertSame( [ '0', '1' ], WooShippingReader::cost( '[qty]' ) );
		$this->assertNull( WooShippingReader::cost( '10 + [fee percent="10"]' ) );
		$this->assertNull( WooShippingReader::cost( '10 - 2' ) );
		$this->assertNull( WooShippingReader::cost( '2 * [qty] + 3 * [qty]' ) );
	}

	private function run_job( string $direction ): Job {
		$job = $this->service->create(
			$direction,
			[
				'backup_confirmed' => true,
				'stages'           => [ 'settings', 'shipping' ],
			],
			false,
			1
		);
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}
}
