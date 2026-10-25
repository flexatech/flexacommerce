<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * An order status change the state machine (or a filter) does not allow.
 *
 * @api
 */
final class InvalidTransition extends CommerceException {
	public const CODE = 'invalid_transition';

	public function __construct( public readonly string $from, public readonly string $to, string $message = '' ) {
		parent::__construct( $message !== '' ? $message : sprintf( 'An order cannot move from "%s" to "%s".', $from, $to ) );
	}
}
