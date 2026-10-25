<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/** The tax one rate charged over a request: items and fees, and shipping. @api */
final class TaxRateTotal {
	public function __construct(
		public readonly TaxRate $rate,
		public readonly Money $items,
		public readonly Money $shipping,
	) {}

	public function total(): Money {
		return $this->items->add( $this->shipping );
	}
}
