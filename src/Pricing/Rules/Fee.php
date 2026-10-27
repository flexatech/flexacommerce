<?php

declare(strict_types=1);

namespace Flexa\Commerce\Pricing\Rules;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/** A charge a fee rule adds to the cart (ADR-043), without tax. */
final class Fee {
	public function __construct(
		public readonly int $rule_id,
		public readonly string $name,
		public readonly Money $amount,
	) {}
}
