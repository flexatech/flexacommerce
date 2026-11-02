<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxc_subscriptions, fxc_subscription_orders (which orders a
 * subscription made: `parent` or `renewal`) and fxc_subscription_events
 * (ADR-073).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared; rows change on every renewal, so they are not object-cached.
final class SubscriptionRepository {
	public const PARENT  = 'parent';
	public const RENEWAL = 'renewal';
	/** An order that pays a plan switch (ADR-077). */
	public const SWITCH = 'switch';

	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find( int $id, bool $lock = false ): ?Subscription {
		$sql = 'SELECT * FROM %i WHERE id = %d' . ( $lock ? ' FOR UPDATE' : '' );
		return $this->one( $this->wpdb->prepare( $sql, Schema::table( 'subscriptions' ), $id ) );
	}

	/** @return list<Subscription> what an order started at checkout */
	public function for_parent( int $order_id ): array {
		return $this->many( $this->wpdb->prepare( 'SELECT * FROM %i WHERE parent_order_id = %d ORDER BY id', Schema::table( 'subscriptions' ), $order_id ) );
	}

	/** @return list<Subscription> what a renewal order bills, or a switch order with `$relation` SWITCH */
	public function for_renewal( int $order_id, string $relation = self::RENEWAL ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT s.* FROM %i s INNER JOIN %i r ON r.subscription_id = s.id WHERE r.order_id = %d AND r.relation = %s ORDER BY s.id',
				Schema::table( 'subscriptions' ),
				Schema::table( 'subscription_orders' ),
				$order_id,
				$relation
			)
		);
	}

	/** @return list<Subscription> newest first */
	public function for_customer( int $user_id ): array {
		return $this->many( $this->wpdb->prepare( 'SELECT * FROM %i WHERE customer_id = %d ORDER BY id DESC', Schema::table( 'subscriptions' ), $user_id ) );
	}

	/** @return list<Subscription> active rows whose payment day has come */
	public function due( string $now, int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND billing_held = 0 AND next_payment_at IS NOT NULL AND next_payment_at <= %s ORDER BY next_payment_at, id LIMIT %d',
				Schema::table( 'subscriptions' ),
				SubscriptionStatus::ACTIVE,
				$now,
				$limit
			)
		);
	}

	/** @return list<Subscription> active rows renewing after $now and by $until that were not reminded of that date (ADR-075) */
	public function to_remind( string $now, string $until, int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND billing_held = 0 AND next_payment_at > %s AND next_payment_at <= %s AND ( reminded_for IS NULL OR reminded_for <> next_payment_at ) ORDER BY next_payment_at, id LIMIT %d',
				Schema::table( 'subscriptions' ),
				SubscriptionStatus::ACTIVE,
				$now,
				$until,
				$limit
			)
		);
	}

	/** @return list<Subscription> live rows whose saved payment method was not looked up yet (ADR-074) */
	public function uncaptured( int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE status IN (%s, %s, %s) AND billing_held = 0 AND payment_token IS NULL AND parent_order_id IS NOT NULL ORDER BY updated_at, id LIMIT %d',
				Schema::table( 'subscriptions' ),
				SubscriptionStatus::ACTIVE,
				SubscriptionStatus::ON_HOLD,
				SubscriptionStatus::PENDING_CANCEL,
				$limit
			)
		);
	}

	/** @return list<Subscription> on-hold rows whose failed automatic charge is due again */
	public function retry_due( string $now, int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND billing_held = 0 AND retry_at IS NOT NULL AND retry_at <= %s ORDER BY retry_at, id LIMIT %d',
				Schema::table( 'subscriptions' ),
				SubscriptionStatus::ON_HOLD,
				$now,
				$limit
			)
		);
	}

	/** @return list<Subscription> rows whose last paid period is over */
	public function ending( string $now, int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE status IN (%s, %s) AND billing_held = 0 AND next_payment_at IS NULL AND end_at IS NOT NULL AND end_at <= %s ORDER BY end_at, id LIMIT %d',
				Schema::table( 'subscriptions' ),
				SubscriptionStatus::ACTIVE,
				SubscriptionStatus::PENDING_CANCEL,
				$now,
				$limit
			)
		);
	}

	/** @param array<string,mixed> $fields column => value, without id and the dates */
	public function insert( array $fields, string $now ): int {
		$row = $fields + [
			'created_at' => $now,
			'updated_at' => $now,
		];
		if ( $this->wpdb->insert( Schema::table( 'subscriptions' ), $row, self::formats( $row ) ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	/** @param array<string,mixed> $fields */
	public function update( int $id, array $fields, string $now ): void {
		$row = $fields + [ 'updated_at' => $now ];
		if ( $this->wpdb->update( Schema::table( 'subscriptions' ), $row, [ 'id' => $id ], self::formats( $row ), [ '%d' ] ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	public function link( int $subscription_id, int $order_id, string $relation, string $now ): void {
		$ok = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT IGNORE INTO %i (subscription_id, order_id, relation, created_at) VALUES (%d, %d, %s, %s)',
				Schema::table( 'subscription_orders' ),
				$subscription_id,
				$order_id,
				$relation,
				$now
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @return list<array{order_id:int,relation:string}> oldest first */
	public function orders( int $subscription_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT order_id, relation FROM %i WHERE subscription_id = %d ORDER BY order_id', Schema::table( 'subscription_orders' ), $subscription_id ),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'order_id' => (int) $row['order_id'],
				'relation' => (string) $row['relation'],
			];
		}
		return $out;
	}

	public function add_event( int $subscription_id, string $type, string $message, ?int $order_id, string $actor_type, ?int $actor_id, string $now ): void {
		$ok = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (subscription_id, type, message, order_id, actor_type, actor_id, created_at) VALUES (%d, %s, %s, NULLIF(%d, 0), %s, NULLIF(%d, 0), %s)',
				Schema::table( 'subscription_events' ),
				$subscription_id,
				$type,
				mb_substr( $message, 0, 500 ),
				(int) $order_id,
				$actor_type,
				(int) $actor_id,
				$now
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @return list<array{type:string,message:string,order_id:?int,actor_type:string,actor_id:?int,created_at:string}> newest first */
	public function events( int $subscription_id, int $limit = 200 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d ORDER BY id DESC LIMIT %d', Schema::table( 'subscription_events' ), $subscription_id, $limit ),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'type'       => (string) $row['type'],
				'message'    => (string) $row['message'],
				'order_id'   => isset( $row['order_id'] ) ? (int) $row['order_id'] : null,
				'actor_type' => (string) $row['actor_type'],
				'actor_id'   => isset( $row['actor_id'] ) ? (int) $row['actor_id'] : null,
				'created_at' => (string) $row['created_at'],
			];
		}
		return $out;
	}

	/**
	 * @return array{rows:list<Subscription>,total:int}
	 */
	public function page( string $search, string $status, int $page, int $per_page, int $customer_id = 0 ): array {
		$where = [ '1=1' ];
		if ( $search !== '' ) {
			$like    = '%' . $this->wpdb->esc_like( $search ) . '%';
			$number  = ctype_digit( $search ) ? (int) $search : 0;
			$where[] = $this->wpdb->prepare( '(customer_email LIKE %s OR snapshot LIKE %s OR id = %d OR parent_order_id = %d)', $like, $like, $number, $number );
		}
		if ( in_array( $status, SubscriptionStatus::ALL, true ) ) {
			$where[] = $this->wpdb->prepare( 'status = %s', $status );
		}
		if ( $customer_id > 0 ) {
			$where[] = $this->wpdb->prepare( 'customer_id = %d', $customer_id );
		}
		$sql   = 'WHERE ' . implode( ' AND ', $where );
		$table = Schema::table( 'subscriptions' );
		$total = (int) $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql is prepared above.
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i {$sql}", $table )
		);
		$rows = $this->many(
			$this->wpdb->prepare(
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

	/** @return array<string,int> status => rows */
	public function counts(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', Schema::table( 'subscriptions' ) ), ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** Rows imported from WooCommerce Subscriptions that bill nothing yet (ADR-079). */
	public function held_count(): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE billing_held = 1', Schema::table( 'subscriptions' ) ) );
	}

	/** @return list<Subscription> held rows, oldest first */
	public function held( int $limit ): array {
		return $this->many( $this->wpdb->prepare( 'SELECT * FROM %i WHERE billing_held = 1 ORDER BY id LIMIT %d', Schema::table( 'subscriptions' ), $limit ) );
	}

	/** Removes a row with its order links and its events. */
	public function delete( int $id ): void {
		foreach ( [ 'subscription_events', 'subscription_orders' ] as $table ) {
			if ( $this->wpdb->delete( Schema::table( $table ), [ 'subscription_id' => $id ], [ '%d' ] ) === false ) {
				throw QueryFailed::from_wpdb( $this->wpdb );
			}
		}
		if ( $this->wpdb->delete( Schema::table( 'subscriptions' ), [ 'id' => $id ], [ '%d' ] ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @return list<Subscription> rows of an address, for the privacy export */
	public function for_email( string $email ): array {
		return $this->many( $this->wpdb->prepare( 'SELECT * FROM %i WHERE customer_email = %s ORDER BY id', Schema::table( 'subscriptions' ), $email ) );
	}

	/** Clears the address and the addresses of the ended rows of an email. */
	public function anonymize_email( string $email, string $now ): int {
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET customer_email = '', billing = NULL, shipping = NULL, payment_customer = '', payment_token = '', payment_label = '', updated_at = %s WHERE customer_email = %s AND status IN (%s, %s)",
				Schema::table( 'subscriptions' ),
				$now,
				$email,
				SubscriptionStatus::CANCELLED,
				SubscriptionStatus::EXPIRED
			)
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return list<string>
	 */
	private static function formats( array $row ): array {
		$ints = [ 'customer_id', 'interval_count', 'length', 'trial_days', 'anchor_day', 'billed', 'total', 'parent_order_id', 'retry_count', 'billing_held' ];
		return array_map( static fn ( string $key ): string => in_array( $key, $ints, true ) ? '%d' : '%s', array_keys( $row ) );
	}

	private function one( string $sql ): ?Subscription {
		$row = $this->wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return is_array( $row ) ? Subscription::from_row( $row ) : null;
	}

	/** @return list<Subscription> */
	private function many( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return array_map( static fn ( array $row ): Subscription => Subscription::from_row( $row ), is_array( $rows ) ? $rows : [] );
	}
}
