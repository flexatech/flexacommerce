<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Tax\TaxAmount;
use Flexa\Commerce\Contracts\Tax\TaxLine;
use Flexa\Commerce\Contracts\Tax\TaxLineResult;
use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRate;
use Flexa\Commerce\Contracts\Tax\TaxRateTotal;
use Flexa\Commerce\Contracts\Tax\TaxRequest;
use Flexa\Commerce\Contracts\Tax\TaxResult;
use Flexa\Commerce\Support\IntMath;

defined( 'ABSPATH' ) || exit;

/**
 * The core tax calculation (doc 02 §7.12.4), integer only. Unrounded taxes
 * are held in micro units (a millionth of the minor unit), then rounded half
 * up per line and rate, or once per rate over the request when rounding at
 * subtotal, and split back to the lines with Money::allocate().
 *
 * The formulas follow WooCommerce 11 (`WC_Tax::calc_exclusive_tax()`,
 * `calc_inclusive_tax()`, `get_shipping_tax_rates()`).
 */
final class TaxEngine {
	private const MICRO = 1000000;

	/** @var callable(TaxLocation,string):list<TaxRate> */
	private $rates;

	/**
	 * @param callable(TaxLocation,string):list<TaxRate> $rates matched rates for a location and class
	 * @param list<string>                               $class_order extra class slugs in settings order
	 * @param ?TaxLocation                                $base store base location, for the inclusive-price adjustment
	 */
	public function __construct(
		callable $rates,
		private readonly string $shipping_tax_class = 'inherit',
		private readonly array $class_order = [],
		private readonly bool $round_at_subtotal = false,
		private readonly ?TaxLocation $base = null,
		private readonly bool $adjust_non_base = true,
	) {
		$this->rates = $rates;
	}

	public function calculate( TaxRequest $request ): TaxResult {
		$location = $request->location;
		if ( $location === null ) {
			return TaxResult::none( $request );
		}

		/** @var array<string,list<array{0:TaxRate,1:int}>> $micro line key => [rate, micro tax] */
		$micro = [];
		/** @var array<string,bool> $inclusive line key => tax is taken out of the amount */
		$inclusive = [];
		/** @var array<string,int> $amounts line key => amount the tax is taken from or added to */
		$amounts = [];

		foreach ( $request->lines as $line ) {
			$amounts[ $line->key ]   = $line->amount->amount;
			$inclusive[ $line->key ] = $line->is_inclusive( $request->prices_include_tax );
			$micro[ $line->key ]     = [];
			if ( $line->type === TaxLine::SHIPPING || $line->tax_status !== TaxLine::TAXABLE ) {
				continue;
			}
			$rates = ( $this->rates )( $location, $line->tax_class );
			if ( $inclusive[ $line->key ] && $this->adjust_non_base && $this->base !== null && ! $this->base->equals( $location ) ) {
				$base_rates = ( $this->rates )( $this->base, $line->tax_class );
				if ( self::rate_keys( $base_rates ) !== self::rate_keys( $rates ) ) {
					// WooCommerce keeps the net price and charges the customer's rates on
					// it. The net is not rounded first, or a line can come out a cent off.
					$removed                 = array_sum( array_map( static fn ( array $t ): int => $t[1], self::inclusive( $line->amount->amount, $base_rates ) ) );
					$net                     = IntMath::mul_div( $line->amount->amount, self::MICRO, 1 )[0] - $removed;
					$amounts[ $line->key ]   = self::round( $net );
					$inclusive[ $line->key ] = false;
					$micro[ $line->key ]     = self::exclusive_micro( $net, $rates );
					continue;
				}
			}
			$micro[ $line->key ] = $inclusive[ $line->key ]
				? self::inclusive( $amounts[ $line->key ], $rates )
				: self::exclusive( $amounts[ $line->key ], $rates );
		}

		$shipping_rates = null;
		foreach ( $request->lines as $line ) {
			if ( $line->type !== TaxLine::SHIPPING || $line->tax_status !== TaxLine::TAXABLE ) {
				continue;
			}
			$shipping_rates    ??= $this->shipping_rates( $request, $location );
			$micro[ $line->key ] = self::exclusive( $line->amount->amount, $shipping_rates );
		}

		$rounded = $this->round_at_subtotal ? self::round_per_rate( $micro ) : self::round_per_line( $micro );

		$lines  = [];
		$totals = [];
		$order  = [];
		foreach ( $request->lines as $line ) {
			$taxes = [];
			$sum   = 0;
			foreach ( $rounded[ $line->key ] as [ $rate, $amount ] ) {
				$sum += $amount;
				if ( $request->exempt ) {
					// An inclusive price still loses its tax; the order gets no tax lines (as in WooCommerce).
					continue;
				}
				$key = $rate->key();
				if ( ! isset( $order[ $key ] ) ) {
					$order[ $key ]  = $rate;
					$totals[ $key ] = [ 0, 0 ];
				}
				$totals[ $key ][ $line->type === TaxLine::SHIPPING ? 1 : 0 ] += $amount;
				$taxes[] = new TaxAmount( $rate, new Money( $amount, $request->currency ) );
			}
			$net                 = $inclusive[ $line->key ] ? $amounts[ $line->key ] - $sum : $amounts[ $line->key ];
			$lines[ $line->key ] = new TaxLineResult( $line->key, new Money( $net, $request->currency ), $taxes );
		}

		$rates = [];
		foreach ( $order as $key => $rate ) {
			$rates[] = new TaxRateTotal( $rate, new Money( $totals[ $key ][0], $request->currency ), new Money( $totals[ $key ][1], $request->currency ) );
		}
		return new TaxResult( $request->currency, $lines, $rates, $request->exempt );
	}

	/**
	 * The class shipping is taxed with, and its rates that tax shipping.
	 *
	 * @return list<TaxRate>
	 */
	private function shipping_rates( TaxRequest $request, TaxLocation $location ): array {
		if ( $this->shipping_tax_class !== 'inherit' ) {
			return self::for_shipping( ( $this->rates )( $location, $this->shipping_tax_class ) );
		}
		$classes = [];
		foreach ( $request->lines as $line ) {
			if ( $line->type === TaxLine::PRODUCT && $line->tax_status !== TaxLine::NONE ) {
				$classes[ $line->tax_class ] = true;
			}
		}
		if ( $classes === [] ) {
			return [];
		}
		$matched = [];
		if ( count( $classes ) === 1 ) {
			$matched = self::for_shipping( ( $this->rates )( $location, (string) array_key_first( $classes ) ) );
		} elseif ( ! isset( $classes[''] ) ) {
			foreach ( $this->class_order as $slug ) {
				if ( isset( $classes[ $slug ] ) ) {
					$matched = self::for_shipping( ( $this->rates )( $location, $slug ) );
					break;
				}
			}
		}
		// No rates found (or a Standard item in a mixed cart): the Standard rates.
		return $matched !== [] ? $matched : self::for_shipping( ( $this->rates )( $location, '' ) );
	}

	/**
	 * @param list<TaxRate> $rates
	 * @return list<TaxRate>
	 */
	private static function for_shipping( array $rates ): array {
		return array_values( array_filter( $rates, static fn ( TaxRate $r ): bool => $r->shipping ) );
	}

	/**
	 * Tax added on top of an amount: simple rates on the amount, each
	 * compound rate on the amount plus every tax before it.
	 *
	 * @param list<TaxRate> $rates
	 * @return list<array{0:TaxRate,1:int}> micro units
	 */
	private static function exclusive( int $amount, array $rates ): array {
		return self::exclusive_micro( IntMath::mul_div( $amount, self::MICRO, 1 )[0], $rates );
	}

	/**
	 * The same, on an amount already in micro units.
	 *
	 * @param list<TaxRate> $rates
	 * @return list<array{0:TaxRate,1:int}> micro units
	 */
	private static function exclusive_micro( int $base, array $rates ): array {
		$out = [];
		$sum = 0;
		foreach ( $rates as $rate ) {
			if ( ! $rate->compound ) {
				$tax   = IntMath::mul_div_half_up( $base, $rate->rate, TaxRate::SCALE );
				$out[] = [ $rate, $tax ];
				$sum  += $tax;
			}
		}
		foreach ( $rates as $rate ) {
			if ( $rate->compound ) {
				$tax   = IntMath::mul_div_half_up( $base + $sum, $rate->rate, TaxRate::SCALE );
				$out[] = [ $rate, $tax ];
				$sum  += $tax;
			}
		}
		return $out;
	}

	/**
	 * Tax taken back out of an amount that holds it. Compound rates are
	 * removed first, last one first; simple rates share what is left.
	 *
	 * @param list<TaxRate> $rates
	 * @return list<array{0:TaxRate,1:int}> micro units, in the order of $rates
	 */
	private static function inclusive( int $amount, array $rates ): array {
		$price  = IntMath::mul_div( $amount, self::MICRO, 1 )[0];
		$taxes  = [];
		$simple = 0;
		foreach ( array_reverse( $rates, true ) as $index => $rate ) {
			if ( $rate->compound ) {
				$tax             = IntMath::mul_div_half_up( $price, $rate->rate, TaxRate::SCALE + $rate->rate );
				$taxes[ $index ] = $tax;
				$price          -= $tax;
			} else {
				$simple += $rate->rate;
			}
		}
		foreach ( $rates as $index => $rate ) {
			if ( ! $rate->compound ) {
				$taxes[ $index ] = IntMath::mul_div_half_up( $price, $rate->rate, TaxRate::SCALE + $simple );
			}
		}
		$out = [];
		foreach ( $rates as $index => $rate ) {
			$out[] = [ $rate, $taxes[ $index ] ];
		}
		return $out;
	}

	/**
	 * @param array<string,list<array{0:TaxRate,1:int}>> $micro
	 * @return array<string,list<array{0:TaxRate,1:int}>>
	 */
	private static function round_per_line( array $micro ): array {
		$out = [];
		foreach ( $micro as $key => $taxes ) {
			$out[ $key ] = array_map( static fn ( array $t ): array => [ $t[0], self::round( $t[1] ) ], $taxes );
		}
		return $out;
	}

	/**
	 * Per rate: round the sum of the unrounded line taxes once, then split
	 * it back to the lines in proportion to their unrounded tax.
	 *
	 * @param array<string,list<array{0:TaxRate,1:int}>> $micro
	 * @return array<string,list<array{0:TaxRate,1:int}>>
	 */
	private static function round_per_rate( array $micro ): array {
		$by_rate = [];
		foreach ( $micro as $key => $taxes ) {
			foreach ( $taxes as $index => [ $rate, $amount ] ) {
				$by_rate[ $rate->key() ][] = [ $key, $index, $amount ];
			}
		}
		$out = array_map( static fn ( array $taxes ): array => array_map( static fn ( array $t ): array => [ $t[0], 0 ], $taxes ), $micro );
		foreach ( $by_rate as $parts ) {
			$whole = 0;
			$frac  = 0;
			foreach ( $parts as $part ) {
				$whole += intdiv( $part[2], self::MICRO );
				$frac  += $part[2] % self::MICRO;
			}
			$total = $whole + self::round( $frac );
			if ( $total === 0 ) {
				continue;
			}
			$weights = array_map( static fn ( array $p ): int => $p[2], $parts );
			$shares  = array_sum( $weights ) > 0 ? ( new Money( $total, 'USD' ) )->allocate( $weights ) : [];
			foreach ( $parts as $i => [ $key, $index ] ) {
				$out[ $key ][ $index ][1] = isset( $shares[ $i ] ) ? $shares[ $i ]->amount : 0;
			}
		}
		return $out;
	}

	/** Micro units to minor units, half up. */
	private static function round( int $micro ): int {
		return intdiv( $micro, self::MICRO ) + ( $micro % self::MICRO >= self::MICRO / 2 ? 1 : 0 );
	}

	/**
	 * @param list<TaxRate> $rates
	 * @return list<string>
	 */
	private static function rate_keys( array $rates ): array {
		return array_map( static fn ( TaxRate $r ): string => $r->key() . ':' . $r->rate, $rates );
	}
}
