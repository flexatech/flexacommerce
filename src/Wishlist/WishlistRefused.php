<?php

declare(strict_types=1);

namespace Flexa\Commerce\Wishlist;

defined( 'ABSPATH' ) || exit;

/**
 * A wishlist change that was not made (ADR-066). `reason` is one of off,
 * product, full or limit; the form and the store route turn it into a
 * message.
 */
final class WishlistRefused extends \RuntimeException {
	public readonly string $reason;

	public function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}

	/** The message shown for a reason. */
	public static function text( string $reason ): string {
		return match ( $reason ) {
			'off' => __( 'The wishlist is turned off.', 'flexacommerce' ),
			'full' => __( 'Your wishlist is full. Remove a product to add another.', 'flexacommerce' ),
			'limit' => __( 'Too many new wishlists from your network. Try again later.', 'flexacommerce' ),
			default => __( 'This product cannot be saved to a wishlist.', 'flexacommerce' ),
		};
	}
}
