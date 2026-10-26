<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * Option set data failed a save rule, or the set does not exist (`field` is
 * `id`). `field` names the offending input, for example `fields`; for a
 * problem inside one field, `index` is its place in the list.
 *
 * @api
 */
final class InvalidOptionSet extends CommerceException {
	public const CODE = 'invalid_option_set';

	public function __construct( string $message, public readonly string $field = '', public readonly int $index = -1 ) {
		parent::__construct( $message );
	}
}
