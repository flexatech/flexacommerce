<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database;

defined( 'ABSPATH' ) || exit;

/**
 * START TRANSACTION / COMMIT / ROLLBACK on $wpdb (doc 02 §4.3).
 *
 * Nested run() calls join the outer transaction; only the outermost call
 * commits. Deadlocks and lock wait timeouts roll back and retry the whole
 * closure, so closures must keep side effects in AfterCommit.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- transaction control statements, no data.
final class Transaction {
	public const MAX_RETRIES = 3;

	private int $depth = 0;

	/** @var list<callable():void> */
	private array $on_end = [];

	/** @var callable(int):void */
	private $sleep;

	/** @param (callable(int):void)|null $sleep receives microseconds; tests pass a no-op */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly AfterCommit $after_commit,
		?callable $sleep = null,
	) {
		$this->sleep = $sleep ?? static function ( int $microseconds ): void {
			usleep( $microseconds );
		};
	}

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	public function run( callable $work ): mixed {
		if ( $this->depth > 0 ) {
			return $this->nested( $work );
		}

		$attempt = 0;
		while ( true ) {
			try {
				return $this->outermost( $work );
			} catch ( QueryFailed $e ) {
				if ( ! $e->is_retryable() || $attempt >= self::MAX_RETRIES ) {
					throw $e;
				}
				++$attempt;
				( $this->sleep )( random_int( 20_000, 80_000 ) );
			}
		}
	}

	public function in_progress(): bool {
		return $this->depth > 0;
	}

	/**
	 * Run once the outermost transaction ends, committed or not. Used for work
	 * that must survive a rollback, such as log entries about the failure.
	 *
	 * @param callable():void $callback
	 */
	public function when_idle( callable $callback ): void {
		if ( $this->depth === 0 ) {
			$callback();
			return;
		}
		$this->on_end[] = $callback;
	}

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	private function outermost( callable $work ): mixed {
		$this->statement( 'START TRANSACTION' );
		$this->depth = 1;
		$this->after_commit->begin();

		try {
			$result = $work();
			$this->statement( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			$this->depth = 0;
			$this->after_commit->rollback();
			$this->drain_on_end();
			throw $e;
		}

		$this->depth = 0;
		$this->after_commit->commit();
		$this->drain_on_end();
		return $result;
	}

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	private function nested( callable $work ): mixed {
		++$this->depth;
		try {
			return $work();
		} finally {
			--$this->depth;
		}
	}

	/** @param 'START TRANSACTION'|'COMMIT'|'ROLLBACK' $sql */
	private function statement( string $sql ): void {
		if ( $this->wpdb->query( $sql ) === false ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed keywords only.
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	private function drain_on_end(): void {
		while ( $this->on_end !== [] ) {
			$callback = array_shift( $this->on_end );
			try {
				$callback();
			} catch ( \Throwable $e ) {
				unset( $e ); // Best effort: a failed log write must not mask the real outcome.
			}
		}
	}
}
