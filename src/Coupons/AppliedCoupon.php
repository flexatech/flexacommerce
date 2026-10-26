<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

defined( 'ABSPATH' ) || exit;

/** One coupon's share of a cart discount, per cart line key, in prices as entered. */
final class AppliedCoupon {
	/** @param array<string,int> $lines cart line key => discount */
	public function __construct(
		public readonly Coupon $coupon,
		public readonly array $lines,
	) {}

	public function total(): int {
		return array_sum( $this->lines );
	}
}
