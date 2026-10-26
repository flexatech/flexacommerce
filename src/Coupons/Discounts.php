<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The coupons of a resolved cart (doc 02 §7.13). Amounts are in prices as
 * entered: with prices including tax a discount includes tax. A code that
 * does not apply stays in the cart with its message in `rejected`, so the
 * shopper sees why and checkout refuses until it is removed.
 */
final class Discounts {
	/**
	 * @param list<AppliedCoupon>  $applied  in the order the codes were added
	 * @param array<string,string> $rejected normalized code => message
	 */
	public function __construct(
		public readonly string $currency,
		public readonly array $applied = [],
		public readonly array $rejected = [],
	) {}

	/** Discount on one cart line, all coupons together. */
	public function line( string $key ): int {
		$sum = 0;
		foreach ( $this->applied as $applied ) {
			$sum += $applied->lines[ $key ] ?? 0;
		}
		return $sum;
	}

	public function total(): Money {
		$sum = 0;
		foreach ( $this->applied as $applied ) {
			$sum += $applied->total();
		}
		return new Money( $sum, $this->currency );
	}

	public function has_discount(): bool {
		return $this->total()->amount > 0;
	}

	public function find( string $code ): ?AppliedCoupon {
		foreach ( $this->applied as $applied ) {
			if ( $applied->coupon->matches( $code ) ) {
				return $applied;
			}
		}
		return null;
	}
}
