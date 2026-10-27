<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Pricing;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The unit price of a variant at one moment. `active` is what the customer
 * pays after the `flexacommerce.price.unit` filter; `regular` is the list
 * price, shown struck through when `active` is lower. `line` is the exact
 * total for the quantity priced when its units cost different amounts (a
 * graduated rule, ADR-042); `active` is then the average, rounded. Null
 * means `active` times the quantity.
 *
 * @api
 */
final class PriceResolution {
	public function __construct(
		public readonly Money $regular,
		public readonly Money $active,
		public readonly bool $on_sale,
		public readonly ?Money $line = null,
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
