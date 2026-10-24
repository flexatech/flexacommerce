<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Pricing;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The unit price of a variant at one moment. `active` is what the customer
 * pays after the `flexacommerce.price.unit` filter; `regular` is the list
 * price, shown struck through when `active` is lower.
 *
 * @api
 */
final class PriceResolution {
	public function __construct(
		public readonly Money $regular,
		public readonly Money $active,
		public readonly bool $on_sale,
	) {}

	/** True when the customer pays less than the list price, for any reason. */
	public function is_reduced(): bool {
		return $this->active->amount < $this->regular->amount;
	}

	/** @return array{regular:int,active:int,on_sale:bool,currency:string} */
	public function to_array(): array {
		return [
			'regular'  => $this->regular->amount,
			'active'   => $this->active->amount,
			'on_sale'  => $this->is_reduced(),
			'currency' => $this->active->currency,
		];
	}
}
