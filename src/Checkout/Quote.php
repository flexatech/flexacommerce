<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;
use Flexa\Commerce\Contracts\Tax\TaxResult;

defined( 'ABSPATH' ) || exit;

/**
 * Totals computed on the server for one cart, destination and rate. The
 * checkout page shows one and the submit computes another; they must agree.
 * `items_net` is the product lines without tax; with prices entered without
 * tax it equals the cart subtotal. `tax_failure` names the tax add-on that
 * failed when the rate table was used instead. `taxes` is null while taxes
 * are off.
 */
final class Quote {
	/** @param list<ShippingRate> $rates */
	public function __construct(
		public readonly CartState $state,
		public readonly ShippingPackage $package,
		public readonly bool $needs_shipping,
		public readonly array $rates,
		public readonly ?ShippingRate $rate,
		public readonly Money $shipping,
		public readonly Money $tax,
		public readonly Money $total,
		public readonly ?TaxResult $taxes,
		public readonly Money $items_net,
		public readonly bool $prices_include_tax = false,
		public readonly ?string $tax_failure = null,
	) {}

	/** Whether the customer must give a shipping address for the chosen rate. */
	public function needs_address(): bool {
		return $this->needs_shipping && ( $this->rate === null || $this->rate->needs_address );
	}
}
