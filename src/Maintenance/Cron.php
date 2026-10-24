<?php

declare(strict_types=1);

namespace Flexa\Commerce\Maintenance;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron jobs: the daily log and session purges and the hourly sale
 * schedule refresh.
 * Pending-order expiry joins in phase 4.
 */
final class Cron {
	use HasInstance;

	public const DAILY  = 'flexacommerce.cron.daily';
	public const HOURLY = 'flexacommerce.cron.hourly';

	public function register(): void {
		add_action( self::DAILY, [ $this, 'run_daily' ] );
		add_action( self::HOURLY, [ $this, 'run_hourly' ] );
		add_action( 'init', [ $this, 'self_heal' ] );
	}

	public static function schedule(): void {
		if ( wp_next_scheduled( self::DAILY ) === false ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
		if ( wp_next_scheduled( self::HOURLY ) === false ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOURLY );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY );
		wp_clear_scheduled_hook( self::HOURLY );
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
		$sessions = Plugin::instance()->cart()->sessions()->purge_expired();

		do_action( 'flexacommerce.cron.daily_done', $deleted, $sessions );
	}

	/** Sale prices that started or ended since the last run (doc 03 §3.3). */
	public function run_hourly(): void {
		$refreshed = Plugin::instance()->catalog()->product_service()->refresh_due_prices();

		do_action( 'flexacommerce.cron.hourly_done', $refreshed );
	}
}
