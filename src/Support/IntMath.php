<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Integer arithmetic for tax: `a × b / d` without a float and without
 * overflow in the product. When the product does not fit in an int it is
 * held in 16-bit limbs, so only a result outside the int range fails.
 */
final class IntMath {
	private const LIMB_BITS = 16;
	private const LIMB_MASK = 0xFFFF;

	/** Largest divisor the limb path accepts: remainder × 2^16 must fit in an int. */
	public const MAX_DIVISOR = 70368744177664; // 2^46.

	/**
	 * round( a × b / d ), half away from zero.
	 *
	 * @throws \InvalidArgumentException when d is not positive
	 * @throws \OverflowException when the result does not fit in an int
	 */
	public static function mul_div_half_up( int $a, int $b, int $d ): int {
		$negative  = ( $a < 0 ) !== ( $b < 0 ) && $a !== 0 && $b !== 0;
		[ $q, $r ] = self::mul_div( self::abs( $a ), self::abs( $b ), $d );
		if ( $r >= $d - $r ) {
			if ( $q === PHP_INT_MAX ) {
				throw new \OverflowException( 'Result is outside the integer range.' );
			}
			++$q;
		}
		return $negative ? -$q : $q;
	}

	/**
	 * floor( a × b / d ) and the remainder, for non-negative a and b.
	 *
	 * @return array{0:int,1:int}
	 * @throws \InvalidArgumentException when an operand is negative or d is not positive
	 * @throws \OverflowException when the result does not fit in an int
	 */
	public static function mul_div( int $a, int $b, int $d ): array {
		if ( $d <= 0 || $a < 0 || $b < 0 ) {
			throw new \InvalidArgumentException( 'mul_div needs non-negative factors and a positive divisor.' );
		}
		if ( $a === 0 || $b === 0 ) {
			return [ 0, 0 ];
		}
		if ( $a <= intdiv( PHP_INT_MAX, $b ) ) {
			$product = $a * $b;
			return [ intdiv( $product, $d ), $product % $d ];
		}
		if ( $d > self::MAX_DIVISOR ) {
			throw new \OverflowException( 'Divisor too large for an exact product.' );
		}

		$x       = self::limbs( $a );
		$y       = self::limbs( $b );
		$product = array_fill( 0, count( $x ) + count( $y ) + 1, 0 );
		foreach ( $x as $i => $xi ) {
			$carry = 0;
			foreach ( $y as $j => $yj ) {
				$t                  = $product[ $i + $j ] + $xi * $yj + $carry;
				$product[ $i + $j ] = $t & self::LIMB_MASK;
				$carry              = $t >> self::LIMB_BITS;
			}
			for ( $k = $i + count( $y ); $carry > 0; ++$k ) {
				$t             = $product[ $k ] + $carry;
				$product[ $k ] = $t & self::LIMB_MASK;
				$carry         = $t >> self::LIMB_BITS;
			}
		}

		$rest   = 0;
		$result = 0;
		for ( $k = count( $product ) - 1; $k >= 0; --$k ) {
			$current = ( $rest << self::LIMB_BITS ) + $product[ $k ];
			$digit   = intdiv( $current, $d );
			$rest    = $current % $d;
			if ( $result > ( PHP_INT_MAX - $digit ) >> self::LIMB_BITS ) {
				throw new \OverflowException( 'Result is outside the integer range.' );
			}
			$result = ( $result << self::LIMB_BITS ) + $digit;
		}
		return [ $result, $rest ];
	}

	private static function abs( int $value ): int {
		if ( $value === PHP_INT_MIN ) {
			throw new \OverflowException( 'Result is outside the integer range.' );
		}
		return abs( $value );
	}

	/** @return list<int> little-endian 16-bit limbs */
	private static function limbs( int $value ): array {
		$out = [];
		while ( $value > 0 ) {
			$out[] = $value & self::LIMB_MASK;
			$value = $value >> self::LIMB_BITS;
		}
		return $out;
	}
}
