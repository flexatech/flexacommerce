<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * Not enough stock for the requested quantity. `available` is what can be
 * sold now, or null when the caller did not read it.
 *
 * @api
 */
final class InsufficientStock extends CommerceException {
	public const CODE = 'insufficient_stock';

	public function __construct( public readonly int $variant_id, public readonly ?int $available = null, string $message = '' ) {
		parent::__construct( $message !== '' ? $message : self::describe( $available ) );
	}

	private static function describe( ?int $available ): string {
		if ( $available === null || $available <= 0 ) {
			return __( 'This item is out of stock.', 'flexacommerce' );
		}
		return sprintf(
			/* translators: %d: quantity in stock. */
			_n( 'Only %d item is in stock.', 'Only %d items are in stock.', $available, 'flexacommerce' ),
			$available
		);
	}
}
