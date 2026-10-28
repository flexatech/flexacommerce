<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\PayPal;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal takes amounts as decimal strings, in the currencies it lists.
 * HUF, JPY and TWD go in whole units, though ISO gives HUF and TWD
 * hundredths, so a store amount with cents there cannot be sent.
 */
final class PayPalAmount {
	public const CURRENCIES = [ 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD' ];

	private const WHOLE = [ 'HUF', 'JPY', 'TWD' ];

	public static function supports( string $currency ): bool {
		return in_array( strtoupper( $currency ), self::CURRENCIES, true );
	}

	public static function exponent( string $currency ): int {
		return in_array( strtoupper( $currency ), self::WHOLE, true ) ? 0 : 2;
	}

	/** "12.30" for 1230 cents; null for a currency PayPal does not take or an amount it cannot hold. */
	public static function to_paypal( Money $money ): ?string {
		if ( ! self::supports( $money->currency ) || $money->amount < 0 ) {
			return null;
		}
		$exponent = self::exponent( $money->currency );
		$shift    = $exponent - Currency::exponent( $money->currency );
		if ( $shift >= 0 ) {
			$units = $money->amount * ( 10 ** $shift );
		} else {
			$unit = 10 ** -$shift;
			if ( $money->amount % $unit !== 0 ) {
				return null;
			}
			$units = intdiv( $money->amount, $unit );
		}
		if ( $exponent === 0 ) {
			return (string) $units;
		}
		$scale = 10 ** $exponent;
		return intdiv( $units, $scale ) . '.' . str_pad( (string) ( $units % $scale ), $exponent, '0', STR_PAD_LEFT );
	}

	/** The store's Money for PayPal's `{value, currency_code}`. */
	public static function from_paypal( string $value, string $currency ): Money {
		$currency             = strtoupper( $currency !== '' ? $currency : 'USD' );
		[ $whole, $fraction ] = array_pad( explode( '.', trim( $value ), 2 ), 2, '' );
		$negative             = str_starts_with( $whole, '-' );
		$whole                = ltrim( $whole, '-' );
		$exponent             = Currency::exponent( $currency );
		$fraction             = substr( str_pad( preg_replace( '/\D/', '', $fraction ) ?? '', $exponent, '0' ), 0, $exponent );
		$amount               = (int) ( ( ctype_digit( $whole ) ? $whole : '0' ) . $fraction );
		return new Money( $negative ? -$amount : $amount, $currency );
	}

	/** @return array{currency_code:string,value:string} */
	public static function field( Money $money ): array {
		return [
			'currency_code' => strtoupper( $money->currency ),
			'value'         => (string) self::to_paypal( $money ),
		];
	}
}
