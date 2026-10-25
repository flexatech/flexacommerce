<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Cart;

use Flexa\Commerce\Cart\SessionNonce;
use Flexa\Commerce\Tests\Unit\TestCase;

final class SessionNonceTest extends TestCase {
	private int $now = 1_800_000_000;

	private function nonce(): SessionNonce {
		return new SessionNonce( 'secret', fn (): int => $this->now );
	}

	public function test_binds_user_login_and_session(): void {
		$n     = $this->nonce();
		$token = $n->create( 5, 'login-a', 'session-a' );
		self::assertTrue( $n->verify( $token, 5, 'login-a', 'session-a' ) );
		self::assertFalse( $n->verify( $token, 6, 'login-a', 'session-a' ) );
		self::assertFalse( $n->verify( $token, 5, 'login-b', 'session-a' ) );
		self::assertFalse( $n->verify( $token, 5, 'login-a', 'session-b' ) );
		self::assertFalse( $n->verify( '', 5, 'login-a', 'session-a' ) );
	}

	public function test_lives_for_two_ticks(): void {
		$n          = $this->nonce();
		$token      = $n->create( 0, '', 'session-a' );
		$this->now += 43200;
		self::assertTrue( $n->verify( $token, 0, '', 'session-a' ) );
		$this->now += 43200;
		self::assertFalse( $n->verify( $token, 0, '', 'session-a' ) );
	}

	public function test_guest_nonce_is_shared_and_still_passes_with_a_session(): void {
		$n      = $this->nonce();
		$public = $n->create( 0, '', null );
		self::assertSame( $public, ( new SessionNonce( 'secret', fn (): int => $this->now ) )->create( 0, '', null ) );
		self::assertTrue( $n->verify( $public, 0, '', null ) );
		// A cached page prints the public nonce; the visitor may have a cart by now.
		self::assertTrue( $n->verify( $public, 0, '', 'session-a' ) );
	}

	public function test_a_user_cannot_use_the_public_nonce(): void {
		$n = $this->nonce();
		self::assertFalse( $n->verify( $n->create( 0, '', null ), 5, 'login-a', 'session-a' ) );
	}

	public function test_another_guests_session_nonce_fails(): void {
		$n = $this->nonce();
		self::assertFalse( $n->verify( $n->create( 0, '', 'session-b' ), 0, '', 'session-a' ) );
	}
}
