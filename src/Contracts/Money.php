<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

use Flexa\Commerce\Contracts\Exceptions\CurrencyMismatch;
use Flexa\Commerce\Contracts\Exceptions\InvalidMoney;

defined( 'ABSPATH' ) || exit;

/**
 * An amount in the currency's minor unit (cents, yen, fils). Immutable, no
 * floats anywhere (doc 03 §2.1). The exponent is not stored on the object:
 * callers that convert to or from decimals pass it, normally through
 * MoneyFactory, which reads it from the currency table.
 *
 * @api
 */
final class Money implements \JsonSerializable {
	/** 18 digits always fit in a signed 64-bit integer. */
	private const MAX_DIGITS = 18;

	public function __construct(
		public readonly int $amount,
		public readonly string $currency,
	) {
		if ( preg_match( '/^[A-Z]{3}$/', $currency ) !== 1 ) {
			throw new InvalidMoney( sprintf( 'Invalid currency code "%s".', $currency ) );
		}
	}

	public static function zero( string $currency ): self {
		return new self( 0, $currency );
	}

	/**
	 * Parse a plain decimal string ("19.99", "-0.5", "20") using string
	 * arithmetic. Extra fraction digits are rounded half up (away from zero).
	 * No thousands separators: locale parsing belongs to the input layer.
	 */
	public static function from_decimal_string( string $value, string $currency, int $exponent ): self {
		[ $negative, $int_part, $fraction ] = self::split_decimal( $value );
		self::assert_exponent( $exponent );

		$kept   = str_pad( substr( $fraction, 0, $exponent ), $exponent, '0' );
		$digits = ltrim( $int_part . $kept, '0' );
		if ( strlen( $digits ) > self::MAX_DIGITS ) {
			throw new InvalidMoney( sprintf( 'Amount "%s" is too large.', $value ) );
		}

		$amount = (int) ( $digits === '' ? '0' : $digits );
		$next   = $fraction[ $exponent ] ?? '0';
		if ( $next >= '5' ) {
			++$amount;
		}

		return new self( $negative && $amount !== 0 ? -$amount : $amount, $currency );
	}

	/**
	 * True when the string has no more fraction digits than the exponent
	 * allows (ignoring trailing zeros), so parsing it loses nothing.
	 */
	public static function is_exact_decimal( string $value, int $exponent ): bool {
		[ , , $fraction ] = self::split_decimal( $value );
		return strlen( rtrim( $fraction, '0' ) ) <= $exponent;
	}

	public function to_decimal_string( int $exponent ): string {
		self::assert_exponent( $exponent );
		$digits = ltrim( (string) $this->amount, '-' );
		$sign   = $this->amount < 0 ? '-' : '';
		if ( $exponent === 0 ) {
			return $sign . $digits;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	public function add( self $other ): self {
		$this->assert_same_currency( $other );
		return new self( self::checked( $this->amount + $other->amount ), $this->currency );
	}

	public function subtract( self $other ): self {
		$this->assert_same_currency( $other );
		return new self( self::checked( $this->amount - $other->amount ), $this->currency );
	}

	public function multiply( int $factor ): self {
		return new self( self::checked( $this->amount * $factor ), $this->currency );
	}

	public function negate(): self {
		return new self( self::checked( -$this->amount ), $this->currency );
	}

	public function is_zero(): bool {
		return $this->amount === 0;
	}

	public function is_negative(): bool {
		return $this->amount < 0;
	}

	public function equals( self $other ): bool {
		return $this->currency === $other->currency && $this->amount === $other->amount;
	}

	/** @return int -1, 0 or 1 */
	public function compare( self $other ): int {
		$this->assert_same_currency( $other );
		return $this->amount <=> $other->amount;
	}

	/**
	 * Split this amount by ratios with the largest remainder method. The parts
	 * always sum to exactly this amount; leftover units go to the parts with
	 * the largest remainders, earlier parts first on ties.
	 *
	 * @param list<int> $ratios non-negative, at least one above zero
	 * @return list<self>
	 */
	public function allocate( array $ratios ): array {
		if ( $ratios === [] ) {
			throw new InvalidMoney( 'Allocation needs at least one ratio.' );
		}
		$sum = 0;
		foreach ( $ratios as $ratio ) {
			if ( $ratio < 0 ) {
				throw new InvalidMoney( 'Allocation ratios cannot be negative.' );
			}
			$sum = self::checked( $sum + $ratio );
		}
		if ( $sum === 0 ) {
			throw new InvalidMoney( 'Allocation ratios cannot all be zero.' );
		}

		$total = abs( $this->amount );
		$whole = intdiv( $total, $sum );
		$rest  = $total % $sum;

		$parts      = [];
		$remainders = [];
		$allocated  = 0;
		foreach ( $ratios as $index => $ratio ) {
			// total * ratio / sum, split so the product stays within int range.
			$scaled               = self::checked( $rest * $ratio );
			$share                = self::checked( $whole * $ratio ) + intdiv( $scaled, $sum );
			$parts[ $index ]      = $share;
			$remainders[ $index ] = $scaled % $sum;
			$allocated           += $share;
		}

		$leftover = $total - $allocated;
		if ( $leftover > 0 ) {
			$order = array_keys( $remainders );
			usort(
				$order,
				static fn ( int $a, int $b ): int => [ $remainders[ $b ], $a ] <=> [ $remainders[ $a ], $b ]
			);
			foreach ( array_slice( $order, 0, $leftover ) as $index ) {
				++$parts[ $index ];
			}
		}

		$sign = $this->amount < 0 ? -1 : 1;
		return array_map( fn ( int $part ): self => new self( $sign * $part, $this->currency ), $parts );
	}

	/** @return array{amount:int,currency:string} */
	public function jsonSerialize(): array {
		return [
			'amount'   => $this->amount,
			'currency' => $this->currency,
		];
	}

	/** @return array{0:bool,1:string,2:string} sign, integer digits, fraction digits */
	private static function split_decimal( string $value ): array {
		$value = trim( $value );
		if ( preg_match( '/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $m ) !== 1 || ( $m[2] === '' && ( $m[3] ?? '' ) === '' ) ) {
			throw new InvalidMoney( sprintf( 'Invalid decimal amount "%s".', $value ) );
		}
		return [ $m[1] === '-', $m[2], $m[3] ?? '' ];
	}

	private static function assert_exponent( int $exponent ): void {
		if ( $exponent < 0 || $exponent > 6 ) {
			throw new InvalidMoney( sprintf( 'Unsupported currency exponent %d.', $exponent ) );
		}
	}

	/** Integer overflow in PHP silently turns into a float; refuse that. */
	private static function checked( int|float $value ): int {
		if ( ! is_int( $value ) ) {
			throw new InvalidMoney( 'Amount is outside the supported range.' );
		}
		return $value;
	}

	private function assert_same_currency( self $other ): void {
		if ( $this->currency !== $other->currency ) {
			throw CurrencyMismatch::between( $this->currency, $other->currency );
		}
	}
}
