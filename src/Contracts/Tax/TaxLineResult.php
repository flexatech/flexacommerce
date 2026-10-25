<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The tax of one request line. `net` is the line amount without tax: the
 * amount itself for exclusive prices, the amount minus the tax taken out of
 * it for inclusive prices.
 *
 * @api
 */
final class TaxLineResult {
	/** @param list<TaxAmount> $taxes */
	public function __construct(
		public readonly string $key,
		public readonly Money $net,
		public readonly array $taxes,
	) {}

	public function total(): Money {
		$sum = Money::zero( $this->net->currency );
		foreach ( $this->taxes as $tax ) {
			$sum = $sum->add( $tax->amount );
		}
		return $sum;
	}

	/** @return array<string,int> rate key => amount, the `meta.taxes` of an order line */
	public function by_rate(): array {
		$out = [];
		foreach ( $this->taxes as $tax ) {
			$out[ $tax->rate->key() ] = ( $out[ $tax->rate->key() ] ?? 0 ) + $tax->amount->amount;
		}
		return $out;
	}
}
