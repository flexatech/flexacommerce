<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Canonical;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Decimal strings without floats (doc 05 §3.1): money to minor units and
 * back, and weight and dimension units to grams and millimetres.
 */
final class Decimal {
	/** Grams per unit, as decimal strings. */
	public const GRAMS = [
		'kg' => '1000',
		'g'  => '1',
		'lb' => '453.59237',
		'oz' => '28.349523125',
	];

	/** Millimetres per unit. */
	public const MILLIMETRES = [
		'mm' => '1',
		'cm' => '10',
		'm'  => '1000',
		'in' => '25.4',
		'yd' => '914.4',
	];

	private const PLACES = 3;

	public static function is_decimal( mixed $value, bool $signed = false ): bool {
		return is_string( $value ) && preg_match( $signed ? '/^-?\d+(\.\d+)?$/' : '/^\d+(\.\d+)?$/', $value ) === 1;
	}

	/** A source number as a plain decimal string, or null when empty or not a number. */
	public static function clean( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			$value = rtrim( rtrim( sprintf( '%.8F', $value ), '0' ), '.' );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( str_starts_with( $value, '.' ) ) {
			$value = '0' . $value;
		}
		if ( ! self::is_decimal( $value, true ) ) {
			return null;
		}
		return self::trim( $value );
	}

	/**
	 * Minor units, rounded half up. `exact` is false when digits were lost.
	 *
	 * @return array{0:int,1:bool}
	 */
	public static function to_minor( string $value, string $currency, int $exponent ): array {
		return [
			Money::from_decimal_string( $value, $currency, $exponent )->amount,
			Money::is_exact_decimal( $value, $exponent ),
		];
	}

	public static function from_minor( int $amount, string $currency, int $exponent ): string {
		return ( new Money( $amount, $currency ) )->to_decimal_string( $exponent );
	}

	/** Store unit to grams or millimetres, three decimals, trailing zeros dropped. */
	public static function to_base( ?string $value, string $factor ): ?string {
		if ( $value === null || ! self::is_decimal( $value ) ) {
			return null;
		}
		return self::trim( self::round( self::multiply( $value, $factor ), self::PLACES ) );
	}

	/**
	 * Grams or millimetres to a store unit. Uses the fewest decimals (up to
	 * seven) that convert back to the same base value, so 453.592 g is 1 lb.
	 */
	public static function from_base( ?string $value, string $factor ): ?string {
		if ( $value === null || ! self::is_decimal( $value ) ) {
			return null;
		}
		$base = self::trim( self::round( $value, self::PLACES ) );
		for ( $places = 0; $places <= 7; $places++ ) {
			$candidate = self::trim( self::divide( $value, $factor, $places ) );
			if ( self::to_base( $candidate, $factor ) === $base ) {
				return $candidate;
			}
		}
		return self::trim( self::divide( $value, $factor, 7 ) );
	}

	/** `1.500` to `1.5`, `2.000` to `2`, `-0` to `0`. */
	public static function trim( string $value ): string {
		$negative = str_starts_with( $value, '-' );
		$value    = ltrim( $value, '-' );
		if ( str_contains( $value, '.' ) ) {
			$value = rtrim( rtrim( $value, '0' ), '.' );
		}
		$value = ltrim( $value, '0' );
		if ( $value === '' || str_starts_with( $value, '.' ) ) {
			$value = '0' . $value;
		}
		return $negative && $value !== '0' ? '-' . $value : $value;
	}

	/** Product of two unsigned decimals, exact. */
	public static function multiply( string $a, string $b ): string {
		[ $a_digits, $a_places ] = self::digits( $a );
		[ $b_digits, $b_places ] = self::digits( $b );
		$product                 = self::multiply_digits( $a_digits, $b_digits );
		return self::place( $product, $a_places + $b_places );
	}

	/** Quotient of two unsigned decimals, rounded half up to `$places`. */
	public static function divide( string $a, string $b, int $places ): string {
		[ $a_digits, $a_places ] = self::digits( $a );
		[ $b_digits, $b_places ] = self::digits( $b );
		$divisor                 = (int) $b_digits;
		if ( $divisor === 0 ) {
			throw new \DivisionByZeroError( 'Unit factor is zero.' );
		}
		// a / b = (a_digits / 10^ap) / (b_digits / 10^bp); one extra digit for rounding.
		$shift     = $places + 1 + $b_places - $a_places;
		$numerator = $shift >= 0 ? $a_digits . str_repeat( '0', $shift ) : substr( $a_digits, 0, max( 1, strlen( $a_digits ) + $shift ) );
		$quotient  = '';
		$remainder = 0;
		foreach ( str_split( $numerator ) as $digit ) {
			$remainder = $remainder * 10 + (int) $digit;
			$quotient .= (string) intdiv( $remainder, $divisor );
			$remainder = $remainder % $divisor;
		}
		$quotient = ltrim( $quotient, '0' );
		return self::round( self::place( $quotient === '' ? '0' : $quotient, $places + 1 ), $places );
	}

	/** Round an unsigned decimal half up. */
	public static function round( string $value, int $places ): string {
		[ $digits, $current ] = self::digits( $value );
		if ( $current <= $places ) {
			return self::place( $digits, $current );
		}
		$drop = $current - $places;
		$keep = substr( $digits, 0, -$drop );
		$next = $digits[ strlen( $digits ) - $drop ];
		$keep = $keep === '' ? '0' : $keep;
		if ( $next >= '5' ) {
			$keep = self::increment( $keep );
		}
		return self::place( $keep, $places );
	}

	/** @return array{0:string,1:int} digits without the point, decimal places */
	private static function digits( string $value ): array {
		$value              = ltrim( $value, '-' );
		[ $int, $fraction ] = array_pad( explode( '.', $value, 2 ), 2, '' );
		$digits             = ltrim( $int . $fraction, '0' );
		return [ $digits === '' ? '0' : $digits, strlen( $fraction ) ];
	}

	private static function place( string $digits, int $places ): string {
		if ( $places === 0 ) {
			return $digits;
		}
		$digits = str_pad( $digits, $places + 1, '0', STR_PAD_LEFT );
		return substr( $digits, 0, -$places ) . '.' . substr( $digits, -$places );
	}

	private static function increment( string $digits ): string {
		$i = strlen( $digits ) - 1;
		while ( $i >= 0 ) {
			if ( $digits[ $i ] !== '9' ) {
				$digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );
				return $digits;
			}
			$digits[ $i ] = '0';
			--$i;
		}
		return '1' . $digits;
	}

	private static function multiply_digits( string $a, string $b ): string {
		$result = array_fill( 0, strlen( $a ) + strlen( $b ), 0 );
		for ( $i = strlen( $a ) - 1; $i >= 0; $i-- ) {
			for ( $j = strlen( $b ) - 1; $j >= 0; $j-- ) {
				$sum                   = $result[ $i + $j + 1 ] + (int) $a[ $i ] * (int) $b[ $j ];
				$result[ $i + $j + 1 ] = $sum % 10;
				$result[ $i + $j ]    += intdiv( $sum, 10 );
			}
		}
		$out = ltrim( implode( '', $result ), '0' );
		return $out === '' ? '0' : $out;
	}
}
