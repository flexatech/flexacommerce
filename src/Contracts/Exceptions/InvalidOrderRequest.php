<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * A request on an order that is malformed or not allowed in its state
 * (paying a cancelled order, a note that is empty). `field` names the input.
 *
 * @api
 */
final class InvalidOrderRequest extends CommerceException {
	public const CODE = 'invalid_order_request';

	public function __construct( string $message, public readonly string $field = '' ) {
		parent::__construct( $message );
	}
}
