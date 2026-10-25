<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * The cart total changed between the checkout page and the submit; the customer must review it again.
 *
 * @api
 */
final class TotalsChanged extends CommerceException {
	public const CODE = 'totals_changed';
}
