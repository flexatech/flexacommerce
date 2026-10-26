<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationError;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Plugin;

/**
 * WC→FXC against the golden store, in the storage mode of the run (doc 05
 * §11): mapping, verification, zero side effects, idempotent re-runs,
 * crash recovery and purge.
 */
final class MigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
	}

	public function test_preflight_reports_the_store(): void {
		$job   = $this->service->create( Direction::WC_TO_FXC, [], true, 1 );
		$codes = array_column( $job->report['preflight'], 'code' );
		$this->assertSame( JobStatus::READY, $job->status );
		foreach ( [ 'storage_mode', 'unsupported_type', 'duplicate_sku', 'custom_status', 'inventory', 'environment' ] as $code ) {
			$this->assertContains( $code, $codes );
		}
		$inventory = $job->report['preflight'][ array_search( 'inventory', $codes, true ) ]['context']['counts'];
		$this->assertSame( 9, $inventory['orders'] );
		$this->assertSame( 8, $inventory['products'] );
	}

	public function test_dry_run_writes_only_the_job(): void {
		$job = $this->import( true );
		$this->assertContains( $job->status, JobStatus::FINISHED );
		$this->assertSame( 9, $job->counter( 'orders', 'created' ) );
		$this->assertSame( 0, $this->rows_in( 'orders' ) );
		$this->assertSame( 0, $this->rows_in( 'id_map' ) );
		$this->assertSame( [], get_posts( [ 'post_type' => 'fxc_product', 'post_status' => 'any' ] ) );
	}

	public function test_real_run_needs_a_backup(): void {
		$job = $this->service->create( Direction::WC_TO_FXC, [], false, 1 );
		$this->expectException( MigrationError::class );
		$this->service->start( $job->id );
	}

	public function test_full_import(): void {
		$stock_before = $this->woo_stock();
		$mails        = 0;
		$hooks        = 0;
		$count_mail   = static function ( array $args ) use ( &$mails ): array {
			++$mails;
			return $args;
		};
		$count_hook   = static function () use ( &$hooks ): void {
			++$hooks;
		};
		add_filter( 'wp_mail', $count_mail );
		foreach ( [ 'flexacommerce.order.created', 'flexacommerce.order.status_changed', 'flexacommerce.order.paid', 'woocommerce_reduce_order_stock' ] as $hook ) {
			add_action( $hook, $count_hook );
		}

		$job = $this->import();

		$this->assertSame( JobStatus::COMPLETED_WITH_WARNINGS, $job->status );
		$this->assertSame( [], $job->report['mismatches'] );
		$this->assertSame( 9, $job->report['checked'] );
		$this->assertSame( 0, $mails, 'No mail leaves the site during a batch.' );
		$this->assertSame( 0, $hooks, 'No order or stock hooks fire during an import.' );
		$this->assertSame( $stock_before, $this->woo_stock(), 'WooCommerce stock is untouched.' );

		$this->assertSame( 7, $job->counter( 'products', 'created' ) );
		$this->assertSame( 1, $job->counter( 'products', 'rejected' ) );
		$this->assertSame( 9, $job->counter( 'orders', 'created' ) );
		$codes = array_column( $this->service->log( $job->id )['rows'], 'code' );
		sort( $codes );
		$this->assertSame( [ 'custom_status', 'duplicate_sku', 'unsupported_type' ], $codes );

		$this->assert_products();
		$this->assert_orders();
	}

	public function test_rerun_without_changes_writes_nothing(): void {
		$first = $this->import();
		$this->service->start( $first->id );
		$again = $this->service->run( $first->id );
		$totals = $again->totals();
		$this->assertArrayNotHasKey( 'created', $totals );
		$this->assertArrayNotHasKey( 'updated', $totals );
		// settings, tax rate, 2 attributes, 6 terms (2 + 4 attribute values), 7 products, customer, 9 orders.
		$this->assertSame( 1 + 1 + 2 + 6 + 7 + 1 + 9, $totals['unchanged'] );
		$this->assertSame( 1, $again->counter( 'products', 'rejected' ), 'The grouped product is rejected again.' );
		$this->assertSame( 9, $this->rows_in( 'orders' ) );
	}

	public function test_rerun_picks_up_a_changed_order(): void {
		$job   = $this->import();
		$order = wc_get_order( $this->store->orders['coupon_fee'] );
		$order->set_status( 'completed' );
		$order->save();
		$this->service->start( $job->id );
		$again = $this->service->run( $job->id );
		$this->assertSame( 1, $again->counter( 'orders', 'updated' ) );
		$this->assertSame( 'completed', $this->order_row( 'coupon_fee' )['status'] );
	}

	public function test_crash_and_resume(): void {
		$crash = static function ( string $entity ) use ( &$crash ): void {
			static $orders = 0;
			if ( $entity === 'order' && ++$orders === 4 ) {
				throw new \RuntimeException( 'Simulated crash.' );
			}
		};
		add_action( 'flexacommerce.migration.entity_imported', $crash );
		$job = $this->service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true ], false, 1 );
		$this->service->start( $job->id );
		$failed = $this->service->run( $job->id );
		$this->assertSame( JobStatus::FAILED, $failed->status );
		$this->assertSame( 4, $this->rows_in( 'orders' ) );
		remove_action( 'flexacommerce.migration.entity_imported', $crash );

		// A dead runner left its lease behind; it expired.
		global $wpdb;
		$wpdb->update( Schema::table( 'migration_jobs' ), [ 'lock_token' => 'dead', 'locked_until' => '2000-01-01 00:00:00' ], [ 'id' => $job->id ] );
		$this->service->start( $job->id );
		$done = $this->service->run( $job->id );
		$this->assertContains( $done->status, JobStatus::FINISHED );
		$this->assertSame( 9, $this->rows_in( 'orders' ) );
		$this->assertSame( 9, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE entity = %s', Schema::table( 'id_map' ), 'order' ) ) );
		$this->assertSame( [], $done->report['mismatches'] );
	}

	public function test_a_held_lease_blocks_a_second_runner(): void {
		$job = $this->service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true ], false, 1 );
		$this->service->start( $job->id );
		global $wpdb;
		$wpdb->update( Schema::table( 'migration_jobs' ), [ 'lock_token' => 'other', 'locked_until' => '2999-01-01 00:00:00' ], [ 'id' => $job->id ] );
		$result = $this->service->run_batch( $job->id );
		$this->assertTrue( $result['locked'] );
		$this->assertSame( 0, $result['processed'] );
	}

	public function test_every_batch_reports_its_peak_within_the_budget(): void {
		$job = $this->service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true ], false, 1 );
		$this->service->start( $job->id );
		$results = [];
		$this->service->run(
			$job->id,
			static function ( array $result ) use ( &$results ): void {
				$results[] = $result;
			}
		);
		$this->assertNotEmpty( $results );
		foreach ( $results as $result ) {
			$this->assertGreaterThan( 0, $result['peak'] );
			$this->assertLessThan( 128 * MB_IN_BYTES, $result['peak'], 'P9: batch peak memory under 128 MB.' );
			$this->assertGreaterThanOrEqual( 0, $result['growth'] );
			$this->assertLessThanOrEqual( $result['peak'], $result['growth'] );
		}
	}

	public function test_the_memory_budget_counts_growth_not_what_the_site_already_uses(): void {
		// Smaller than what the loaded site uses: as an absolute cap it would end every batch after one entity.
		$job = $this->service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true, 'memory_budget' => intdiv( memory_get_usage(), 2 ) ], false, 1 );
		$this->service->start( $job->id );
		$this->assertGreaterThan( 1, $this->service->run_batch( $job->id )['processed'] );
	}

	public function test_a_batch_stops_near_the_memory_limit(): void {
		$job = $this->service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true ], false, 1 );
		$this->service->start( $job->id );
		$limit = (string) ini_get( 'memory_limit' );
		// PHP refuses a limit under the memory in use, so this one puts usage just over 80 %.
		ini_set( 'memory_limit', (string) (int) ( memory_get_usage() * 1.2 ) );
		try {
			$processed = $this->service->run_batch( $job->id )['processed'];
		} finally {
			ini_set( 'memory_limit', $limit );
		}
		$this->assertSame( 1, $processed );
	}

	public function test_imported_pending_orders_do_not_expire(): void {
		$this->import();
		$stale = Plugin::instance()->orders()->orders()->stale_pending( '2999-01-01 00:00:00', 100 );
		$this->assertSame( [], $stale );
	}

	public function test_purge_removes_what_the_job_created(): void {
		$job    = $this->import();
		$result = $this->service->purge( $job->id );
		$this->assertSame( 9, $result['order']['deleted'] );
		$this->assertSame( 7, $result['product']['deleted'] );
		$this->assertSame( 0, $this->rows_in( 'orders' ) );
		$this->assertSame( 0, $this->rows_in( 'order_items' ) );
		$this->assertSame( [], get_posts( [ 'post_type' => 'fxc_product', 'post_status' => 'any' ] ) );
	}

	private function assert_products(): void {
		$tee = $this->variants( $this->store->products['tee'] );
		$this->assertCount( 1, $tee );
		$this->assertSame( 'TEE-1', $tee[0]['sku'] );
		$this->assertSame( '2000', $tee[0]['regular_price'] );
		$this->assertSame( (string) wc_get_product( $this->store->products['tee'] )->get_stock_quantity(), $tee[0]['stock_quantity'] );
		$this->assertSame( '500.000', $tee[0]['weight'] );
		$this->assertSame( '100.000', $tee[0]['length'] );
		$post = get_post( $this->target( 'product', $this->store->products['tee'] ) );
		$this->assertSame( 'Plain Tee', $post->post_title );
		$this->assertSame( 'plain-tee', $post->post_name );

		$sale = $this->variants( $this->store->products['sale'] )[0];
		$this->assertSame( '4000', $sale['sale_price'] );
		$this->assertSame( '2026-01-01 00:00:00', $sale['sale_from'] );

		$dupe = $this->variants( $this->store->products['dupe'] )[0];
		$this->assertNull( $dupe['sku'], 'The later duplicate SKU is left empty.' );
		$this->assertSame( 'Áo dài Việt Nam', get_post( $this->target( 'product', $this->store->products['unicode'] ) )->post_title );
		$this->assertSame( 'draft', get_post_status( $this->target( 'product', $this->store->products['draft'] ) ) );

		$hoodie = $this->variants( $this->store->products['hoodie'] );
		$this->assertCount( 3, $hoodie, 'Base row plus two variations.' );
		$by_sku = array_column( $hoodie, null, 'sku' );
		$this->assertSame( '3000', $by_sku['HOOD-RL']['regular_price'] );
		$this->assertSame( '1', $by_sku['HOOD-B']['stock_from_base'] );
		$this->assertSame( '1', $by_sku['HOOD']['is_base'] );
		$this->assertSame( (string) wc_get_product( $this->store->products['hoodie'] )->get_stock_quantity(), $by_sku['HOOD']['stock_quantity'] );
		$this->assertSame( $this->target( 'variant', $this->store->variations['red_large'] ), (int) $by_sku['HOOD-RL']['id'] );

		$scarf = array_column( array_filter( $this->variants( $this->store->products['scarf'] ), static fn ( array $row ): bool => $row['sku'] !== null ), null, 'sku' );
		$this->assertSame( '1800', $scarf['SCARF-W']['regular_price'] );

		$this->assertNotSame( 0, $this->target( 'term', $this->store->terms['shirts'] ) );
		$cats = wp_get_object_terms( $this->target( 'product', $this->store->products['tee'] ), 'fxc_product_cat', [ 'fields' => 'slugs' ] );
		$this->assertSame( [ 'shirts' ], $cats );
	}

	private function assert_orders(): void {
		foreach ( $this->store->orders as $name => $id ) {
			if ( $name === 'checkout_draft' ) {
				$this->assertSame( 0, $this->target( 'order', $id ), 'Checkout drafts are not imported.' );
				continue;
			}
			$row = $this->order_row( $name );
			$this->assertSame( (string) $id, $row['order_number'], "{$name}: the number is kept" );
			$woo = wc_get_order( $id );
			$this->assertSame( (int) round( (float) $woo->get_total() * 100 ), (int) $row['total'], "{$name}: total" );
			$this->assertSame( $woo->get_order_key(), $row['order_key'], "{$name}: order key" );
		}

		$completed = $this->order_row( 'completed' );
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 'paid', $completed['payment_status'] );
		$this->assertSame( $completed['total'], $completed['amount_paid'] );
		$payments = $this->rows( 'transactions', (int) $completed['id'] );
		$this->assertCount( 1, $payments );
		$this->assertSame( 'import', $payments[0]['method'] );
		$this->assertSame( 'ch_completed', $payments[0]['gateway_reference'] );
		$notes = array_column( array_filter( $this->rows( 'order_history', (int) $completed['id'] ), static fn ( array $h ): bool => $h['type'] === 'note' ), 'customer_visible', 'message' );
		$this->assertSame( '0', $notes['Packed by the warehouse.'] );
		$this->assertSame( '1', $notes['Thanks for your order!'] );
		$addresses = array_column( $this->rows( 'order_addresses', (int) $completed['id'] ), null, 'type' );
		$this->assertSame( '94105', $addresses['billing']['postcode'] );
		$this->assertSame( '94105', $addresses['shipping']['postcode'] );

		$coupon = $this->order_row( 'coupon_fee' );
		$types  = array_count_values( array_column( $this->rows( 'order_items', (int) $coupon['id'] ), 'type' ) );
		$this->assertSame( 2, $types['product'] );
		$this->assertSame( 1, $types['fee'] );
		$this->assertSame( 1, $types['shipping'] );
		$this->assertSame( 1, $types['discount'] ?? $types['coupon'] ?? 0 );
		$this->assertGreaterThan( 0, (int) $coupon['discount_total'] );

		$partial = $this->order_row( 'partial_refund' );
		$this->assertSame( 'processing', $partial['status'] );
		$this->assertSame( '2200', $partial['amount_refunded'] );
		$this->assertSame( 'partially_refunded', $partial['payment_status'] );
		$tee_line = array_values( array_filter( $this->rows( 'order_items', (int) $partial['id'] ), static fn ( array $i ): bool => $i['sku'] === 'TEE-1' ) )[0];
		$this->assertSame( '1', $tee_line['refunded_qty'] );
		$this->assertSame( '2200', $tee_line['refunded_total'] );
		$refunds = array_values( array_filter( $this->rows( 'transactions', (int) $partial['id'] ), static fn ( array $t ): bool => $t['type'] === 'refund' ) );
		$this->assertCount( 1, $refunds );
		$this->assertSame( '2200', $refunds[0]['amount'] );

		$refunded = $this->order_row( 'refunded' );
		$this->assertSame( 'refunded', $refunded['status'] );
		$this->assertSame( $refunded['total'], $refunded['amount_refunded'] );

		$guest = $this->order_row( 'guest_pending' );
		$this->assertSame( 'pending', $guest['status'] );
		$this->assertNull( $guest['customer_id'] );

		$custom = $this->order_row( 'custom' );
		$this->assertSame( 'on-hold', $custom['status'] );
		$this->assertSame( '1', $custom['needs_attention'] );

		$this->assertNotNull( $this->order_row( 'cancelled' )['cancelled_at'] );
		$trashed = $this->order_row( 'trashed' );
		$this->assertSame( 'on-hold', $trashed['status'] );
		$this->assertNotNull( $trashed['deleted_at'] );

		$orphan = $this->rows( 'order_items', (int) $this->order_row( 'deleted_product' )['id'] );
		$line   = array_values( array_filter( $orphan, static fn ( array $i ): bool => $i['type'] === 'product' ) )[0];
		$this->assertNull( $line['product_id'], 'A line whose product is gone keeps its name and price.' );
		$this->assertSame( 'Doomed Item', $line['name'] );
	}

	private function import( bool $dry_run = false ): Job {
		$job = $this->service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true ], $dry_run, 1 );
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}

	/** @return array<string,int|null> */
	private function woo_stock(): array {
		$out = [];
		foreach ( [ ...$this->store->products, ...$this->store->variations ] as $name => $id ) {
			$product      = wc_get_product( $id );
			$out[ $name ] = $product instanceof \WC_Product ? $product->get_stock_quantity() : null;
		}
		return $out;
	}

	private function target( string $entity, int $wc_id ): int {
		$row = Plugin::instance()->migration()->ids()->by_remote( $entity, (string) $wc_id );
		return $row === null ? 0 : $row->local_id;
	}

	/** @return list<array<string,string|null>> */
	private function variants( int $wc_product ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE product_id = %d ORDER BY is_base DESC, id', Schema::table( 'variants' ), $this->target( 'product', $wc_product ) ),
			ARRAY_A
		);
	}

	/** @return array<string,string|null> */
	private function order_row( string $name ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'orders' ), $this->target( 'order', $this->store->orders[ $name ] ) ),
			ARRAY_A
		);
		$this->assertIsArray( $row, "Order {$name} was imported." );
		return $row;
	}

	/** @return list<array<string,string|null>> */
	private function rows( string $table, int $order_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', Schema::table( $table ), $order_id ),
			ARRAY_A
		);
	}

	private function rows_in( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( $table ) ) );
	}
}
