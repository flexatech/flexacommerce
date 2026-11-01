<?php

declare(strict_types=1);

namespace Flexa\Commerce\GiftCards;

defined( 'ABSPATH' ) || exit;

/** A gift card in the cart and the part of the total it pays (ADR-069). `balance` is before the payment. */
final class AppliedGiftCard {
	public function __construct(
		public readonly int $id,
		public readonly string $code,
		public readonly int $amount,
		public readonly int $balance,
	) {}

	public function masked(): string {
		return GiftCard::mask( $this->code );
	}
}
