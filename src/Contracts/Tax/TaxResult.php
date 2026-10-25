<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Tax per line and per rate. Core checks that the line keys match the
 * request, the currency matches and the sums add up before it uses a
 * result from an add-on (doc 04 §7.1).
 *
 * @api
 */
final class TaxResult {
	/**
	 * @param array<string,TaxLineResult> $lines by request line key
	 * @param list<TaxRateTotal>          $rates in rate order
	 */
	public function __construct(
		public readonly string $currency,
		public readonly array $lines,
		public readonly array $rates,
		public readonly bool $exempt = false,
	) {}

	public static function none( TaxRequest $request ): self {
		$lines = [];
		foreach ( $request->lines as $line ) {
			$lines[ $line->key ] = new TaxLineResult( $line->key, $line->amount, [] );
		}
		return new self( $request->currency, $lines, [], $request->exempt );
	}

	public function total(): Money {
		$sum = Money::zero( $this->currency );
		foreach ( $this->rates as $rate ) {
			$sum = $sum->add( $rate->total() );
		}
		return $sum;
	}

	public function line( string $key ): ?TaxLineResult {
		return $this->lines[ $key ] ?? null;
	}

	/** Tax of every line that is not shipping. */
	public function items_total(): Money {
		$sum = Money::zero( $this->currency );
		foreach ( $this->rates as $rate ) {
			$sum = $sum->add( $rate->items );
		}
		return $sum;
	}

	public function shipping_total(): Money {
		$sum = Money::zero( $this->currency );
		foreach ( $this->rates as $rate ) {
			$sum = $sum->add( $rate->shipping );
		}
		return $sum;
	}
}
