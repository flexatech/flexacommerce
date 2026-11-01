<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * A payment method the provider keeps for later charges (ADR-074):
 * the provider's customer, the method's id there, and what the shopper
 * sees, like "Visa ending 4242". Never card numbers.
 */
final class SavedMethod {
	public function __construct(
		public readonly string $customer,
		public readonly string $token,
		public readonly string $label,
	) {}
}
