<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/** @api */
final class InvalidAttribute extends CommerceException {
	public const CODE = 'invalid_attribute';
}
