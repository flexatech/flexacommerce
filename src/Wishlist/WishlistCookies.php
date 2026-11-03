<?php

declare(strict_types=1);

namespace Flexa\Commerce\Wishlist;

defined( 'ABSPATH' ) || exit;

/**
 * The wishlist cookies (ADR-066). `fxcm_wishlist` holds a guest's token:
 * HttpOnly, SameSite=Lax, like `fxcm_session`. `fxcm_wishlist_ids` is
 * readable by scripts and holds the user id the list belongs to and the
 * product ids in it ("5:12.34", "0:" for an empty guest list), so a cached
 * product page can show a filled heart without a request.
 */
final class WishlistCookies {
	public const TOKEN = 'fxcm_wishlist';
	public const IDS   = 'fxcm_wishlist_ids';

	public const LIFETIME = 90 * DAY_IN_SECONDS;

	private ?string $current = null;

	private bool $cleared = false;

	public function token(): ?string {
		if ( $this->cleared ) {
			return null;
		}
		if ( $this->current !== null ) {
			return $this->current;
		}
		$raw = isset( $_COOKIE[ self::TOKEN ] ) && is_string( $_COOKIE[ self::TOKEN ] ) ? wp_unslash( $_COOKIE[ self::TOKEN ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the pattern below.
		return preg_match( '/^[A-Za-z0-9_-]{43}$/', $raw ) === 1 ? $raw : null;
	}

	public function set_token( string $token ): void {
		$this->current = $token;
		$this->cleared = false;
		self::send( self::TOKEN, $token, time() + self::LIFETIME, true );
	}

	public function clear_token(): void {
		$this->current = null;
		$this->cleared = true;
		self::send( self::TOKEN, '', time() - YEAR_IN_SECONDS, true );
	}

	/** @param list<int> $product_ids */
	public function set_ids( int $user_id, array $product_ids ): void {
		$value                = $user_id . ':' . implode( '.', $product_ids );
		$_COOKIE[ self::IDS ] = $value;
		self::send( self::IDS, $value, time() + self::LIFETIME, false );
	}

	public static function clear_ids(): void {
		unset( $_COOKIE[ self::IDS ] );
		self::send( self::IDS, '', time() - YEAR_IN_SECONDS, false );
	}

	private static function send( string $name, string $value, int $expires, bool $http_only ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			[
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH !== '' ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => $http_only,
				'samesite' => 'Lax',
			]
		);
	}
}
