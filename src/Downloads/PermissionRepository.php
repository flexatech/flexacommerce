<?php

declare(strict_types=1);

namespace Flexa\Commerce\Downloads;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\OrderStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Download permissions in fxc_downloads (ADR-058). A grant inserts one row
 * per order line and file and skips rows that exist, so granting twice is
 * harmless. A download takes one from `downloads_remaining` in a single
 * conditional UPDATE, so two requests cannot both use the last one.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared; counts change on every download, so rows are not object-cached.
final class PermissionRepository {
	/** Orders whose files may be downloaded. */
	public const OPEN = [ OrderStatus::PROCESSING, OrderStatus::COMPLETED ];

	private const COLUMNS = 'id, order_id, order_item_id, product_id, variant_id, file_id, customer_id, email, token, downloads_remaining, access_expires, download_count, granted_at, last_download_at';

	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * Adds the rows that do not exist yet.
	 *
	 * @param list<array{order_id:int,order_item_id:int,product_id:int,variant_id:int,file_id:string,customer_id:?int,email:string,downloads_remaining:?int,access_expires:?string,granted_at:string,download_count?:int,last_download_at?:?string}> $rows
	 * @return int rows added
	 */
	public function grant( array $rows ): int {
		$wpdb  = $this->wpdb;
		$added = 0;
		foreach ( $rows as $row ) {
			$done = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (order_id, order_item_id, product_id, variant_id, file_id, customer_id, email, token, downloads_remaining, access_expires, download_count, granted_at, last_download_at) VALUES (%d, %d, %d, %d, %s, NULLIF(%d, 0), %s, %s, NULLIF(%s, \'\'), NULLIF(%s, \'\'), %d, %s, NULLIF(%s, \'\'))',
					Schema::table( 'downloads' ),
					$row['order_id'],
					$row['order_item_id'],
					$row['product_id'],
					$row['variant_id'],
					$row['file_id'],
					(int) $row['customer_id'],
					$row['email'],
					self::new_token(),
					$row['downloads_remaining'] === null ? '' : (string) $row['downloads_remaining'],
					(string) $row['access_expires'],
					(int) ( $row['download_count'] ?? 0 ),
					$row['granted_at'],
					(string) ( $row['last_download_at'] ?? '' )
				)
			);
			if ( $done === false ) {
				throw QueryFailed::from_wpdb( $wpdb );
			}
			$added += (int) $done;
		}
		return $added;
	}

	public function find( int $id ): ?Permission {
		return $this->one( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'downloads' ), $id ) );
	}

	public function find_by_token( string $token ): ?Permission {
		if ( preg_match( '/^[a-f0-9]{32}$/', $token ) !== 1 ) {
			return null;
		}
		return $this->one( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE token = %s', Schema::table( 'downloads' ), $token ) );
	}

	/** @return list<Permission> in the order of the lines, then the files */
	public function for_order( int $order_id ): array {
		return $this->many( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE order_id = %d ORDER BY order_item_id, id', Schema::table( 'downloads' ), $order_id ) );
	}

	/**
	 * A customer's permissions on orders that allow downloads, newest order first.
	 *
	 * @return list<Permission>
	 */
	public function for_customer( int $customer_id ): array {
		$wpdb = $this->wpdb;
		return $this->many(
			$wpdb->prepare(
				'SELECT d.id, d.order_id, d.order_item_id, d.product_id, d.variant_id, d.file_id, d.customer_id, d.email, d.token, d.downloads_remaining, d.access_expires, d.download_count, d.granted_at, d.last_download_at FROM %i AS d INNER JOIN %i AS o ON o.id = d.order_id WHERE o.customer_id = %d AND o.status IN (%s, %s) ORDER BY d.order_id DESC, d.order_item_id, d.id LIMIT 500',
				Schema::table( 'downloads' ),
				Schema::table( 'orders' ),
				$customer_id,
				self::OPEN[0],
				self::OPEN[1]
			)
		);
	}

	/** @return list<Permission> */
	public function for_email( string $email, int $limit = 500, int $offset = 0 ): array {
		return $this->many( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE email = %s ORDER BY id LIMIT %d OFFSET %d', Schema::table( 'downloads' ), $email, $limit, $offset ) );
	}

	/**
	 * Lines of open orders that bought a variant, for a file added later.
	 *
	 * @return list<array{order_id:int,order_item_id:int,product_id:int,quantity:int,customer_id:?int,email:string,paid_at:?string,created_at:string}>
	 */
	public function open_lines( int $variant_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT i.order_id, i.id AS order_item_id, i.product_id, i.quantity, o.customer_id, o.customer_email, o.paid_at, o.created_at FROM %i AS i INNER JOIN %i AS o ON o.id = i.order_id WHERE i.variant_id = %d AND i.type = %s AND o.status IN (%s, %s) ORDER BY i.id LIMIT 5000',
				Schema::table( 'order_items' ),
				Schema::table( 'orders' ),
				$variant_id,
				'product',
				self::OPEN[0],
				self::OPEN[1]
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'order_id'      => (int) $row['order_id'],
				'order_item_id' => (int) $row['order_item_id'],
				'product_id'    => (int) $row['product_id'],
				'quantity'      => (int) $row['quantity'],
				'customer_id'   => (int) $row['customer_id'] > 0 ? (int) $row['customer_id'] : null,
				'email'         => (string) $row['customer_email'],
				'paid_at'       => isset( $row['paid_at'] ) ? (string) $row['paid_at'] : null,
				'created_at'    => (string) $row['created_at'],
			];
		}
		return $out;
	}

	/**
	 * Counts one download. False when none is left; `$take` false counts
	 * without taking one (the rest of a download that was cut off).
	 */
	public function consume( int $id, string $now, bool $take = true ): bool {
		$wpdb = $this->wpdb;
		$done = $take
			? $wpdb->query( $wpdb->prepare( 'UPDATE %i SET download_count = download_count + 1, downloads_remaining = IF(downloads_remaining IS NULL, NULL, downloads_remaining - 1), last_download_at = %s WHERE id = %d AND (downloads_remaining IS NULL OR downloads_remaining > 0)', Schema::table( 'downloads' ), $now, $id ) )
			: $wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_download_at = %s WHERE id = %d', Schema::table( 'downloads' ), $now, $id ) );
		if ( $done === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return $done > 0 || ! $take;
	}

	/**
	 * Admin changes: downloads left and the expiry date, null for no limit.
	 */
	public function update_limits( int $id, ?int $remaining, ?string $expires ): bool {
		$wpdb = $this->wpdb;
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET downloads_remaining = NULLIF(%s, \'\'), access_expires = NULLIF(%s, \'\') WHERE id = %d', Schema::table( 'downloads' ), $remaining === null ? '' : (string) $remaining, (string) $expires, $id ) );
		if ( $done === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return $done > 0;
	}

	public function delete( int $id ): bool {
		$wpdb = $this->wpdb;
		$done = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'downloads' ), $id ) );
		if ( $done === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return $done > 0;
	}

	public function delete_for_email( string $email ): int {
		$wpdb = $this->wpdb;
		$done = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', Schema::table( 'downloads' ), $email ) );
		if ( $done === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return (int) $done;
	}

	/**
	 * Removes the permissions of a variant's files that are gone.
	 *
	 * @param list<string> $keep file ids that still exist
	 */
	public function delete_missing_files( int $variant_id, array $keep ): int {
		$wpdb = $this->wpdb;
		if ( $keep === [] ) {
			$sql = $wpdb->prepare( 'DELETE FROM %i WHERE variant_id = %d', Schema::table( 'downloads' ), $variant_id );
		} else {
			$marks = implode( ', ', array_fill( 0, count( $keep ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $marks holds placeholders only.
			$sql = $wpdb->prepare( "DELETE FROM %i WHERE variant_id = %d AND file_id NOT IN ({$marks})", Schema::table( 'downloads' ), $variant_id, ...$keep );
		}
		$done = $wpdb->query( $sql );
		if ( $done === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return (int) $done;
	}

	private function one( string $sql ): ?Permission {
		$row = $this->wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return is_array( $row ) ? Permission::from_row( $row ) : null;
	}

	/** @return list<Permission> */
	private function many( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		if ( ! is_array( $rows ) ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return array_map( static fn ( array $row ): Permission => Permission::from_row( $row ), $rows );
	}

	private static function new_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}
