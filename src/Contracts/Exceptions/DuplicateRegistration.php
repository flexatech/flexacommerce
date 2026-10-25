<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * A gateway or shipping method id registered twice, or a reserved id used by an extension.
 *
 * @api
 */
final class DuplicateRegistration extends CommerceException {
	public const CODE = 'duplicate_registration';
}
