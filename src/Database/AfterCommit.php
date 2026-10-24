<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Side effects that must only happen once data is committed: emails, public
 * actions, payment calls, cache purges (doc 02 §4.4). Outside a transaction
 * a deferred callback runs at once.
 */
final class AfterCommit {
	/** @var list<callable():void> */
	private array $queue = [];

	private int $depth = 0;

	/** @var (callable(\Throwable):void)|null */
	private $on_error;

	/** @param (callable(\Throwable):void)|null $on_error */
	public function __construct( ?callable $on_error = null ) {
		$this->on_error = $on_error;
	}

	/** @param callable():void $callback */
	public function defer( callable $callback ): void {
		if ( $this->depth > 0 ) {
			$this->queue[] = $callback;
			return;
		}
		$this->call( $callback );
	}

	public function begin(): void {
		++$this->depth;
	}

	/** Run the queue in insertion order once the outermost transaction committed. */
	public function commit(): void {
		$this->depth = max( 0, $this->depth - 1 );
		if ( $this->depth > 0 ) {
			return;
		}
		// Callbacks may open their own transactions and defer more work.
		while ( $this->queue !== [] ) {
			$this->call( array_shift( $this->queue ) );
		}
	}

	public function rollback(): void {
		$this->depth = 0;
		$this->queue = [];
	}

	public function pending(): int {
		return count( $this->queue );
	}

	/** @param callable():void $callback */
	private function call( callable $callback ): void {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			if ( $this->on_error !== null ) {
				( $this->on_error )( $e );
			}
		}
	}
}
