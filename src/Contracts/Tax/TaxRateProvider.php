<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the rates for a location while core does the math, rounding,
 * storage and refunds (doc 04 §7.1). Comes from the
 * `flexacommerce.tax.rate_provider` filter.
 *
 * @api
 */
interface TaxRateProvider {
	/** @return list<TaxRate> simple rates first, then compound, each in priority order */
	public function rates( TaxLocation $location, string $tax_class ): array;

	/** False: checkout refuses the order when this provider fails, instead of using the rate table. */
	public function fallback_allowed(): bool;
}
