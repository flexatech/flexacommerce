<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * No order with that id, number or key.
 *
 * @api
 */
final class OrderNotFound extends CommerceException {
	public const CODE = 'order_not_found';
}
