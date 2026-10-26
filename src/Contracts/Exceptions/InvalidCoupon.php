<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon data failed a save rule, or the coupon does not exist (`field`
 * is `id`). `field` names the offending input, for example `amount`.
 *
 * @api
 */
final class InvalidCoupon extends CommerceException {
	public const CODE = 'invalid_coupon';

	public function __construct( string $message, public readonly string $field = '' ) {
		parent::__construct( $message );
	}
}
