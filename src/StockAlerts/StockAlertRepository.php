<?php

declare(strict_types=1);

namespace Flexa\Commerce\StockAlerts;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxcm_stock_alerts (ADR-065). An address has one waiting row
 * per product and variant. Sent and cancelled rows stay for a while so the
 * list shows what went out.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared; rows change on stock changes and cron runs, so they are not object-cached.
final class StockAlertRepository {
	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find( int $id ): ?StockAlert {
		$wpdb = $this->wpdb;
		return $this->one( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'stock_alerts' ), $id ) );
	}

	public function waiting_for( string $email, int $product_id, int $variant_id ): ?StockAlert {
		$wpdb = $this->wpdb;
		return $this->one(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email = %s AND product_id = %d AND variant_id = %d AND status = %s LIMIT 1',
				Schema::table( 'stock_alerts' ),
				$email,
				$product_id,
				$variant_id,
				StockAlert::WAITING
			)
		);
	}

	public function insert( int $product_id, int $variant_id, ?int $user_id, string $email, string $locale, string $now ): int {
		$wpdb = $this->wpdb;
		$ok   = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (product_id, variant_id, user_id, email, locale, status, due, token, created_at, updated_at) VALUES (%d, %d, NULLIF(%d, 0), %s, %s, %s, 0, %s, %s, %s)',
				Schema::table( 'stock_alerts' ),
				$product_id,
				$variant_id,
				(int) $user_id,
				$email,
				$locale,
				StockAlert::WAITING,
				bin2hex( random_bytes( 16 ) ),
				$now,
				$now
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return (int) $wpdb->insert_id;
	}

	/** The stock of a product may be back: its waiting rows get looked at. */
	public function mark_product( int $product_id ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET due = 1 WHERE product_id = %d AND status = %s AND due = 0', Schema::table( 'stock_alerts' ), $product_id, StockAlert::WAITING )
		);
	}

	/** As mark_product(), for the product a variant belongs to; a shared stock moves every variant. */
	public function mark_variant( int $variant_id ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i a JOIN %i v ON v.product_id = a.product_id SET a.due = 1 WHERE v.id = %d AND a.status = %s AND a.due = 0',
				Schema::table( 'stock_alerts' ),
				Schema::table( 'variants' ),
				$variant_id,
				StockAlert::WAITING
			)
		);
	}

	public function mark_all(): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET due = 1 WHERE status = %s AND due = 0', Schema::table( 'stock_alerts' ), StockAlert::WAITING )
		);
	}

	public function has_due(): bool {
		$wpdb = $this->wpdb;
		return $wpdb->get_var(
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE status = %s AND due = 1 LIMIT 1', Schema::table( 'stock_alerts' ), StockAlert::WAITING )
		) !== null;
	}

	/** @return list<StockAlert> */
	public function due( int $limit ): array {
		$wpdb = $this->wpdb;
		return $this->many(
			$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s AND due = 1 ORDER BY id LIMIT %d', Schema::table( 'stock_alerts' ), StockAlert::WAITING, $limit )
		);
	}

	/** Looked at, still nothing to send. */
	public function checked( int $id ): void {
		$wpdb = $this->wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET due = 0 WHERE id = %d', Schema::table( 'stock_alerts' ), $id ) );
	}

	public function sent( int $id, string $now ): void {
		$wpdb = $this->wpdb;
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = %s, due = 0, sent_at = %s, updated_at = %s WHERE id = %d', Schema::table( 'stock_alerts' ), StockAlert::SENT, $now, $now, $id )
		);
	}

	/** Every waiting row of the address stops. */
	public function cancel_email( string $email, string $now ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = %s, due = 0, updated_at = %s WHERE email = %s AND status = %s', Schema::table( 'stock_alerts' ), StockAlert::CANCELLED, $now, $email, StockAlert::WAITING )
		);
	}

	public function delete( int $id ): bool {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'stock_alerts' ), $id ) ) > 0;
	}

	/** Settled rows older than `$before` go, and rows of products that are gone. */
	public function purge( string $before, int $limit = 1000 ): int {
		$wpdb = $this->wpdb;
		$old  = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE status <> %s AND updated_at < %s ORDER BY id LIMIT %d', Schema::table( 'stock_alerts' ), StockAlert::WAITING, $before, $limit )
		);
		$gone = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE a FROM %i a LEFT JOIN %i p ON p.ID = a.product_id WHERE p.ID IS NULL', Schema::table( 'stock_alerts' ), $wpdb->posts )
		);
		return $old + $gone;
	}

	/**
	 * @return array{rows:list<StockAlert>,total:int}
	 */
	public function page( string $status, int $product_id, int $page, int $per_page ): array {
		$wpdb  = $this->wpdb;
		$where = [];
		if ( $status !== '' ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}
		if ( $product_id > 0 ) {
			$where[] = $wpdb->prepare( 'product_id = %d', $product_id );
		}
		$sql   = $where !== [] ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$table = Schema::table( 'stock_alerts' );
		$total = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql is prepared above.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i {$sql}", $table )
		);
		$rows = $this->many(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql is prepared above.
				"SELECT * FROM %i {$sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				$table,
				$per_page,
				max( 0, ( $page - 1 ) * $per_page )
			)
		);
		return [
			'rows'  => $rows,
			'total' => $total,
		];
	}

	/**
	 * Products with the most addresses waiting.
	 *
	 * @return list<array{product_id:int,waiting:int}>
	 */
	public function wanted( int $limit ): array {
		$wpdb   = $this->wpdb;
		$result = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT product_id, COUNT(*) AS waiting FROM %i WHERE status = %s GROUP BY product_id ORDER BY waiting DESC, product_id LIMIT %d',
				Schema::table( 'stock_alerts' ),
				StockAlert::WAITING,
				$limit
			),
			ARRAY_A
		);
		$out    = [];
		foreach ( is_array( $result ) ? $result : [] as $row ) {
			$out[] = [
				'product_id' => (int) $row['product_id'],
				'waiting'    => (int) $row['waiting'],
			];
		}
		return $out;
	}

	/** @return array{waiting:int,sent:int} rows by status, sent ones since `$since` */
	public function counts( string $since ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::table( 'stock_alerts' );
		return [
			'waiting' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, StockAlert::WAITING ) ),
			'sent'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND sent_at >= %s', $table, StockAlert::SENT, $since ) ),
		];
	}

	/** @return list<StockAlert> */
	public function for_email( string $email ): array {
		$wpdb = $this->wpdb;
		return $this->many( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', Schema::table( 'stock_alerts' ), $email ) );
	}

	public function erase_email( string $email ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', Schema::table( 'stock_alerts' ), $email ) );
	}

	private function one( string $sql ): ?StockAlert {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return is_array( $row ) ? StockAlert::from_row( $row ) : null;
	}

	/** @return list<StockAlert> */
	private function many( string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return array_map( static fn ( array $row ): StockAlert => StockAlert::from_row( $row ), is_array( $rows ) ? $rows : [] );
	}
}
