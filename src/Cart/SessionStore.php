<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side sessions (doc 02 §7.3.1, ADR-008). Nothing is read unless a
 * caller asks for the cart, and nothing is written (no row, no cookie)
 * until the first cart write. The cookie holds a random token; the table
 * stores its SHA-256.
 *
 * A logged-in visitor's session is the row with their user id. A guest row
 * found through the cookie is handed back as `guest` so CartService can
 * merge it the first time the cart is touched after login.
 */
final class SessionStore {
	/** Slide the expiry at most this often on reads. */
	private const TOUCH_AFTER = HOUR_IN_SECONDS;

	/** @var array<int,array{session:?Session,guest:?Session}> */
	private array $loaded = [];

	public function __construct(
		private readonly SessionRepository $repository,
		private readonly TokenStore $tokens,
		private readonly Clock $clock,
	) {}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/** True when the request carries a session cookie at all. No query. */
	public function has_token(): bool {
		return $this->tokens->get() !== null;
	}

	/** Raw token for binding nonces; null for a visitor without a session. */
	public function token(): ?string {
		return $this->tokens->get();
	}

	/**
	 * @return array{session:?Session,guest:?Session} `guest` is set only for a
	 *         logged-in visitor whose cookie points at an unclaimed guest row
	 */
	public function load( int $user_id ): array {
		if ( isset( $this->loaded[ $user_id ] ) ) {
			return $this->loaded[ $user_id ];
		}
		$now      = $this->clock->db_now();
		$token    = $this->tokens->get();
		$by_token = $token !== null ? $this->repository->find_by_token_hash( self::hash( $token ) ) : null;
		if ( $by_token !== null && $by_token->is_expired( $now ) ) {
			$by_token = null;
		}

		$session = null;
		$guest   = null;
		if ( $user_id <= 0 ) {
			// A row that belongs to a user is not shown after logout.
			$session = $by_token !== null && $by_token->user_id === null ? $by_token : null;
		} elseif ( $by_token !== null && $by_token->user_id === $user_id ) {
			$session = $by_token;
		} else {
			$session = $this->repository->find_by_user( $user_id );
			if ( $session !== null && $session->is_expired( $now ) ) {
				$this->repository->delete( $session->id );
				$session = null;
			}
			$guest = $by_token !== null && $by_token->user_id === null ? $by_token : null;
		}

		if ( $session !== null ) {
			$this->maybe_touch( $session );
		}
		$this->loaded[ $user_id ] = [
			'session' => $session,
			'guest'   => $guest,
		];
		return $this->loaded[ $user_id ];
	}

	/**
	 * Write cart data. Creates the row and the cookie when `$session` is null.
	 * Returns the stored session, or null when another request changed the
	 * row since it was read (the caller reloads and retries).
	 *
	 * @param array<string,mixed> $data
	 */
	public function save( ?Session $session, array $data, int $user_id, ?Session $absorbed = null ): ?Session {
		$now     = $this->clock->db_now();
		$expires = $this->expiry( $user_id );
		$owner   = $user_id > 0 ? $user_id : null;

		if ( $session === null && $absorbed !== null ) {
			// First cart touch after login with no user cart yet: claim the guest row.
			if ( ! $this->repository->update( $absorbed->id, $absorbed->version, $data, $expires, $now, $owner ) ) {
				return $this->conflict( $user_id );
			}
			$this->tokens->set( (string) $this->tokens->get(), $this->clock_ts( $expires ) );
			return $this->remember( $user_id, $absorbed->with_data( $data, $expires, $owner ) );
		}

		if ( $session === null ) {
			$token = self::new_token();
			$hash  = self::hash( $token );
			$id    = $this->repository->insert( $hash, $owner, $data, $expires, $now );
			if ( $id === 0 ) {
				// Another request created the user's row first.
				return $this->conflict( $user_id );
			}
			$this->tokens->set( $token, $this->clock_ts( $expires ) );
			return $this->remember( $user_id, new Session( $id, $hash, $owner, $data, 1, $expires, $now, $now ) );
		}

		if ( ! $this->repository->update( $session->id, $session->version, $data, $expires, $now ) ) {
			return $this->conflict( $user_id );
		}
		$token_hash = null;
		if ( $absorbed !== null ) {
			// The guest row goes once its lines are in the user row; the user
			// row takes its token so this browser finds the user cart with one
			// lookup from now on.
			$this->repository->delete( $absorbed->id );
			$token_hash = $absorbed->token_hash;
			$this->repository->set_token_hash( $session->id, $token_hash );
		}
		$stored = new Session( $session->id, $token_hash ?? $session->token_hash, $session->user_id, $data, $session->version + 1, $expires, $session->created_at, $now );
		$token  = $this->tokens->get();
		if ( $token !== null && self::hash( $token ) === $stored->token_hash ) {
			$this->tokens->set( $token, $this->clock_ts( $expires ) );
		}
		return $this->remember( $user_id, $stored );
	}

	/** The mini-cart count, kept as long as the session. */
	public function publish_count( int $count, int $user_id ): void {
		$this->tokens->set_count( $count, $this->clock_ts( $this->expiry( $user_id ) ) );
	}

	/** Forget what this request loaded, so the next load() reads the table again. */
	public function forget(): void {
		$this->loaded = [];
	}

	public function purge_expired( int $limit = 1000 ): int {
		return $this->repository->purge_expired( $this->clock->db_now(), $limit );
	}

	public function expiry( int $user_id ): string {
		$seconds = $user_id > 0
			? max( 1, Settings::int( 'user_session_days' ) ) * DAY_IN_SECONDS
			: max( 1, Settings::int( 'guest_session_hours' ) ) * HOUR_IN_SECONDS;
		return $this->clock->now()->modify( sprintf( '+%d seconds', $seconds ) )->format( Clock::DB_FORMAT );
	}

	private function maybe_touch( Session $session ): void {
		$next = $this->expiry( (int) $session->user_id );
		if ( $this->clock_ts( $next ) - $this->clock_ts( $session->expires_at ) < self::TOUCH_AFTER ) {
			return;
		}
		$this->repository->touch( $session->id, $next );
		$token = $this->tokens->get();
		if ( $token !== null && $session->token_hash === self::hash( $token ) ) {
			$this->tokens->set( $token, $this->clock_ts( $next ) );
		}
	}

	private function conflict( int $user_id ): null {
		unset( $this->loaded[ $user_id ] );
		return null;
	}

	private function remember( int $user_id, Session $session ): Session {
		$this->loaded[ $user_id ] = [
			'session' => $session,
			'guest'   => null,
		];
		return $session;
	}

	private function clock_ts( string $db_time ): int {
		return ( new \DateTimeImmutable( $db_time, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	/** 32 random bytes, base64url without padding: 43 characters. */
	private static function new_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes a random session token.
	}
}
