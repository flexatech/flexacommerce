<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\ShippingClasses;
use Flexa\Commerce\Support\Settings;

/**
 * Shipping classes (ADR-068) move both ways: the class list, the flat rate
 * class costs and the class of each product and variation.
 */
final class ShippingClassMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;
	/** @var array<string,int> term ids by slug */
	private array $terms = [];

	public function set_up(): void {
		parent::set_up();
		Settings::update( [ 'shipping_classes' => [] ] );
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
		foreach ( [
			'bulky'   => 'Bulky',
			'fragile' => 'Fragile glass',
		] as $slug => $name ) {
			$term                 = wp_insert_term( $name, 'product_shipping_class', [ 'slug' => $slug ] );
			$this->terms[ $slug ] = (int) $term['term_id'];
		}
		$zone   = \WC_Shipping_Zones::get_zone( $this->store->zones['california'] );
		$ground = array_values( $zone->get_shipping_methods() )[0];
		update_option(
			$ground->get_instance_option_key(),
			array_merge(
				$ground->instance_settings,
				[
					'class_cost_' . $this->terms['bulky'] => '5 + 2 * [qty]',
					'no_class_cost'                       => '1',
					'type'                                => 'order',
				]
			)
		);
		$tee = wc_get_product( $this->store->products['tee'] );
		$tee->set_shipping_class_id( $this->terms['bulky'] );
		$tee->save();
		$variation = wc_get_product( $this->store->variations['red_large'] );
		$variation->set_shipping_class_id( $this->terms['fragile'] );
		$variation->save();
	}

	public function tear_down(): void {
		Settings::update( [ 'shipping_classes' => [] ] );
		parent::tear_down();
	}

	public function test_classes_and_class_costs_are_imported_and_written_back(): void {
		$job = $this->run_job( Direction::WC_TO_FXC, [ 'settings', 'shipping', 'terms', 'attributes', 'products' ] );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame(
			[
				[
					'slug' => 'bulky',
					'name' => 'Bulky',
				],
				[
					'slug' => 'fragile',
					'name' => 'Fragile glass',
				],
			],
			ShippingClasses::all()
		);
		$ground = Plugin::instance()->shipping()->service()->zones()->all()[0]->methods[0];
		$this->assertTrue( $ground->enabled );
		$this->assertSame(
			[
				'calculation' => 'per_order',
				'costs'       => [
					'bulky' => [
						'cost'     => 500,
						'per_item' => 200,
					],
					''      => [
						'cost'     => 100,
						'per_item' => 0,
					],
				],
			],
			$ground->settings['class_costs']
		);

		$products = Plugin::instance()->catalog()->product_service();
		$tee      = $products->get( $this->local_id( $this->store->products['tee'] ) );
		$this->assertSame( 'bulky', $tee->base->shipping_class );
		$hoodie = $products->get( $this->local_id( $this->store->products['hoodie'] ) );
		$skus   = array_column( array_map( static fn ( $v ): array => [ (string) $v->sku, $hoodie->shipping_class_of( $v ) ], $hoodie->variations ), 1, 0 );
		$this->assertSame( 'fragile', $skus['HOOD-RL'] );
		$this->assertSame( '', $hoodie->base->shipping_class );

		// Changed in FlexaCommerce, then written back to WooCommerce.
		$products->save( $tee->id, ProductInput::from_array( [ 'base' => [ 'shipping_class' => 'heavy' ] ] ) );
		Settings::update(
			[
				'shipping_classes' => [
					...ShippingClasses::all(),
					[
						'slug' => 'heavy',
						'name' => 'Heavy',
					],
				],
			]
		);
		// Same second as the import, so force the write.
		$job = $this->run_job( Direction::FXC_TO_WC, [ 'settings', 'shipping', 'terms', 'attributes', 'products' ], true );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$heavy = get_term_by( 'slug', 'heavy', 'product_shipping_class' );
		$this->assertInstanceOf( \WP_Term::class, $heavy );
		$this->assertSame( 'Heavy', $heavy->name );
		$this->assertSame( $heavy->term_id, wc_get_product( $this->store->products['tee'] )->get_shipping_class_id() );
		$this->assertSame( $this->terms['fragile'], wc_get_product( $this->store->variations['red_large'] )->get_shipping_class_id() );

		$zone   = \WC_Shipping_Zones::get_zone( $this->store->zones['california'] );
		$ground = array_values( $zone->get_shipping_methods() )[0];
		$this->assertSame( 'order', $ground->get_instance_option( 'type' ) );
		$this->assertSame( '5 + 2 * [qty]', $ground->get_instance_option( 'class_cost_' . $this->terms['bulky'] ) );
		$this->assertSame( '1', $ground->get_instance_option( 'no_class_cost' ) );
		$this->assertSame( '', $ground->get_instance_option( 'class_cost_' . $heavy->term_id ) );
	}

	public function test_a_product_import_without_the_shipping_stage_keeps_the_class(): void {
		$job = $this->run_job( Direction::WC_TO_FXC, [ 'terms', 'attributes', 'products' ] );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame(
			[
				[
					'slug' => 'bulky',
					'name' => 'Bulky',
				],
				[
					'slug' => 'fragile',
					'name' => 'Fragile',
				],
			],
			ShippingClasses::all(),
			'A class the job did not bring is named after its slug.'
		);
	}

	/** @param list<string> $stages */
	private function run_job( string $direction, array $stages, bool $force = false ): Job {
		$job = $this->service->create(
			$direction,
			[
				'backup_confirmed' => true,
				'stages'           => $stages,
				'force'            => $force,
			],
			false,
			1
		);
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}

	private function local_id( int $wc_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT local_id FROM %i WHERE entity = %s AND remote_id = %s', Schema::table( 'id_map' ), 'product', (string) $wc_id ) );
	}
}
