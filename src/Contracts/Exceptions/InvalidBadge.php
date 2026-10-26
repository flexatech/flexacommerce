<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * Badge data failed a save rule, or the badge does not exist (`field` is
 * `id`). `field` names the offending input, for example `label`.
 *
 * @api
 */
final class InvalidBadge extends CommerceException {
	public const CODE = 'invalid_badge';

	public function __construct( string $message, public readonly string $field = '' ) {
		parent::__construct( $message );
	}
}
