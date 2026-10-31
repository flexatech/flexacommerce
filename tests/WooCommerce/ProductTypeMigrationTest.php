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

/**
 * Grouped and external products (ADR-062) move both ways: a group keeps its
 * products in order, an external product keeps its link and button text.
 */
final class ProductTypeMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
	}

	public function test_grouped_and_external_products_round_trip(): void {
		$tee     = $this->store->products['tee'];
		$sale    = $this->store->products['sale'];
		$grouped = new \WC_Product_Grouped();
		$grouped->set_name( 'Tee Pack' );
		$grouped->set_status( 'publish' );
		$grouped->set_children( [ $sale, $tee ] );
		$group_id = $grouped->save();
		$external = new \WC_Product_External();
		$external->set_name( 'Partner Lamp' );
		$external->set_status( 'publish' );
		$external->set_regular_price( '80' );
		$external->set_product_url( 'https://partner.example/lamp' );
		$external->set_button_text( 'Buy at Partner' );
		$external_id = $external->save();

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );

		$products = Plugin::instance()->catalog()->product_service();
		$group    = $products->get( $this->local_id( $group_id ) );
		$this->assertTrue( $group->is_grouped() );
		$this->assertSame( [ $this->local_id( $sale ), $this->local_id( $tee ) ], $group->children );
		$lamp = $products->get( $this->local_id( $external_id ) );
		$this->assertTrue( $lamp->is_external() );
		$this->assertSame( [ 'https://partner.example/lamp', 'Buy at Partner', 8000 ], [ $lamp->product_url, $lamp->button_text, $lamp->base->regular_price ] );

		// Changed in FlexaCommerce, then written back to WooCommerce.
		$products->save(
			$group->id,
			ProductInput::from_array(
				[
					'type'     => 'grouped',
					'children' => [ $this->local_id( $tee ) ],
				]
			)
		);
		$products->save(
			$lamp->id,
			ProductInput::from_array(
				[
					'type'        => 'external',
					'product_url' => 'https://partner.example/lamp-2',
					'button_text' => 'See the lamp',
				]
			)
		);
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_modified_gmt = DATE_ADD(post_modified_gmt, INTERVAL 1 HOUR) WHERE ID IN (%d, %d)", $group->id, $lamp->id ) );
		clean_post_cache( $group->id );
		clean_post_cache( $lamp->id );

		$back = $this->run_job( Direction::FXC_TO_WC );
		$this->assertContains( $back->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $back->id ) ) );
		$wc_group = wc_get_product( $group_id );
		$this->assertInstanceOf( \WC_Product_Grouped::class, $wc_group );
		$this->assertSame( [ $tee ], array_map( 'intval', $wc_group->get_children() ) );
		$wc_lamp = wc_get_product( $external_id );
		$this->assertInstanceOf( \WC_Product_External::class, $wc_lamp );
		$this->assertSame( [ 'https://partner.example/lamp-2', 'See the lamp' ], [ $wc_lamp->get_product_url(), $wc_lamp->get_button_text() ] );
	}

	private function run_job( string $direction ): Job {
		$job = $this->service->create(
			$direction,
			[
				'backup_confirmed' => true,
				'stages'           => [ 'settings', 'terms', 'attributes', 'products' ],
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
