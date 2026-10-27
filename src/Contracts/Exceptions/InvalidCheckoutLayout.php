<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * A checkout layout failed a save rule (doc 11). `field` names the
 * offending input as `section.index.key`, for example `billing.3.label`.
 *
 * @api
 */
final class InvalidCheckoutLayout extends CommerceException {
	public const CODE = 'invalid_checkout_layout';

	public function __construct( string $message, public readonly string $field = '' ) {
		parent::__construct( $message );
	}
}
