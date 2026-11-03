<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Increasing counters in fxcm_sequences (doc 03 §2.7). `next()` runs in
 * autocommit before the checkout transaction so the row lock is held for
 * one statement only. Numbers are unique, not gapless.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- atomic counter on a plugin table, values always prepared.
final class Sequence {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Transaction $transaction,
	) {}

	public function next( string $name ): int {
		$wpdb = $this->wpdb;
		if ( $this->transaction->in_progress() ) {
			throw new \LogicException( 'Sequence::next() must run outside a transaction.' );
		}

		$table = Schema::table( 'sequences' );
		if ( $this->increment( $table, $name ) === 0 ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (name, value) VALUES (%s, 0)', $table, $name ) );
			if ( $this->increment( $table, $name ) === 0 ) {
				throw new \RuntimeException( sprintf( 'Sequence "%s" could not be created.', $name ) );
			}
		}

		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	public function current( string $name ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT value FROM %i WHERE name = %s', Schema::table( 'sequences' ), $name )
		);
	}

	/** @phpstan-impure */
	private function increment( string $table, string $name ): int {
		$wpdb    = $this->wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET value = LAST_INSERT_ID(value + 1) WHERE name = %s', $table, $name )
		);
		if ( $updated === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return (int) $updated;
	}

	/** Never lowers the counter; used after imports keep their source numbers. */
	public function raise_to( string $name, int $value ): void {
		$wpdb  = $this->wpdb;
		$table = Schema::table( 'sequences' );
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (name, value) VALUES (%s, 0)', $table, $name ) );
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET value = GREATEST(value, %d) WHERE name = %s', $table, $value, $name )
		);
	}
}
