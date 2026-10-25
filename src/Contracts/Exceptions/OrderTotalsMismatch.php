<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * Order totals that do not add up: total must equal subtotal - discount + shipping + fee + tax.
 *
 * @api
 */
final class OrderTotalsMismatch extends CommerceException {
	public const CODE = 'order_totals_mismatch';
}
