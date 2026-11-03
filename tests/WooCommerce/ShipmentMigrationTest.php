<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Plugin;

/**
 * Tracking numbers (ADR-067): the Shipment Tracking extension's order meta
 * becomes shipments, and goes back in the same shape.
 */
final class ShipmentMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
	}

	public function test_tracking_round_trips(): void {
		$order = new \WC_Order();
		$order->set_customer_id( $this->store->customer );
		$order->set_billing_email( 'ana@example.com' );
		$order->add_product( wc_get_product( $this->store->products['tee'] ), 1 );
		$order->calculate_totals( false );
		$order->set_date_paid( time() );
		$order->set_status( 'completed' );
		$order->update_meta_data(
			'_wc_shipment_tracking_items',
			[
				[
					'tracking_provider'        => 'ups',
					'custom_tracking_provider' => '',
					'custom_tracking_link'     => '',
					'tracking_number'          => '1Z999AA10123456784',
					'date_shipped'             => '1767225600',
					'tracking_id'              => 'a1',
				],
				[
					'tracking_provider'        => '',
					'custom_tracking_provider' => 'Local Courier',
					'custom_tracking_link'     => 'https://courier.example/t/AB12',
					'tracking_number'          => 'AB12',
					'date_shipped'             => '1767312000',
					'tracking_id'              => 'a2',
				],
			]
		);
		$order_id = $order->save();

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );

		$fxcm_order = $this->local_id( $order_id );
		$shipments  = Plugin::instance()->orders()->shipments()->for_order( $fxcm_order );
		$this->assertCount( 2, $shipments );
		$this->assertSame( [ 'ups', 'UPS', '1Z999AA10123456784', 'https://www.ups.com/track?tracknum=1Z999AA10123456784', '2026-01-01 00:00:00' ], [ $shipments[0]->carrier, $shipments[0]->carrier_name, $shipments[0]->tracking_number, $shipments[0]->tracking_url, $shipments[0]->shipped_at ] );
		$this->assertSame( [ '', 'Local Courier', 'https://courier.example/t/AB12' ], [ $shipments[1]->carrier, $shipments[1]->carrier_name, $shipments[1]->tracking_url ] );
		global $wpdb;
		$copied = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d AND meta_key = %s', Schema::table( 'order_meta' ), $fxcm_order, '_wc_shipment_tracking_items' ) );
		$this->assertSame( 0, $copied, 'the meta became rows, not a copy' );

		// Back to WooCommerce.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = DATE_ADD(updated_at, INTERVAL 1 HOUR) WHERE id = %d', Schema::table( 'orders' ), $fxcm_order ) );
		$back = $this->run_job( Direction::FXC_TO_WC );
		$this->assertContains( $back->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $back->id ) ) );

		$items = wc_get_order( $order_id )->get_meta( '_wc_shipment_tracking_items', true, 'edit' );
		$this->assertIsArray( $items );
		$this->assertCount( 2, $items );
		$this->assertSame( [ 'ups', '', '1Z999AA10123456784', '1767225600' ], [ $items[0]['tracking_provider'], $items[0]['custom_tracking_provider'], $items[0]['tracking_number'], $items[0]['date_shipped'] ] );
		$this->assertSame( [ '', 'Local Courier', 'https://courier.example/t/AB12', 'AB12' ], [ $items[1]['tracking_provider'], $items[1]['custom_tracking_provider'], $items[1]['custom_tracking_link'], $items[1]['tracking_number'] ] );
		$this->assertNotSame( '', $items[1]['tracking_id'] );
	}

	private function run_job( string $direction ): Job {
		$job = $this->service->create(
			$direction,
			[
				'backup_confirmed' => true,
				'stages'           => [ 'settings', 'terms', 'attributes', 'products', 'customers', 'orders' ],
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
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT local_id FROM %i WHERE entity = %s AND remote_id = %s', Schema::table( 'id_map' ), 'order', (string) $wc_id ) );
	}
}
