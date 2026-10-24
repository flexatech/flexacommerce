<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Database\AfterCommit;
use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Logger;
use Flexa\Commerce\Support\LogRepository;
use Flexa\Commerce\Support\Redactor;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Support\FakeWpdb;
use Flexa\Commerce\Tests\Unit\TestCase;

final class LoggerTest extends TestCase {
	public function test_level_threshold(): void {
		self::assertTrue( Logger::passes( 'error', 'info' ) );
		self::assertTrue( Logger::passes( 'info', 'info' ) );
		self::assertFalse( Logger::passes( 'debug', 'info' ) );
		self::assertFalse( Logger::passes( 'warning', 'critical' ) );
	}

	public function test_interpolates_scalar_placeholders(): void {
		self::assertSame(
			'Order 42 failed: true {missing}',
			Logger::interpolate( 'Order {id} failed: {flag} {missing}', [ 'id' => 42, 'flag' => true, 'arr' => [ 1 ] ] )
		);
	}

	public function test_writes_redacted_entry_after_transaction_ends(): void {
		$db   = new FakeWpdb();
		$tx   = new Transaction( new FakeWpdb(), new AfterCommit() );
		$log  = new Logger( 'payments', new LogRepository( $db ), new Redactor( 'salt' ), $tx, new Clock( new \DateTimeImmutable( '2026-10-24 10:00:00', new \DateTimeZone( 'UTC' ) ) ) );

		try {
			$tx->run(
				static function () use ( $log, $db ): void {
					$log->error( 'Charge for {email} failed', [ 'email' => 'a@b.co', 'token' => 'tok_1' ] );
					self::assertSame( [], $db->inserted );
					throw new \RuntimeException( 'rollback' );
				}
			);
		} catch ( \RuntimeException ) {
			// expected
		}

		self::assertCount( 1, $db->inserted );
		self::assertSame( 'wp_fxc_logs', $db->inserted[0]['table'] );
		$row = $db->inserted[0]['data'];
		self::assertSame( 'error', $row['level'] );
		self::assertSame( 'payments', $row['channel'] );
		self::assertSame( '2026-10-24 10:00:00', $row['created_at'] );
		self::assertStringNotContainsString( 'a@b.co', $row['message'] . $row['context'] );
		self::assertStringContainsString( 'email#', $row['message'] );
		self::assertStringNotContainsString( 'tok_1', (string) $row['context'] );
	}

	public function test_skips_entries_below_configured_level(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = [ 'log_level' => 'warning' ];
		$db   = new FakeWpdb();
		$log  = new Logger( 'core', new LogRepository( $db ), new Redactor( 'salt' ), new Transaction( new FakeWpdb(), new AfterCommit() ), new Clock() );

		$log->info( 'quiet' );
		$log->warning( 'loud' );

		self::assertSame( [ 'loud' ], array_map( static fn ( array $r ): mixed => $r['data']['message'], $db->inserted ) );
	}
}
