<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Tax\TaxResult;

defined( 'ABSPATH' ) || exit;

/**
 * A calculation and, when an add-on failed and the rate table was used
 * instead, what failed. Checkout turns `failure` into `needs_attention` and
 * an order note (doc 02 §7.12.7).
 */
final class TaxOutcome {
	public function __construct(
		public readonly TaxResult $result,
		public readonly ?string $failure = null,
	) {}

	public function fell_back(): bool {
		return $this->failure !== null;
	}
}
