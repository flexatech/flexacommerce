<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Database;

use Flexa\Commerce\Database\AfterCommit;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Tests\Support\FakeWpdb;
use Flexa\Commerce\Tests\Unit\TestCase;

final class TransactionTest extends TestCase {
	private FakeWpdb $wpdb;
	private AfterCommit $after;
	private Transaction $tx;

	/** @var list<int> */
	private array $sleeps = [];

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb   = new FakeWpdb();
		$this->after  = new AfterCommit();
		$this->sleeps = [];
		$this->tx     = new Transaction(
			$this->wpdb,
			$this->after,
			function ( int $us ): void {
				$this->sleeps[] = $us;
			}
		);
	}

	public function test_commits_and_returns_result(): void {
		$result = $this->tx->run( fn (): string => $this->wpdb->query( 'UPDATE x' ) ? 'done' : 'no' );
		self::assertSame( 'done', $result );
		self::assertSame( [ 'START TRANSACTION', 'UPDATE x', 'COMMIT' ], $this->wpdb->queries );
		self::assertFalse( $this->tx->in_progress() );
	}

	public function test_rolls_back_and_rethrows(): void {
		try {
			$this->tx->run(
				static function (): void {
					throw new \DomainException( 'nope' );
				}
			);
			self::fail( 'Expected exception' );
		} catch ( \DomainException $e ) {
			self::assertSame( 'nope', $e->getMessage() );
		}
		self::assertSame( [ 'START TRANSACTION', 'ROLLBACK' ], $this->wpdb->queries );
		self::assertFalse( $this->tx->in_progress() );
	}

	public function test_nested_calls_join_outer_transaction(): void {
		$this->tx->run(
			function (): void {
				$this->tx->run(
					function (): void {
						self::assertTrue( $this->tx->in_progress() );
						$this->wpdb->query( 'INNER' );
					}
				);
			}
		);
		self::assertSame( [ 'START TRANSACTION', 'INNER', 'COMMIT' ], $this->wpdb->queries );
	}

	public function test_retries_deadlock_then_succeeds(): void {
		$this->wpdb->fail_next( 'UPDATE stock', QueryFailed::DEADLOCK );
		$runs = 0;
		$this->tx->run(
			function () use ( &$runs ): void {
				++$runs;
				if ( $this->wpdb->query( 'UPDATE stock' ) === false ) {
					throw QueryFailed::from_wpdb( $this->wpdb );
				}
			}
		);
		self::assertSame( 2, $runs );
		self::assertCount( 1, $this->sleeps );
		self::assertGreaterThanOrEqual( 20_000, $this->sleeps[0] );
		self::assertSame( 'COMMIT', end( $this->wpdb->queries ) );
	}

	public function test_gives_up_after_max_retries(): void {
		for ( $i = 0; $i <= Transaction::MAX_RETRIES; $i++ ) {
			$this->wpdb->fail_next( 'UPDATE stock', QueryFailed::LOCK_WAIT_TIMEOUT );
		}
		$this->expectException( QueryFailed::class );
		try {
			$this->tx->run(
				function (): void {
					if ( $this->wpdb->query( 'UPDATE stock' ) === false ) {
						throw QueryFailed::from_wpdb( $this->wpdb );
					}
				}
			);
		} finally {
			self::assertCount( Transaction::MAX_RETRIES, $this->sleeps );
		}
	}

	public function test_does_not_retry_other_errors(): void {
		$this->wpdb->fail_next( 'INSERT', QueryFailed::DUPLICATE_KEY );
		try {
			$this->tx->run(
				function (): void {
					if ( $this->wpdb->query( 'INSERT' ) === false ) {
						throw QueryFailed::from_wpdb( $this->wpdb );
					}
				}
			);
			self::fail( 'Expected exception' );
		} catch ( QueryFailed $e ) {
			self::assertTrue( $e->is_duplicate_key() );
		}
		self::assertSame( [], $this->sleeps );
	}

	public function test_after_commit_runs_only_on_commit(): void {
		$ran = [];
		$this->tx->run(
			function () use ( &$ran ): void {
				$this->after->defer(
					static function () use ( &$ran ): void {
						$ran[] = 'committed';
					}
				);
				self::assertSame( [], $ran );
			}
		);
		self::assertSame( [ 'committed' ], $ran );

		try {
			$this->tx->run(
				function () use ( &$ran ): void {
					$this->after->defer(
						static function () use ( &$ran ): void {
							$ran[] = 'should not run';
						}
					);
					throw new \RuntimeException( 'fail' );
				}
			);
		} catch ( \RuntimeException ) {
			// expected
		}
		self::assertSame( [ 'committed' ], $ran );
		self::assertSame( 0, $this->after->pending() );
	}

	public function test_when_idle_runs_after_rollback_too(): void {
		$ran = [];
		try {
			$this->tx->run(
				function () use ( &$ran ): void {
					$this->tx->when_idle(
						static function () use ( &$ran ): void {
							$ran[] = 'idle';
						}
					);
					self::assertSame( [], $ran );
					throw new \RuntimeException( 'fail' );
				}
			);
		} catch ( \RuntimeException ) {
			// expected
		}
		self::assertSame( [ 'idle' ], $ran );
	}

	public function test_failed_after_commit_callback_reports_and_continues(): void {
		$errors = [];
		$after  = new AfterCommit(
			static function ( \Throwable $e ) use ( &$errors ): void {
				$errors[] = $e->getMessage();
			}
		);
		$ran    = false;
		$after->defer(
			static function (): void {
				throw new \RuntimeException( 'mail down' );
			}
		);
		$after->defer(
			static function () use ( &$ran ): void {
				$ran = true;
			}
		);
		self::assertSame( [ 'mail down' ], $errors );
		self::assertTrue( $ran );
	}
}
