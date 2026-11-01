<?php

declare(strict_types=1);

namespace Flexa\Commerce\GiftCards;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxc_gift_cards and fxc_gift_card_activity (ADR-069). Balance
 * changes lock the card row first and run inside the caller's transaction.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared; balances change at checkout and refunds, so they are not object-cached.
final class GiftCardRepository {
	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find( int $id, bool $lock = false ): ?GiftCard {
		$sql = 'SELECT * FROM %i WHERE id = %d' . ( $lock ? ' FOR UPDATE' : '' );
		return $this->one( $this->wpdb->prepare( $sql, Schema::table( 'gift_cards' ), $id ) );
	}

	public function find_by_code( string $code, bool $lock = false ): ?GiftCard {
		$sql = 'SELECT * FROM %i WHERE code = %s' . ( $lock ? ' FOR UPDATE' : '' );
		return $this->one( $this->wpdb->prepare( $sql, Schema::table( 'gift_cards' ), $code ) );
	}

	/** The store credit of a customer (ADR-072). */
	public function for_user( int $user_id, bool $lock = false ): ?GiftCard {
		$sql = 'SELECT * FROM %i WHERE user_id = %d' . ( $lock ? ' FOR UPDATE' : '' );
		return $this->one( $this->wpdb->prepare( $sql, Schema::table( 'gift_cards' ), $user_id ) );
	}

	/**
	 * The store credit accounts of several customers, for a list screen.
	 *
	 * @param list<int> $user_ids
	 * @return array<int,GiftCard> by user id
	 */
	public function for_users( array $user_ids ): array {
		if ( $user_ids === [] ) {
			return [];
		}
		$in  = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$out = [];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is placeholders only.
		foreach ( $this->many( $this->wpdb->prepare( "SELECT * FROM %i WHERE user_id IN ({$in})", Schema::table( 'gift_cards' ), ...$user_ids ) ) as $card ) {
			$out[ (int) $card->user_id ] = $card;
		}
		return $out;
	}

	/**
	 * @param list<string> $codes
	 * @return array<string,GiftCard> by code
	 */
	public function by_codes( array $codes ): array {
		if ( $codes === [] ) {
			return [];
		}
		$in  = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$out = [];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is placeholders only.
		foreach ( $this->many( $this->wpdb->prepare( "SELECT * FROM %i WHERE code IN ({$in})", Schema::table( 'gift_cards' ), ...$codes ) ) as $card ) {
			$out[ $card->code ] = $card;
		}
		return $out;
	}

	/** @return list<GiftCard> cards bought in the order */
	public function for_order( int $order_id ): array {
		return $this->many( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'gift_cards' ), $order_id ) );
	}

	/** @param array<string,mixed> $fields column => value, without id and the dates */
	public function insert( array $fields, string $now ): int {
		$row = $fields + [
			'created_at' => $now,
			'updated_at' => $now,
		];
		if ( $this->wpdb->insert( Schema::table( 'gift_cards' ), $row, self::formats( $row ) ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	/** @param array<string,mixed> $fields */
	public function update( int $id, array $fields, string $now ): void {
		$row = $fields + [ 'updated_at' => $now ];
		if ( $this->wpdb->update( Schema::table( 'gift_cards' ), $row, [ 'id' => $id ], self::formats( $row ), [ '%d' ] ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @return list<GiftCard> cards whose email waits for its day */
	public function due_delivery( string $now, int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE delivered_at IS NULL AND deliver_at IS NOT NULL AND deliver_at <= %s AND status = %s AND recipient_email <> %s ORDER BY deliver_at, id LIMIT %d',
				Schema::table( 'gift_cards' ),
				$now,
				GiftCard::ACTIVE,
				'',
				$limit
			)
		);
	}

	public function add_activity( int $card_id, string $type, int $amount, int $balance_after, ?int $order_id, ?int $transaction_id, string $note, ?int $user_id, string $now ): int {
		$ok = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (gift_card_id, type, amount, balance_after, order_id, transaction_id, note, user_id, created_at) VALUES (%d, %s, %d, %d, NULLIF(%d, 0), NULLIF(%d, 0), %s, NULLIF(%d, 0), %s)',
				Schema::table( 'gift_card_activity' ),
				$card_id,
				$type,
				$amount,
				$balance_after,
				(int) $order_id,
				(int) $transaction_id,
				mb_substr( $note, 0, 500 ),
				(int) $user_id,
				$now
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	/** @return list<GiftCardActivity> newest first */
	public function activity( int $card_id, int $limit = 1000 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE gift_card_id = %d ORDER BY id DESC LIMIT %d', Schema::table( 'gift_card_activity' ), $card_id, $limit ),
			ARRAY_A
		);
		return array_map( static fn ( array $row ): GiftCardActivity => GiftCardActivity::from_row( $row ), is_array( $rows ) ? $rows : [] );
	}

	/** Whether a row of this type already records the transaction, so a retried refund credits once. */
	public function has_activity( int $card_id, string $type, int $transaction_id ): bool {
		return $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT 1 FROM %i WHERE gift_card_id = %d AND type = %s AND transaction_id = %d LIMIT 1',
				Schema::table( 'gift_card_activity' ),
				$card_id,
				$type,
				$transaction_id
			)
		) !== null;
	}

	/**
	 * @return array{rows:list<GiftCard>,total:int}
	 */
	public function page( string $search, string $status, int $page, int $per_page, string $now ): array {
		// Store credit has its own place on the customer (ADR-072).
		$where = [ 'user_id IS NULL' ];
		if ( $search !== '' ) {
			$code    = GiftCard::normalize( $search );
			$like    = '%' . $this->wpdb->esc_like( $search ) . '%';
			$where[] = $this->wpdb->prepare( '(code LIKE %s OR recipient_email LIKE %s OR recipient_name LIKE %s OR order_id = %d)', '%' . $this->wpdb->esc_like( $code ) . '%', $like, $like, ctype_digit( $search ) ? (int) $search : 0 );
		}
		if ( $status === 'expired' ) {
			$where[] = $this->wpdb->prepare( 'expires_at IS NOT NULL AND expires_at <= %s', $now );
		} elseif ( $status === 'used' ) {
			$where[] = 'balance <= 0';
		} elseif ( in_array( $status, GiftCard::STATUSES, true ) ) {
			$where[] = $this->wpdb->prepare( 'status = %s', $status );
		}
		$sql   = 'WHERE ' . implode( ' AND ', $where );
		$table = Schema::table( 'gift_cards' );
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

	/** @return list<GiftCard> cards sent to the address */
	public function for_email( string $email ): array {
		return $this->many( $this->wpdb->prepare( 'SELECT * FROM %i WHERE recipient_email = %s ORDER BY id', Schema::table( 'gift_cards' ), $email ) );
	}

	/** Clears the recipient on the address's cards; the codes and balances stay usable. */
	public function anonymize_email( string $email, string $now ): int {
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET recipient_email = '', recipient_name = '', sender_name = '', message = NULL, updated_at = %s WHERE recipient_email = %s",
				Schema::table( 'gift_cards' ),
				$now,
				$email
			)
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return list<string>
	 */
	private static function formats( array $row ): array {
		$ints = [ 'initial_amount', 'balance', 'order_id', 'order_item_id', 'user_id' ];
		return array_map( static fn ( string $key ): string => in_array( $key, $ints, true ) ? '%d' : '%s', array_keys( $row ) );
	}

	private function one( string $sql ): ?GiftCard {
		$row = $this->wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return is_array( $row ) ? GiftCard::from_row( $row ) : null;
	}

	/** @return list<GiftCard> */
	private function many( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return array_map( static fn ( array $row ): GiftCard => GiftCard::from_row( $row ), is_array( $rows ) ? $rows : [] );
	}
}
