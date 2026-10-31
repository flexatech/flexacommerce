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

	/**
	 * The address a guest typed at checkout, kept for cart reminders
	 * (ADR-063). It counts as cart activity, so the cart is looked at again.
	 */
	public function set_email( int $id, string $email, string $now ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'UPDATE %i SET email = %s, updated_at = %s WHERE id = %d', Schema::table( 'sessions' ), $email, $now, $id )
		);
	}

	/** Forgets an address typed at checkout on every cart that holds it (privacy eraser). */
	public function clear_email( string $email ): int {
		return (int) $this->wpdb->query(
			$this->wpdb->prepare( "UPDATE %i SET email = '' WHERE email = %s", Schema::table( 'sessions' ), $email )
		);
	}

	/**
	 * Carts of known shoppers that went quiet between `$from` and `$until`
	 * and changed since recovery last looked at them (ADR-063).
	 *
	 * @return list<Session>
	 */
	public function idle( string $from, string $until, int $limit ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE updated_at >= %s AND updated_at <= %s AND (email <> '' OR user_id IS NOT NULL) AND (recovery_checked IS NULL OR recovery_checked < updated_at) ORDER BY updated_at LIMIT %d",
				Schema::table( 'sessions' ),
				$from,
				$until,
				$limit
			),
			ARRAY_A
		);
		return array_map( static fn ( array $row ): Session => Session::from_row( $row ), is_array( $rows ) ? $rows : [] );
	}

	/** Recovery has handled the row as it was at `$updated_at`. */
	public function mark_checked( int $id, string $updated_at ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'UPDATE %i SET recovery_checked = %s WHERE id = %d', Schema::table( 'sessions' ), $updated_at, $id )
		);
	}

	public function find( int $id ): ?Session {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'sessions' ), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? Session::from_row( $row ) : null;
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
