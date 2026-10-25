<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * A webhook whose signature does not verify. Core answers 401 and changes nothing.
 *
 * @api
 */
final class InvalidSignature extends CommerceException {
	public const CODE = 'invalid_signature';
}
