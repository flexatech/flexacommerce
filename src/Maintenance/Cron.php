<?php

declare(strict_types=1);

namespace Flexa\Commerce\Maintenance;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron jobs. Phase 1 has only the daily log purge; pending-order expiry
 * joins in phase 4.
 */
final class Cron {
	use HasInstance;

	public const DAILY = 'flexacommerce.cron.daily';

	public function register(): void {
		add_action( self::DAILY, [ $this, 'run_daily' ] );
		add_action( 'init', [ $this, 'self_heal' ] );
	}

	public static function schedule(): void {
		if ( wp_next_scheduled( self::DAILY ) === false ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY );
	}

	/** Restores a lost schedule; only checked where it is cheap to do so. */
	public function self_heal(): void {
		if ( is_admin() || wp_doing_cron() ) {
			self::schedule();
		}
	}

	public function run_daily(): void {
		$services = Plugin::instance()->services();
		$cutoff   = $services->clock()->db_ago( Settings::int( 'log_retention_days' ) * DAY_IN_SECONDS );
		$deleted  = $services->log_repository()->purge_older_than( $cutoff );

		do_action( 'flexacommerce.cron.daily_done', $deleted );
	}
}
