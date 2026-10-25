<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * A checkout submission that cannot become an order. `errors` maps a field
 * name (`billing.postcode`, `payment_method`, `cart`) to a message.
 *
 * @api
 */
final class InvalidCheckout extends CommerceException {
	public const CODE = 'invalid_checkout';

	/** @param array<string,string> $errors */
	public function __construct( public readonly array $errors, string $message = '' ) {
		parent::__construct( $message !== '' ? $message : (string) ( reset( $errors ) ?: 'The checkout could not be completed.' ) );
	}
}
