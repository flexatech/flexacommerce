<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Migration\BackgroundDriver;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Plugin;

/**
 * The admin screen and background drivers (doc 05 §4.4): REST routes that
 * poll `batch`, and the Action Scheduler hook that queues one batch after
 * another. Both end in the same Runner as the CLI.
 */
final class MigrationDriversTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		GoldenStore::build();
		wp_set_current_user( 1 );
		rest_get_server();
	}

	public function test_admin_screen_polls_a_job_to_the_end(): void {
		$created = $this->post( '/admin/migrations', [ 'direction' => Direction::WC_TO_FXC, 'dry_run' => false ] );
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];
		$this->assertSame( JobStatus::READY, $created->get_data()['status'] );

		$refused = $this->post( "/admin/migrations/{$id}/start" );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'flexacommerce_migration_backup_required', $refused->as_error()->get_error_code() );

		$this->assertSame( JobStatus::RUNNING, $this->post( "/admin/migrations/{$id}/start", [ 'backup_confirmed' => true ] )->get_data()['status'] );
		$polls = 0;
		do {
			$batch = $this->post( "/admin/migrations/{$id}/batch" )->get_data();
			++$polls;
		} while ( ! $batch['done'] && $polls < 50 );
		$this->assertTrue( $batch['done'] );
		$this->assertSame( JobStatus::COMPLETED_WITH_WARNINGS, $batch['job']['status'] );

		$log = $this->get( "/admin/migrations/{$id}/log", [ 'level' => 'warning' ] );
		$this->assertSame( 200, $log->get_status() );
		$this->assertGreaterThan( 0, (int) $log->get_headers()['X-WP-Total'] );

		$index = $this->get( '/admin/migrations' )->get_data();
		$this->assertTrue( $index['woocommerce'] );
		$this->assertSame( $id, $index['jobs'][0]['id'] );
		$this->assertArrayNotHasKey( 'report', $index['jobs'][0] );

		$this->assertSame( JobStatus::COMPLETED_WITH_WARNINGS, $this->post( "/admin/migrations/{$id}/verify" )->get_data()['status'] );
		$this->assertSame( 404, $this->get( '/admin/migrations/999999' )->get_status() );
	}

	public function test_routes_need_the_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->get( '/admin/migrations' )->get_status() );
		$this->assertSame( 403, $this->post( '/admin/migrations', [ 'direction' => Direction::WC_TO_FXC ] )->get_status() );
	}

	public function test_background_driver_queues_batches_until_done(): void {
		$service = Plugin::instance()->migration()->service();
		$job     = $service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true, 'batch_default' => 1, 'batch_products' => 2, 'batch_orders' => 2 ], false, 1 );
		$service->start( $job->id );
		$this->assertSame( 200, $this->post( "/admin/migrations/{$job->id}/background" )->get_status() );

		$driver = Plugin::instance()->migration()->background();
		$runs   = 0;
		while ( $this->queued( $job->id ) && $runs < 100 ) {
			$this->unqueue( $job->id );
			$driver->handle( $job->id );
			++$runs;
		}
		$this->assertGreaterThan( 1, $runs, 'Small batches need several actions.' );
		$this->assertFalse( $this->queued( $job->id ), 'Nothing is queued once the job is finished.' );
		$this->assertContains( $service->find( $job->id )->status, JobStatus::FINISHED );
	}

	public function test_background_driver_waits_for_a_held_lease(): void {
		$service = Plugin::instance()->migration()->service();
		$job     = $service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true ], false, 1 );
		$service->start( $job->id );
		global $wpdb;
		$wpdb->update( \Flexa\Commerce\Database\Schema::table( 'migration_jobs' ), [ 'lock_token' => 'other', 'locked_until' => '2999-01-01 00:00:00' ], [ 'id' => $job->id ] );

		Plugin::instance()->migration()->background()->handle( $job->id );
		$next = as_next_scheduled_action( BackgroundDriver::HOOK, [ $job->id ], BackgroundDriver::GROUP );
		$this->assertIsInt( $next );
		$this->assertGreaterThanOrEqual( time() + 50, $next, 'The retry waits for the lease to expire.' );
		$this->unqueue( $job->id );
	}

	private function queued( int $job_id ): bool {
		return as_next_scheduled_action( BackgroundDriver::HOOK, [ $job_id ], BackgroundDriver::GROUP ) !== false;
	}

	private function unqueue( int $job_id ): void {
		as_unschedule_all_actions( BackgroundDriver::HOOK, [ $job_id ], BackgroundDriver::GROUP );
	}

	/** @param array<string,mixed> $body */
	private function post( string $route, array $body = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/' . FLEXACOMMERCE_REST_NAMESPACE . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	/** @param array<string,mixed> $query */
	private function get( string $route, array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/' . FLEXACOMMERCE_REST_NAMESPACE . $route );
		$request->set_query_params( $query );
		return rest_do_request( $request );
	}
}
