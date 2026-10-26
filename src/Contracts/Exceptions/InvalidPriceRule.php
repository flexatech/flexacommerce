<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing rule data failed a save rule, or the rule does not exist (`field` is
 * `id`). `field` names the offending input, for example `tiers`.
 *
 * @api
 */
final class InvalidPriceRule extends CommerceException {
	public const CODE = 'invalid_price_rule';

	public function __construct( string $message, public readonly string $field = '' ) {
		parent::__construct( $message );
	}
}
