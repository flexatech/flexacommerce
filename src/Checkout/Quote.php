<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;
use Flexa\Commerce\Contracts\Tax\TaxResult;
use Flexa\Commerce\GiftCards\AppliedGiftCard;

defined( 'ABSPATH' ) || exit;

/**
 * Totals computed on the server for one cart, destination and rate. The
 * checkout page shows one and the submit computes another; they must agree.
 * `items_net` is the product lines without tax and before coupons; with
 * prices entered without tax it equals the cart subtotal. `discount` is what
 * the coupons take off it, without tax; `taxes` are on what is left, and
 * `undiscounted` is the same calculation before coupons, null without a
 * discount. `tax_failure` names the tax add-on that failed when the rate
 * table was used instead. `taxes` is null while taxes are off.
 * `gift_cards` pay part of `total` (ADR-069); `due()` is what is left for
 * the payment method.
 */
final class Quote {
	/**
	 * @param list<ShippingRate>    $rates
	 * @param list<AppliedGiftCard> $gift_cards
	 */
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
		public readonly ?Money $discount = null,
		public readonly ?TaxResult $undiscounted = null,
		public readonly array $gift_cards = [],
	) {}

	/** @param list<AppliedGiftCard> $gift_cards */
	public function with_gift_cards( array $gift_cards ): self {
		return new self( $this->state, $this->package, $this->needs_shipping, $this->rates, $this->rate, $this->shipping, $this->tax, $this->total, $this->taxes, $this->items_net, $this->prices_include_tax, $this->tax_failure, $this->discount, $this->undiscounted, $gift_cards );
	}

	/** What the gift cards pay together. */
	public function gift_card_total(): Money {
		$paid = 0;
		foreach ( $this->gift_cards as $card ) {
			$paid += $card->amount;
		}
		return new Money( $paid, $this->total->currency );
	}

	/** The total less the gift cards: what the payment method charges. */
	public function due(): Money {
		return $this->total->subtract( $this->gift_card_total() );
	}

	public function discount(): Money {
		return $this->discount ?? $this->items_net->multiply( 0 );
	}

	/** Whether the customer must give a shipping address for the chosen rate. */
	public function needs_address(): bool {
		return $this->needs_shipping && ( $this->rate === null || $this->rate->needs_address );
	}
}
