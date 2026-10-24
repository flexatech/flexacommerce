<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/** @internal For tests and CLI: keeps the token in memory. */
final class MemoryTokenStore implements TokenStore {
	public int $writes = 0;

	public ?int $count = null;

	public function __construct( private ?string $token = null, public int $expires = 0 ) {}

	public function get(): ?string {
		return $this->token;
	}

	public function set( string $token, int $expires ): void {
		$this->token   = $token;
		$this->expires = $expires;
		++$this->writes;
	}

	public function clear(): void {
		$this->token = null;
		$this->count = null;
		++$this->writes;
	}

	public function set_count( int $count, int $expires ): void {
		unset( $expires );
		$this->count = $count > 0 ? $count : null;
	}
}
