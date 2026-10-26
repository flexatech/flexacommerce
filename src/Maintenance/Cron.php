<?php

declare(strict_types=1);

namespace Flexa\Commerce\Maintenance;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron jobs (doc 02 §6.6): unpaid order expiry every five minutes, the
 * hourly sale schedule refresh, and the daily log, session and webhook
 * purges. Every handler is bounded per run. Deferred emails get one
 * single event each (SEND_EMAIL).
 */
final class Cron {
	use HasInstance;

	public const DAILY  = 'flexacommerce.cron.daily';
	public const HOURLY = 'flexacommerce.cron.hourly';
	public const EXPIRE = 'flexacommerce.cron.expire_pending';

	public const SEND_EMAIL = 'flexacommerce.cron.send_email';

	/** Same value as Migration\BackgroundDriver::HOOK, kept literal so page views do not load migration code. */
	public const MIGRATION_BATCH = 'flexacommerce.migration.batch';

	public const INTERVAL = 'flexacommerce_five_minutes';

	public function register(): void {
		add_action( self::DAILY, [ $this, 'run_daily' ] );
		add_action( self::HOURLY, [ $this, 'run_hourly' ] );
		add_action( self::EXPIRE, [ $this, 'run_expire' ] );
		// Single events only ever run from cron, so page views skip this callback (P1 budget).
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( self::SEND_EMAIL, [ $this, 'send_email' ] );
		}
		// Action Scheduler also runs queues from its async admin-ajax request.
		if ( wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( self::MIGRATION_BATCH, [ $this, 'migration_batch' ] );
		}
		// Only cron runs reschedule events; admin tools list the schedules.
		if ( wp_doing_cron() || is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_filter( 'cron_schedules', [ self::class, 'intervals' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five minutes is the documented expiry cadence.
		}
		add_action( 'init', [ $this, 'self_heal' ] );
	}

	/**
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function intervals( array $schedules ): array {
		$schedules[ self::INTERVAL ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'flexacommerce' ),
		];
		return $schedules;
	}

	public static function schedule(): void {
		// Activation can run before register(), so the interval is added here too.
		add_filter( 'cron_schedules', [ self::class, 'intervals' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- see register().
		if ( wp_next_scheduled( self::DAILY ) === false ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
		if ( wp_next_scheduled( self::HOURLY ) === false ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOURLY );
		}
		if ( wp_next_scheduled( self::EXPIRE ) === false ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::INTERVAL, self::EXPIRE );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY );
		wp_clear_scheduled_hook( self::HOURLY );
		wp_clear_scheduled_hook( self::EXPIRE );
		wp_unschedule_hook( self::SEND_EMAIL );
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
		$webhooks = Plugin::instance()->payments()->service()->purge_webhooks();

		do_action( 'flexacommerce.cron.daily_done', $deleted, $sessions, $webhooks );
	}

	/** Sale prices that started or ended since the last run (doc 03 §3.3). */
	public function run_hourly(): void {
		$refreshed = Plugin::instance()->catalog()->product_service()->refresh_due_prices();

		do_action( 'flexacommerce.cron.hourly_done', $refreshed );
	}

	/** Pending orders past the hold time are cancelled and their stock released. */
	public function run_expire(): void {
		$cancelled = Plugin::instance()->orders()->service()->expire_pending();

		do_action( 'flexacommerce.cron.expire_done', $cancelled );
	}

	public function migration_batch( mixed $job_id ): void {
		if ( is_numeric( $job_id ) ) {
			Plugin::instance()->migration()->background()->handle( (int) $job_id );
		}
	}

	public function send_email( mixed $queue_id ): void {
		if ( is_string( $queue_id ) ) {
			Plugin::instance()->notifications()->mailer()->send_deferred( $queue_id );
		}
	}
}
