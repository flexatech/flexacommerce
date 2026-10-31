<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Downloads\PermissionRepository;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Plugin;

/**
 * Downloadable products (ADR-058): files, limit and expiry move with the
 * product, and download permissions keep their counts with the order.
 */
final class DownloadMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;
	private string $file = '';

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
		$uploads       = wp_get_upload_dir();
		$this->file    = $uploads['basedir'] . '/fxc-download-test.pdf';
		file_put_contents( $this->file, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public function tear_down(): void {
		wp_delete_file( $this->file );
		parent::tear_down();
	}

	public function test_files_and_permissions_round_trip(): void {
		$url = wp_get_upload_dir()['baseurl'] . '/fxc-download-test.pdf';
		wc_get_container()->get( Register::class )->add_approved_directory( wp_get_upload_dir()['baseurl'] );
		$product = new \WC_Product_Simple();
		$product->set_name( 'Ebook' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '9' );
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$file = new \WC_Product_Download();
		$file->set_id( 'ebook-pdf' );
		$file->set_name( 'Ebook (PDF)' );
		$file->set_file( $url );
		$product->set_downloads( [ $file ] );
		$product->set_download_limit( 3 );
		$product->set_download_expiry( 30 );
		$product_id = $product->save();

		$order = new \WC_Order();
		$order->set_customer_id( $this->store->customer );
		$order->set_billing_email( 'ana@example.com' );
		$order->add_product( wc_get_product( $product_id ), 2 );
		$order->calculate_totals( false );
		$order->set_date_paid( time() );
		$order->set_status( 'completed' );
		$order_id = $order->save();
		wc_downloadable_product_permissions( $order_id, true );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}woocommerce_downloadable_product_permissions SET downloads_remaining = '4', download_count = 2 WHERE order_id = %d", $order_id ) );

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );

		$fxc     = Plugin::instance()->catalog()->product_service()->get( $this->local_id( 'product', $product_id ) );
		$variant = $fxc->base;
		$this->assertTrue( $variant->virtual );
		$this->assertTrue( $variant->downloadable );
		$this->assertSame( [ 'ebook-pdf' ], $variant->downloads->ids() );
		$this->assertSame( 'Ebook (PDF)', $variant->downloads->files[0]['name'] );
		$this->assertSame( [ 3, 30 ], [ $variant->downloads->limit, $variant->downloads->expiry_days ] );

		$fxc_order   = $this->local_id( 'order', $order_id );
		$permissions = ( new PermissionRepository( $wpdb ) )->for_order( $fxc_order );
		$this->assertCount( 1, $permissions );
		$this->assertSame( [ 'ebook-pdf', 4, 2, 'ana@example.com' ], [ $permissions[0]->file_id, $permissions[0]->downloads_remaining, $permissions[0]->download_count, $permissions[0]->email ] );
		$this->assertNotNull( $permissions[0]->access_expires );
		$item = Plugin::instance()->orders()->orders()->find( $fxc_order )?->items[0] ?? null;
		$this->assertTrue( ! empty( $item?->meta['downloadable'] ) && ! empty( $item->meta['virtual'] ), 'The line is marked so the order page looks for links.' );

		// Back to WooCommerce: the order keeps its counts and is not granted again.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET downloads_remaining = 1 WHERE order_id = %d', Schema::table( 'downloads' ), $fxc_order ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = DATE_ADD(updated_at, INTERVAL 1 HOUR) WHERE id = %d', Schema::table( 'orders' ), $fxc_order ) );
		$back = $this->run_job( Direction::FXC_TO_WC );
		$this->assertContains( $back->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $back->id ) ) );

		$wc_product = wc_get_product( $product_id );
		$this->assertTrue( $wc_product->is_downloadable() );
		$this->assertSame( [ 'ebook-pdf' ], array_keys( $wc_product->get_downloads() ) );
		$this->assertSame( 3, $wc_product->get_download_limit() );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT download_id, downloads_remaining, download_count FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d", $order_id ), ARRAY_A );
		$this->assertSame(
			[
				[
					'download_id'         => 'ebook-pdf',
					'downloads_remaining' => '1',
					'download_count'      => '2',
				],
			],
			$rows
		);
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

	private function local_id( string $entity, int $wc_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT local_id FROM %i WHERE entity = %s AND remote_id = %s', Schema::table( 'id_map' ), $entity, (string) $wc_id ) );
	}
}
