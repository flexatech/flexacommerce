<?php

declare(strict_types=1);

namespace Flexa\Commerce\Wishlist;

defined( 'ABSPATH' ) || exit;

/**
 * One row of fxc_wishlists (ADR-066). A customer has one list; a guest's
 * list is found by the hash of the `fxc_wishlist` cookie and moves to the
 * account at the first signed-in visit. The share key opens a read-only
 * copy for anyone with the link.
 */
final class Wishlist {
	public function __construct(
		public readonly int $id,
		public readonly ?int $user_id,
		public readonly string $share_key,
		public readonly string $updated_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			user_id: isset( $row['user_id'] ) && (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null,
			share_key: (string) ( $row['share_key'] ?? '' ),
			updated_at: (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
