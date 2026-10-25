<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * The tax add-on failed and does not allow the rate table as a fallback
 * (`TaxRateProvider::fallback_allowed()` is false), so no order is placed.
 *
 * @api
 */
final class TaxUnavailable extends CommerceException {
	public const CODE = 'tax_unavailable';
}
