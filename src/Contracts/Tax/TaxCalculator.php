<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the whole calculation (doc 04 §7.1). Comes from the
 * `flexacommerce.tax.calculator` filter. Must be deterministic and free of
 * side effects: it runs on cart reads and twice per checkout.
 *
 * @api
 */
interface TaxCalculator {
	public function calculate( TaxRequest $request ): TaxResult;
}
