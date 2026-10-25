<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Maintenance;

use Flexa\Commerce\Maintenance\Cron;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

final class CronTest extends OrderTestCase {
	public function tear_down(): void {
		Cron::schedule();
		parent::tear_down();
	}

	public function test_schedule_adds_every_job_once_and_unschedule_clears_them(): void {
		Cron::unschedule();
		Cron::schedule();
		Cron::schedule();

		self::assertSame( Cron::INTERVAL, wp_get_schedule( Cron::EXPIRE ) );
		self::assertSame( 300, wp_get_schedules()[ Cron::INTERVAL ]['interval'] );
		self::assertSame( 'hourly', wp_get_schedule( Cron::HOURLY ) );
		self::assertSame( 'daily', wp_get_schedule( Cron::DAILY ) );
		$events = array_filter( (array) _get_cron_array(), static fn ( array $hooks ): bool => isset( $hooks[ Cron::EXPIRE ] ) );
		self::assertCount( 1, $events );

		Cron::unschedule();
		self::assertFalse( wp_next_scheduled( Cron::EXPIRE ) );
	}

	public function test_the_expiry_job_cancels_stale_pending_orders(): void {
		$product = $this->simple( 1000, 5 );
		$old     = $this->place( [ $product => 1 ] );
		Plugin::instance()->orders()->orders()->update( $old->id, [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ] );

		$done = null;
		add_action(
			'flexacommerce.cron.expire_done',
			static function ( int $cancelled ) use ( &$done ): void {
				$done = $cancelled;
			}
		);
		do_action( Cron::EXPIRE );

		self::assertSame( 1, $done );
		self::assertSame( OrderStatus::CANCELLED, $this->orders()->get( $old->id )->status );
		self::assertSame( 5, $this->stock_of( $product ) );
	}
}
