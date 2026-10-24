<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * A cart request that cannot be applied: bad quantity, unknown line, a
 * selection that does not match the variation. `field` names the input.
 *
 * @api
 */
final class InvalidCartRequest extends CommerceException {
	public const CODE = 'invalid_cart_request';

	public function __construct( string $message, public readonly string $field = '', string $code = '' ) {
		parent::__construct( $message );
		$this->reason = $code;
	}

	public readonly string $reason;
}
