<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * The `fxc_session` cookie (doc 06 §2): HttpOnly, SameSite=Lax, Secure on
 * HTTPS, path COOKIEPATH. A token set during this request is visible to
 * later reads in the same request. `fxc_cart_count` is readable by scripts
 * and holds only the item count.
 */
final class CookieTokenStore implements TokenStore {
	public const COOKIE = 'fxc_session';

	public const COUNT_COOKIE = 'fxc_cart_count';

	private ?int $count = null;

	private ?string $current = null;

	private bool $cleared = false;

	public function get(): ?string {
		if ( $this->cleared ) {
			return null;
		}
		if ( $this->current !== null ) {
			return $this->current;
		}
		$raw = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the pattern below.
		return preg_match( '/^[A-Za-z0-9_-]{43}$/', $raw ) === 1 ? $raw : null;
	}

	public function set( string $token, int $expires ): void {
		$this->current = $token;
		$this->cleared = false;
		$this->send( $token, $expires );
	}

	public function clear(): void {
		$this->current = null;
		$this->cleared = true;
		$this->send( '', time() - YEAR_IN_SECONDS );
		$this->set_count( 0, 0 );
	}

	public function set_count( int $count, int $expires ): void {
		$count = max( 0, $count );
		if ( $this->count === null ) {
			$this->count = isset( $_COOKIE[ self::COUNT_COOKIE ] ) && is_string( $_COOKIE[ self::COUNT_COOKIE ] ) ? max( 0, (int) $_COOKIE[ self::COUNT_COOKIE ] ) : 0;
		}
		if ( $count === $this->count ) {
			return;
		}
		$this->count = $count;
		$this->send( $count > 0 ? (string) $count : '', $count > 0 ? $expires : time() - YEAR_IN_SECONDS, self::COUNT_COOKIE, false );
	}

	private function send( string $value, int $expires, string $name = self::COOKIE, bool $http_only = true ): void {
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
