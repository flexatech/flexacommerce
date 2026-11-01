<?php

declare(strict_types=1);

namespace Flexa\Commerce\Wishlist;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxc_wishlists and fxc_wishlist_items (ADR-066). A list has
 * one row per product; adding a product twice keeps the first row.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared; a list changes on every click, so it is not object-cached.
final class WishlistRepository {
	public function __construct( private readonly \wpdb $wpdb ) {}

	public function for_user( int $user_id ): ?Wishlist {
		return $this->one( $this->wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', Schema::table( 'wishlists' ), $user_id ) );
	}

	public function for_token( string $token_hash ): ?Wishlist {
		return $this->one( $this->wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND user_id IS NULL', Schema::table( 'wishlists' ), $token_hash ) );
	}

	public function for_share( string $share_key ): ?Wishlist {
		return $this->one( $this->wpdb->prepare( 'SELECT * FROM %i WHERE share_key = %s', Schema::table( 'wishlists' ), $share_key ) );
	}

	/** A list of a customer (`$user_id`), or of a guest (`$token_hash`). */
	public function create( int $user_id, ?string $token_hash, string $now ): Wishlist {
		$ok = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (user_id, token_hash, share_key, created_at, updated_at) VALUES (NULLIF(%d, 0), NULLIF(%s, %s), %s, %s, %s)',
				Schema::table( 'wishlists' ),
				$user_id,
				(string) $token_hash,
				'',
				bin2hex( random_bytes( 16 ) ),
				$now,
				$now
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		$list = $this->one( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'wishlists' ), (int) $this->wpdb->insert_id ) );
		if ( $list === null ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return $list;
	}

	/** A guest's list becomes the customer's; the token no longer finds it. */
	public function assign( int $wishlist_id, int $user_id, string $now ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'UPDATE %i SET user_id = %d, token_hash = NULL, updated_at = %s WHERE id = %d', Schema::table( 'wishlists' ), $user_id, $now, $wishlist_id )
		);
	}

	/** The products of `$from` join `$into`, up to `$room` of them, newest first; `$from` goes. */
	public function merge( int $from, int $into, int $room, string $now ): void {
		if ( $room > 0 ) {
			$this->wpdb->query(
				$this->wpdb->prepare(
					'INSERT IGNORE INTO %i (wishlist_id, product_id, created_at) SELECT %d, product_id, created_at FROM %i WHERE wishlist_id = %d ORDER BY created_at DESC, product_id DESC LIMIT %d',
					Schema::table( 'wishlist_items' ),
					$into,
					Schema::table( 'wishlist_items' ),
					$from,
					$room
				)
			);
		}
		$this->delete( $from );
		$this->touch( $into, $now );
	}

	/** @return list<int> product ids, the last added first */
	public function items( int $wishlist_id ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare( 'SELECT product_id FROM %i WHERE wishlist_id = %d ORDER BY created_at DESC, product_id DESC', Schema::table( 'wishlist_items' ), $wishlist_id )
		);
		return array_map( 'intval', $ids );
	}

	public function add( int $wishlist_id, int $product_id, string $now ): bool {
		$ok = $this->wpdb->query(
			$this->wpdb->prepare( 'INSERT IGNORE INTO %i (wishlist_id, product_id, created_at) VALUES (%d, %d, %s)', Schema::table( 'wishlist_items' ), $wishlist_id, $product_id, $now )
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return $ok > 0;
	}

	public function remove( int $wishlist_id, int $product_id ): bool {
		return (int) $this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE wishlist_id = %d AND product_id = %d', Schema::table( 'wishlist_items' ), $wishlist_id, $product_id )
		) > 0;
	}

	public function touch( int $wishlist_id, string $now ): void {
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE %i SET updated_at = %s WHERE id = %d', Schema::table( 'wishlists' ), $now, $wishlist_id ) );
	}

	public function delete( int $wishlist_id ): void {
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE wishlist_id = %d', Schema::table( 'wishlist_items' ), $wishlist_id ) );
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'wishlists' ), $wishlist_id ) );
	}

	/**
	 * Guest lists untouched since `$before`, lists of deleted users, and
	 * rows of deleted products go.
	 */
	public function purge( string $before ): int {
		$lists = Schema::table( 'wishlists' );
		$items = Schema::table( 'wishlist_items' );
		$old   = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE w FROM %i w LEFT JOIN %i u ON u.ID = w.user_id WHERE ( w.user_id IS NULL AND w.updated_at < %s ) OR ( w.user_id IS NOT NULL AND u.ID IS NULL )',
				$lists,
				$this->wpdb->users,
				$before
			)
		);
		$this->wpdb->query(
			$this->wpdb->prepare( 'DELETE i FROM %i i LEFT JOIN %i w ON w.id = i.wishlist_id WHERE w.id IS NULL', $items, $lists )
		);
		$gone = (int) $this->wpdb->query(
			$this->wpdb->prepare( 'DELETE i FROM %i i LEFT JOIN %i p ON p.ID = i.product_id WHERE p.ID IS NULL', $items, $this->wpdb->posts )
		);
		return $old + $gone;
	}

	private function one( string $sql ): ?Wishlist {
		$row = $this->wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		return is_array( $row ) ? Wishlist::from_row( $row ) : null;
	}
}
