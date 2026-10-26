<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Third driver (doc 05 §4.4): one batch per scheduled action, the next one
 * queued while the job still has work. Uses Action Scheduler when it is
 * loaded (WooCommerce ships it) and a WP-Cron single event otherwise. A
 * batch that finds the lease taken retries after the lease would expire.
 */
final class BackgroundDriver {
	public const HOOK  = 'flexacommerce.migration.batch';
	public const GROUP = 'flexacommerce';

	public function __construct( private readonly MigrationService $service ) {}

	public function dispatch( int $job_id, int $delay = 0 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( as_next_scheduled_action( self::HOOK, [ $job_id ], self::GROUP ) === false ) {
				as_schedule_single_action( time() + $delay, self::HOOK, [ $job_id ], self::GROUP );
			}
			return;
		}
		if ( wp_next_scheduled( self::HOOK, [ $job_id ] ) === false ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, [ $job_id ] );
		}
	}

	public function handle( int $job_id ): void {
		$result = $this->service->run_batch( $job_id );
		if ( $result['locked'] ) {
			$this->dispatch( $job_id, Runner::LEASE_SECONDS );
			return;
		}
		$job = $result['job'];
		if ( ! $result['done'] && $job instanceof Job && in_array( $job->status, [ JobStatus::RUNNING, JobStatus::VERIFYING ], true ) ) {
			$this->dispatch( $job_id );
		}
	}

	public function cancel( int $job_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [ $job_id ], self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK, [ $job_id ] );
	}
}
