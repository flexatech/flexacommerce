<?php

declare(strict_types=1);

namespace Flexa\Commerce\Wishlist;

use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Support\ClientIp;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wishlists (ADR-066). A customer has one list, a guest has one found by
 * the `fxc_wishlist` cookie. A guest who signs in keeps what they saved:
 * the first request that reads the list moves the guest list into the
 * account. Every change rewrites the script-readable ids cookie, so cached
 * pages can show the heart filled.
 */
final class WishlistService {
	public const MAX_ITEMS = 200;

	/** Guest lists not changed for this long go on the daily run. */
	public const KEEP_DAYS = 90;

	/** New guest lists per IP and hour. */
	public const LIMIT = 'wishlist_ip';

	public function __construct(
		private readonly WishlistRepository $lists,
		private readonly ProductRepository $products,
		private readonly WishlistCookies $cookies,
		private readonly Clock $clock,
	) {}

	public static function enabled(): bool {
		return Settings::string( 'wishlist' ) !== 'off';
	}

	public function repository(): WishlistRepository {
		return $this->lists;
	}

	/**
	 * The list of the visitor, or null when they have none and `$create` is
	 * false. A guest list in the cookie moves into the account here.
	 *
	 * @throws WishlistRefused When a new guest list goes over the IP limit.
	 */
	public function current( int $user_id, bool $create = false ): ?Wishlist {
		$token = $this->cookies->token();
		$guest = $token !== null ? $this->lists->for_token( self::hash( $token ) ) : null;
		if ( $user_id > 0 ) {
			$list = $this->lists->for_user( $user_id );
			if ( $guest !== null ) {
				$now = $this->clock->db_now();
				if ( $list === null ) {
					$this->lists->assign( $guest->id, $user_id, $now );
					$list = $this->lists->for_user( $user_id );
				} else {
					$this->lists->merge( $guest->id, $list->id, self::MAX_ITEMS - count( $this->lists->items( $list->id ) ), $now );
				}
				$this->cookies->clear_token();
				if ( $list !== null ) {
					$this->cookies->set_ids( $user_id, $this->lists->items( $list->id ) );
				}
			} elseif ( $token !== null ) {
				$this->cookies->clear_token();
			}
			if ( $list === null && $create ) {
				$list = $this->lists->create( $user_id, null, $this->clock->db_now() );
			}
			return $list;
		}
		if ( $guest !== null || ! $create ) {
			return $guest;
		}
		try {
			( new RateLimiter() )->hit( self::LIMIT, ClientIp::get() );
		} catch ( RateLimited ) {
			throw new WishlistRefused( 'limit' );
		}
		$token = self::new_token();
		$list  = $this->lists->create( 0, self::hash( $token ), $this->clock->db_now() );
		$this->cookies->set_token( $token );
		return $list;
	}

	/** @return list<int> the products the visitor saved, the last added first */
	public function ids( int $user_id ): array {
		$list = $this->current( $user_id );
		return $list !== null ? $this->lists->items( $list->id ) : [];
	}

	/**
	 * @return list<int> the products in the list after the change
	 * @throws WishlistRefused
	 */
	public function add( int $user_id, int $product_id ): array {
		if ( ! self::enabled() ) {
			throw new WishlistRefused( 'off' );
		}
		$product = $this->products->find( $product_id );
		if ( $product === null || $product->status !== 'publish' ) {
			throw new WishlistRefused( 'product' );
		}
		$list = $this->current( $user_id, true );
		if ( $list === null ) {
			throw new WishlistRefused( 'product' );
		}
		$ids = $this->lists->items( $list->id );
		if ( ! in_array( $product_id, $ids, true ) ) {
			if ( count( $ids ) >= self::MAX_ITEMS ) {
				throw new WishlistRefused( 'full' );
			}
			$now = $this->clock->db_now();
			$this->lists->add( $list->id, $product_id, $now );
			$this->lists->touch( $list->id, $now );
			array_unshift( $ids, $product_id );
			/**
			 * A shopper saved a product to their wishlist.
			 *
			 * @param int $product_id
			 * @param int $user_id 0 for a guest.
			 */
			do_action( 'flexacommerce.wishlist.added', $product_id, $user_id );
		}
		$this->saved( $user_id, $ids );
		return $ids;
	}

	/** @return list<int> the products in the list after the change */
	public function remove( int $user_id, int $product_id ): array {
		$list = $this->current( $user_id );
		if ( $list === null ) {
			$this->saved( $user_id, [] );
			return [];
		}
		if ( $this->lists->remove( $list->id, $product_id ) ) {
			$this->lists->touch( $list->id, $this->clock->db_now() );
		}
		$ids = $this->lists->items( $list->id );
		$this->saved( $user_id, $ids );
		return $ids;
	}

	/** The list behind a share link. */
	public function shared( string $key ): ?Wishlist {
		return preg_match( '/^[a-f0-9]{32}$/', $key ) === 1 ? $this->lists->for_share( $key ) : null;
	}

	/** @return list<int> */
	public function items( Wishlist $list ): array {
		return $this->lists->items( $list->id );
	}

	/** The daily cron. */
	public function daily(): void {
		$this->lists->purge( $this->clock->db_ago( self::KEEP_DAYS * DAY_IN_SECONDS ) );
	}

	/** @param list<int> $ids */
	private function saved( int $user_id, array $ids ): void {
		$this->cookies->set_ids( $user_id, $ids );
		if ( $user_id === 0 && $this->cookies->token() !== null ) {
			// A guest's token lives as long as the list is used.
			$this->cookies->set_token( (string) $this->cookies->token() );
		}
	}

	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/** 32 random bytes, base64url without padding: 43 characters. */
	private static function new_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes a random token.
	}
}
