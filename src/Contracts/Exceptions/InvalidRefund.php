<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * A refund that cannot be made: more than was paid, more items than were
 * bought, or a gateway that does not refund.
 *
 * @api
 */
final class InvalidRefund extends CommerceException {
	public const CODE = 'invalid_refund';
}
