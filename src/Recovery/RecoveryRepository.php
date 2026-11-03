<?php

declare(strict_types=1);

namespace Flexa\Commerce\Recovery;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\OrderStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxcm_recoveries (ADR-063). An email has one open row at a
 * time; the cron finds it by session or by email. Unsubscribed rows stay,
 * so the address is not written to again.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared; rows change on every cron run, so they are not object-cached.
final class RecoveryRepository {
	/** Orders that count as the cart bought. */
	public const PLACED = [ OrderStatus::ON_HOLD, OrderStatus::PROCESSING, OrderStatus::COMPLETED ];

	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find( int $id ): ?Recovery {
		$wpdb = $this->wpdb;
		return $this->one( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'recoveries' ), $id ) );
	}

	/** The open row of a session, else the open row of the address. */
	public function open_for( int $session_id, string $email ): ?Recovery {
		$wpdb  = $this->wpdb;
		$found = $this->one( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %d AND status = %s ORDER BY id DESC LIMIT 1', Schema::table( 'recoveries' ), $session_id, Recovery::OPEN ) );
		if ( $found !== null || $email === '' ) {
			return $found;
		}
		return $this->one( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s AND status = %s ORDER BY id DESC LIMIT 1', Schema::table( 'recoveries' ), $email, Recovery::OPEN ) );
	}

	/**
	 * @param array{session_id:int,user_id:?int,email:string,cart:array<string,mixed>,total:int,currency:string,item_count:int,next_send_at:?string,last_activity_at:string} $row
	 */
	public function insert( array $row, string $now ): int {
		$wpdb = $this->wpdb;
		$ok   = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (session_id, user_id, email, cart, total, currency, item_count, status, token, next_send_at, last_activity_at, created_at, updated_at) VALUES (%d, NULLIF(%d, 0), %s, %s, %d, %s, %d, %s, %s, NULLIF(%s, \'\'), %s, %s, %s)',
				Schema::table( 'recoveries' ),
				$row['session_id'],
				(int) $row['user_id'],
				$row['email'],
				self::encode( $row['cart'] ),
				$row['total'],
				$row['currency'],
				$row['item_count'],
				Recovery::OPEN,
				bin2hex( random_bytes( 16 ) ),
				(string) $row['next_send_at'],
				$row['last_activity_at'],
				$now,
				$now
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * The fresh cart of an open row.
	 *
	 * @param array{session_id:int,user_id:?int,email:string,cart:array<string,mixed>,total:int,currency:string,item_count:int,next_send_at:?string,last_activity_at:string} $row
	 */
	public function refresh( int $id, array $row, string $now ): void {
		$wpdb = $this->wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET session_id = %d, user_id = NULLIF(%d, 0), email = %s, cart = %s, total = %d, currency = %s, item_count = %d, next_send_at = NULLIF(%s, \'\'), last_activity_at = %s, updated_at = %s WHERE id = %d',
				Schema::table( 'recoveries' ),
				$row['session_id'],
				(int) $row['user_id'],
				$row['email'],
				self::encode( $row['cart'] ),
				$row['total'],
				$row['currency'],
				$row['item_count'],
				(string) $row['next_send_at'],
				$row['last_activity_at'],
				$now,
				$id
			)
		);
	}

	/** After a reminder went out: the next one, or none. */
	public function sent( int $id, int $reminder, bool $emailed, ?string $next_send_at, string $coupon_code, string $now ): void {
		$wpdb = $this->wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET reminder = %d, emails_sent = emails_sent + %d, next_send_at = NULLIF(%s, \'\'), coupon_code = IF(%s = \'\', coupon_code, %s), updated_at = %s WHERE id = %d',
				Schema::table( 'recoveries' ),
				$reminder,
				$emailed ? 1 : 0,
				(string) $next_send_at,
				$coupon_code,
				$coupon_code,
				$now,
				$id
			)
		);
	}

	public function recovered( int $id, ?int $order_id, string $now ): void {
		$wpdb = $this->wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, order_id = NULLIF(%d, 0), recovered_at = %s, next_send_at = NULL, updated_at = %s WHERE id = %d',
				Schema::table( 'recoveries' ),
				Recovery::RECOVERED,
				(int) $order_id,
				$now,
				$now,
				$id
			)
		);
	}

	public function lost( int $id, string $now ): void {
		$wpdb = $this->wpdb;
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = %s, next_send_at = NULL, updated_at = %s WHERE id = %d', Schema::table( 'recoveries' ), Recovery::LOST, $now, $id )
		);
	}

	/** The shopper came back through a link: the row follows their session again. */
	public function clicked( int $id, ?int $session_id, string $now ): void {
		$wpdb = $this->wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, session_id = NULLIF(%d, 0), clicked_at = COALESCE(clicked_at, %s), updated_at = %s WHERE id = %d',
				Schema::table( 'recoveries' ),
				Recovery::OPEN,
				(int) $session_id,
				$now,
				$now,
				$id
			)
		);
	}

	public function delete( int $id ): bool {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'recoveries' ), $id ) ) > 0;
	}

	/** @return list<Recovery> open rows whose next reminder is due */
	public function due( string $now, int $limit ): array {
		$wpdb = $this->wpdb;
		return $this->many(
			$wpdb->prepare( 'SELECT * FROM %i WHERE next_send_at <= %s AND status = %s ORDER BY next_send_at LIMIT %d', Schema::table( 'recoveries' ), $now, Recovery::OPEN, $limit )
		);
	}

	public function is_unsubscribed( string $email ): bool {
		$wpdb = $this->wpdb;
		if ( $email === '' ) {
			return false;
		}
		return $wpdb->get_var(
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE email = %s AND unsubscribed_at IS NOT NULL LIMIT 1', Schema::table( 'recoveries' ), $email )
		) !== null;
	}

	/** No more reminders to this address, from any of its rows. */
	public function unsubscribe( string $email, string $now ): void {
		$wpdb = $this->wpdb;
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET unsubscribed_at = COALESCE(unsubscribed_at, %s), next_send_at = NULL, updated_at = %s WHERE email = %s', Schema::table( 'recoveries' ), $now, $now, $email )
		);
	}

	/** Open rows with no cart activity since `$before` are given up. */
	public function expire( string $before, string $now ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = %s, next_send_at = NULL, updated_at = %s WHERE status = %s AND last_activity_at < %s', Schema::table( 'recoveries' ), Recovery::LOST, $now, Recovery::OPEN, $before )
		);
	}

	/** Old rows go; an unsubscribed one stays to keep the address quiet. */
	public function purge( string $before, int $limit = 1000 ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s AND status <> %s AND unsubscribed_at IS NULL ORDER BY id LIMIT %d', Schema::table( 'recoveries' ), $before, Recovery::OPEN, $limit )
		);
	}

	/**
	 * An order the shopper placed at or after `$since`.
	 *
	 * @return array{id:int,total:int}|null
	 */
	public function order_since( string $email, ?int $user_id, string $since ): ?array {
		$wpdb     = $this->wpdb;
		$statuses = implode( ', ', array_fill( 0, count( self::PLACED ), '%s' ) );
		$row      = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders and fixed SQL only; every value is a prepare() replacement.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				"SELECT id, total FROM %i WHERE (customer_email = %s OR customer_id = %d) AND created_at >= %s AND status IN ({$statuses}) AND deleted_at IS NULL ORDER BY id LIMIT 1",
				Schema::table( 'orders' ),
				$email,
				(int) $user_id,
				$since,
				...self::PLACED
			),
			ARRAY_A
		);
		return is_array( $row ) ? [
			'id'    => (int) $row['id'],
			'total' => (int) $row['total'],
		] : null;
	}

	/**
	 * @return array{rows:list<array{recovery:Recovery,order_number:?string}>,total:int}
	 */
	public function page( string $status, int $page, int $per_page ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::table( 'recoveries' );
		$where = $status !== '' ? $wpdb->prepare( 'WHERE r.status = %s', $status ) : '';
		$total = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i r {$where}", $table )
		);
		$result = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
				"SELECT r.*, o.order_number FROM %i r LEFT JOIN %i o ON o.id = r.order_id {$where} ORDER BY r.last_activity_at DESC, r.id DESC LIMIT %d OFFSET %d",
				$table,
				Schema::table( 'orders' ),
				$per_page,
				max( 0, ( $page - 1 ) * $per_page )
			),
			ARRAY_A
		);
		$rows = [];
		foreach ( is_array( $result ) ? $result : [] as $row ) {
			$rows[] = [
				'recovery'     => Recovery::from_row( $row ),
				'order_number' => isset( $row['order_number'] ) ? (string) $row['order_number'] : null,
			];
		}
		return [
			'rows'  => $rows,
			'total' => $total,
		];
	}

	/**
	 * Rows started since `$since`, by status, and what the recovered orders came to.
	 *
	 * @return array{open:int,recovered:int,lost:int,revenue:int}
	 */
	public function stats( string $since ): array {
		$wpdb   = $this->wpdb;
		$counts = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY status', Schema::table( 'recoveries' ), $since ),
			ARRAY_A
		);
		$out    = [
			'open'      => 0,
			'recovered' => 0,
			'lost'      => 0,
			'revenue'   => 0,
		];
		foreach ( is_array( $counts ) ? $counts : [] as $row ) {
			if ( in_array( $row['status'], Recovery::STATUSES, true ) ) {
				$out[ (string) $row['status'] ] = (int) $row['n'];
			}
		}
		$out['revenue'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(o.total), 0) FROM %i r JOIN %i o ON o.id = r.order_id WHERE r.status = %s AND r.created_at >= %s',
				Schema::table( 'recoveries' ),
				Schema::table( 'orders' ),
				Recovery::RECOVERED,
				$since
			)
		);
		return $out;
	}

	/** @return list<Recovery> */
	public function for_email( string $email ): array {
		$wpdb = $this->wpdb;
		return $this->many( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', Schema::table( 'recoveries' ), $email ) );
	}

	public function erase_email( string $email ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', Schema::table( 'recoveries' ), $email ) );
	}

	private function one( string $sql ): ?Recovery {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return is_array( $row ) ? Recovery::from_row( $row ) : null;
	}

	/** @return list<Recovery> */
	private function many( string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return array_map( static fn ( array $row ): Recovery => Recovery::from_row( $row ), is_array( $rows ) ? $rows : [] );
	}

	/** @param array<string,mixed> $data */
	private static function encode( array $data ): string {
		return json_encode( $data, self::JSON_FLAGS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- THROW_ON_ERROR, wp_json_encode() swallows failures.
	}
}
