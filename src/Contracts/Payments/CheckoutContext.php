<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * What a gateway may look at when it decides whether it is offered and
 * validates its own input (doc 04 §3). Read-only; built by checkout from the
 * cart and the submitted addresses.
 *
 * @api
 */
final class CheckoutContext {
	public function __construct(
		public readonly Money $total,
		public readonly string $billing_country,
		public readonly string $shipping_country,
		public readonly bool $needs_shipping,
		public readonly ?string $shipping_rate_id,
		public readonly ?int $customer_id,
		public readonly int $item_count,
	) {}

	public function currency(): string {
		return $this->total->currency;
	}

	/** The shipping method type of the chosen rate (`flat_rate` for `flat_rate:3`). */
	public function shipping_method(): ?string {
		return $this->shipping_rate_id === null ? null : explode( ':', $this->shipping_rate_id )[0];
	}
}
