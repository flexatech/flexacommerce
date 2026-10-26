<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxc_coupons and fxc_coupon_uses. A cart reads its coupons by
 * code once per request; the usage count only moves inside the checkout
 * transaction (redeem) or the cancel transition (release).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared; coupon rows change at checkout, so they are not object-cached.
final class CouponRepository {
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	/** @var array<string,?Coupon> by normalized code, for this request */
	private array $by_code = [];

	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find( int $id ): ?Coupon {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'coupons' ), $id ), ARRAY_A );
		return is_array( $row ) ? Coupon::from_row( $row ) : null;
	}

	/**
	 * Coupons for normalized codes in one query; a code without a row maps to null.
	 *
	 * @param list<string> $codes
	 * @return array<string,?Coupon>
	 */
	public function by_codes( array $codes ): array {
		$missing = array_values( array_diff( $codes, array_keys( $this->by_code ) ) );
		if ( $missing !== [] ) {
			foreach ( $missing as $code ) {
				$this->by_code[ $code ] = null;
			}
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%s' ) );
			$rows         = $this->wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %s per code.
				$this->wpdb->prepare( "SELECT * FROM %i WHERE code IN ({$placeholders})", Schema::table( 'coupons' ), ...$missing ),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$coupon = Coupon::from_row( $row );
				$this->by_code[ Coupon::normalize( $coupon->code ) ] = $coupon;
			}
		}
		$out = [];
		foreach ( $codes as $code ) {
			$out[ $code ] = $this->by_code[ $code ] ?? null;
		}
		return $out;
	}

	public function code_taken( string $code, int $except_id = 0 ): bool {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE code = %s AND id <> %d', Schema::table( 'coupons' ), trim( $code ), $except_id )
		) > 0;
	}

	/**
	 * @param array{search?:string,status?:string,page?:int,per_page?:int} $args
	 * @return array{coupons:list<Coupon>,total:int}
	 */
	public function query( array $args ): array {
		$where  = [ '1=1' ];
		$params = [ Schema::table( 'coupons' ) ];
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( $search !== '' ) {
			$where[]  = '(code LIKE %s OR description LIKE %s)';
			$like     = '%' . $this->wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$status = (string) ( $args['status'] ?? '' );
		if ( in_array( $status, [ Coupon::ACTIVE, Coupon::DISABLED ], true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$clause   = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the clause holds placeholders only.
		$total = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$clause}", ...$params ) );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d", ...[ ...$params, $per_page, ( $page - 1 ) * $per_page ] ),
			ARRAY_A
		);
		// phpcs:enable

		return [
			'coupons' => array_map( [ Coupon::class, 'from_row' ], is_array( $rows ) ? $rows : [] ),
			'total'   => $total,
		];
	}

	/** @param array<string,mixed> $fields columns of fxc_coupons except id, usage_count and the timestamps; `rules` is an array */
	public function insert( array $fields, string $now ): int {
		$row = self::row( $fields ) + [
			'usage_count' => 0,
			'created_at'  => $now,
			'updated_at'  => $now,
		];
		if ( $this->wpdb->insert( Schema::table( 'coupons' ), $row ) === false ) {
			throw new QueryFailed( 'Could not save the coupon: ' . $this->wpdb->last_error );
		}
		$this->by_code = [];
		return (int) $this->wpdb->insert_id;
	}

	/** @param array<string,mixed> $fields */
	public function update( int $id, array $fields, string $now ): void {
		$row = self::row( $fields ) + [ 'updated_at' => $now ];
		if ( $this->wpdb->update( Schema::table( 'coupons' ), $row, [ 'id' => $id ] ) === false ) {
			throw new QueryFailed( 'Could not save the coupon: ' . $this->wpdb->last_error );
		}
		$this->by_code = [];
	}

	/** Deletes the coupon and its use rows. Orders keep their discount lines. */
	public function delete( int $id ): void {
		$this->wpdb->delete( Schema::table( 'coupon_uses' ), [ 'coupon_id' => $id ], [ '%d' ] );
		$this->wpdb->delete( Schema::table( 'coupons' ), [ 'id' => $id ], [ '%d' ] );
		$this->by_code = [];
	}

	/** Orders that used the coupon by this account or this billing email. */
	public function uses_by( int $coupon_id, int $user_id, string $email ): int {
		$email = strtolower( trim( $email ) );
		if ( $user_id <= 0 && $email === '' ) {
			return 0;
		}
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE coupon_id = %d AND ((user_id = %d AND user_id > 0) OR (email = %s AND email <> %s))',
				Schema::table( 'coupon_uses' ),
				$coupon_id,
				$user_id,
				$email,
				''
			)
		);
	}

	/**
	 * Takes one use under the total limit. The UPDATE locks the coupon row
	 * until the checkout transaction ends, so concurrent checkouts with the
	 * same coupon queue here and the per-customer count that follows is exact.
	 */
	public function take_use( int $coupon_id ): bool {
		$changed = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET usage_count = usage_count + 1 WHERE id = %d AND status = %s AND (usage_limit = 0 OR usage_count < usage_limit)',
				Schema::table( 'coupons' ),
				$coupon_id,
				Coupon::ACTIVE
			)
		);
		if ( $changed === false ) {
			throw new QueryFailed( 'Could not update the coupon usage: ' . $this->wpdb->last_error );
		}
		return $changed === 1;
	}

	public function record_use( int $coupon_id, int $order_id, int $user_id, string $email, string $now ): void {
		$ok = $this->wpdb->insert(
			Schema::table( 'coupon_uses' ),
			[
				'coupon_id'  => $coupon_id,
				'order_id'   => $order_id,
				'user_id'    => $user_id > 0 ? $user_id : null,
				'email'      => strtolower( trim( $email ) ),
				'created_at' => $now,
			],
			[ '%d', '%d', '%d', '%s', '%s' ]
		);
		if ( $ok === false ) {
			throw new QueryFailed( 'Could not record the coupon use: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * Gives back the uses of an order (cancelled). Returns the coupon ids released.
	 *
	 * @return list<int>
	 */
	public function release_order( int $order_id ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare( 'SELECT coupon_id FROM %i WHERE order_id = %d FOR UPDATE', Schema::table( 'coupon_uses' ), $order_id )
		);
		$ids = array_values( array_map( 'intval', $ids ) );
		if ( $ids === [] ) {
			return [];
		}
		$this->wpdb->delete( Schema::table( 'coupon_uses' ), [ 'order_id' => $order_id ], [ '%d' ] );
		foreach ( $ids as $id ) {
			$this->wpdb->query(
				$this->wpdb->prepare( 'UPDATE %i SET usage_count = usage_count - 1 WHERE id = %d AND usage_count > 0', Schema::table( 'coupons' ), $id )
			);
		}
		return $ids;
	}

	/**
	 * Who used the coupon, one entry per use: the user id, or the email of a guest.
	 *
	 * @return list<string>
	 */
	public function used_by( int $coupon_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT user_id, email FROM %i WHERE coupon_id = %d ORDER BY id', Schema::table( 'coupon_uses' ), $coupon_id ),
			ARRAY_A
		);
		return array_map(
			static fn ( array $row ): string => (int) $row['user_id'] > 0 ? (string) $row['user_id'] : (string) $row['email'],
			is_array( $rows ) ? $rows : []
		);
	}

	/** Sets the usage count of an imported coupon, which the editor never writes. */
	public function set_usage_count( int $coupon_id, int $count ): void {
		$this->wpdb->update( Schema::table( 'coupons' ), [ 'usage_count' => max( 0, $count ) ], [ 'id' => $coupon_id ], [ '%d' ], [ '%d' ] );
		$this->by_code = [];
	}

	/**
	 * Use rows for the order lines of type $line_type, skipping orders in
	 * $skip_status, so per-customer limits count imported orders. The caller
	 * passes the Orders values; this module does not depend on Orders.
	 * Existing rows are kept. Returns the rows added.
	 */
	public function backfill_uses( string $line_type, string $skip_status ): int {
		$added = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT IGNORE INTO %i (coupon_id, order_id, user_id, email, created_at)
				SELECT c.id, o.id, o.customer_id, LOWER(o.customer_email), o.created_at
				FROM %i i JOIN %i o ON o.id = i.order_id JOIN %i c ON c.code = i.code
				WHERE i.type = %s AND i.code <> %s AND o.status <> %s',
				Schema::table( 'coupon_uses' ),
				Schema::table( 'order_items' ),
				Schema::table( 'orders' ),
				Schema::table( 'coupons' ),
				$line_type,
				'',
				$skip_status
			)
		);
		if ( $added === false ) {
			throw new QueryFailed( 'Could not record the coupon uses: ' . $this->wpdb->last_error );
		}
		return (int) $added;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private static function row( array $fields ): array {
		$row = $fields;
		if ( array_key_exists( 'rules', $row ) ) {
			$row['rules'] = wp_json_encode( $row['rules'], self::JSON_FLAGS );
		}
		foreach ( [ 'individual_use', 'exclude_sale_items', 'free_shipping' ] as $flag ) {
			if ( array_key_exists( $flag, $row ) ) {
				$row[ $flag ] = $row[ $flag ] ? 1 : 0;
			}
		}
		unset( $row['id'], $row['usage_count'], $row['created_at'], $row['updated_at'] );
		return $row;
	}
}
