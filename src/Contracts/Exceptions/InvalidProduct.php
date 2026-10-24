<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * Product data failed a save rule. `field` points at the offending input,
 * for example `base.sale_price` or `variations.3.attributes`.
 *
 * @api
 */
final class InvalidProduct extends CommerceException {
	public const CODE = 'invalid_product';

	public function __construct( string $message, public readonly string $field = '' ) {
		parent::__construct( $message );
	}
}
