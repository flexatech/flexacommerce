<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxc_sessions. Writes are versioned: an update names the
 * version it read and affects nothing when another request wrote first.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared, rows are per-visitor and not cached.
final class SessionRepository {
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find_by_token_hash( string $token_hash ): ?Session {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', Schema::table( 'sessions' ), $token_hash ),
			ARRAY_A
		);
		return is_array( $row ) ? Session::from_row( $row ) : null;
	}

	public function find_by_user( int $user_id ): ?Session {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', Schema::table( 'sessions' ), $user_id ),
			ARRAY_A
		);
		return is_array( $row ) ? Session::from_row( $row ) : null;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return int new id, 0 when the token or the user already has a row
	 */
	public function insert( string $token_hash, ?int $user_id, array $data, string $expires_at, string $now ): int {
		$suppressed = $this->wpdb->suppress_errors( true );
		try {
			$ok = $this->wpdb->insert(
				Schema::table( 'sessions' ),
				[
					'token_hash' => $token_hash,
					'user_id'    => $user_id,
					'data'       => self::encode( $data ),
					'version'    => 1,
					'expires_at' => $expires_at,
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%s', '%d', '%s', '%d', '%s', '%s', '%s' ]
			);
		} finally {
			$this->wpdb->suppress_errors( $suppressed );
		}
		if ( $ok === false ) {
			$error = QueryFailed::from_wpdb( $this->wpdb );
			if ( $error->is_duplicate_key() ) {
				return 0;
			}
			throw $error;
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Write when the row still has `$version`. Returns false on a conflict.
	 *
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, int $version, array $data, string $expires_at, string $now, ?int $user_id = null ): bool {
		$sets = 'data = %s, version = version + 1, expires_at = %s, updated_at = %s';
		$args = [ self::encode( $data ), $expires_at, $now ];
		if ( $user_id !== null ) {
			$sets  .= ', user_id = %d';
			$args[] = $user_id;
		}
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column list with placeholders.
				"UPDATE %i SET {$sets} WHERE id = %d AND version = %d",
				Schema::table( 'sessions' ),
				...[ ...$args, $id, $version ]
			)
		);
		if ( $affected === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $affected === 1;
	}

	/** Slide the expiry without a version bump: no cart data changes. */
	public function touch( int $id, string $expires_at ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'UPDATE %i SET expires_at = %s WHERE id = %d', Schema::table( 'sessions' ), $expires_at, $id )
		);
	}

	public function set_token_hash( int $id, string $token_hash ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'UPDATE %i SET token_hash = %s WHERE id = %d', Schema::table( 'sessions' ), $token_hash, $id )
		);
	}

	public function delete( int $id ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'sessions' ), $id )
		);
	}

	/** Bounded purge for the daily cron (doc 02 §5). */
	public function purge_expired( string $now, int $limit = 1000 ): int {
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s ORDER BY expires_at LIMIT %d', Schema::table( 'sessions' ), $now, $limit )
		);
		return (int) $deleted;
	}

	/** @param array<string,mixed> $data */
	private static function encode( array $data ): string {
		return json_encode( $data, self::JSON_FLAGS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- THROW_ON_ERROR, wp_json_encode() swallows failures.
	}
}
