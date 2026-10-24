<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Where the session token lives on the client. The cookie in production,
 * memory in tests. The item count is a second, readable value the mini-cart
 * shows without a request (doc 10 Q19); it carries nothing secret.
 */
interface TokenStore {
	public function get(): ?string;

	public function set( string $token, int $expires ): void;

	public function clear(): void;

	/** Publishes the cart's item count; 0 removes it. */
	public function set_count( int $count, int $expires ): void;
}
