<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\Stripe;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe counts some currencies in other units than ISO 4217: ISK in
 * hundredths, MGA and UGX in whole units, IQD in hundredths. The store
 * keeps ISO minor units, so amounts are converted on the way in and out.
 */
final class StripeAmount {
	private const ZERO = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];

	private const THREE = [ 'BHD', 'JOD', 'KWD', 'OMR', 'TND' ];

	public static function exponent( string $currency ): int {
		$currency = strtoupper( $currency );
		return match ( true ) {
			in_array( $currency, self::ZERO, true )  => 0,
			in_array( $currency, self::THREE, true ) => 3,
			default                                  => 2,
		};
	}

	/** The amount in Stripe's units, or null when it does not convert exactly. */
	public static function to_stripe( Money $money ): ?int {
		$shift = self::exponent( $money->currency ) - Currency::exponent( $money->currency );
		if ( $shift >= 0 ) {
			return $money->amount * ( 10 ** $shift );
		}
		$unit = 10 ** -$shift;
		return $money->amount % $unit === 0 ? intdiv( $money->amount, $unit ) : null;
	}

	public static function from_stripe( int $amount, string $currency ): Money {
		$currency = strtoupper( $currency );
		$shift    = Currency::exponent( $currency ) - self::exponent( $currency );
		$amount   = $shift >= 0 ? $amount * ( 10 ** $shift ) : intdiv( $amount, 10 ** -$shift );
		return new Money( $amount, $currency );
	}
}
