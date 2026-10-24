<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * CSRF token for the store API (`X-FXC-Nonce`, doc 06 §2). Bound to the
 * user, their login session and the cart session token, valid for two
 * 12-hour ticks like core nonces. A visitor without a cart session gets the
 * same guest nonce as every other such visitor, which is what lets catalog
 * pages that print it stay cacheable; a stale one is answered with a fresh
 * nonce in the response header and the script retries once.
 *
 * Because that guest nonce is public anyway, a guest who has a cart also
 * passes with it: a cached product page then still works without a retry
 * or JavaScript. For guests the real CSRF defence is the same-site check
 * and the SameSite=Lax cookie; for users it is the login-token binding.
 */
final class SessionNonce {
	public const HEADER = 'X-FXC-Nonce';

	private const ACTION = 'flexacommerce_store';

	private const TICK = 43200;

	/** @param string $secret normally wp_salt( 'nonce' ) */
	public function __construct(
		private readonly string $secret,
		private readonly ?\Closure $time = null,
	) {}

	public function create( int $user_id, string $login_token, ?string $session_token ): string {
		return $this->sign( $this->tick(), $user_id, $login_token, $session_token );
	}

	public function verify( string $nonce, int $user_id, string $login_token, ?string $session_token ): bool {
		if ( $nonce === '' ) {
			return false;
		}
		$tick     = $this->tick();
		$sessions = $user_id <= 0 && $session_token !== null ? [ $session_token, null ] : [ $session_token ];
		foreach ( $sessions as $session ) {
			foreach ( [ $tick, $tick - 1 ] as $candidate ) {
				if ( hash_equals( $this->sign( $candidate, $user_id, $login_token, $session ), $nonce ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function tick(): int {
		$now = $this->time !== null ? (int) ( $this->time )() : time();
		return (int) ceil( $now / self::TICK );
	}

	private function sign( int $tick, int $user_id, string $login_token, ?string $session_token ): string {
		$session = $session_token !== null && $session_token !== '' ? SessionStore::hash( $session_token ) : '';
		return substr( hash_hmac( 'sha256', implode( '|', [ $tick, self::ACTION, $user_id, $login_token, $session ] ), $this->secret ), 0, 24 );
	}
}
