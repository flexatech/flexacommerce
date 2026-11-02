<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * A plan switch priced for the customer (ADR-077). `credit` is the
 * unused share of the period already paid, `charge` what is paid now.
 * On the same schedule an upgrade pays the price difference for the rest
 * of the period and a downgrade pays nothing; on another schedule a new
 * period starts today, less the credit. `next_payment_at` is the payment
 * day after the switch (UTC).
 */
final class SwitchQuote {
	public function __construct(
		public readonly Snapshot $snapshot,
		public readonly int $credit,
		public readonly int $charge,
		public readonly bool $same_schedule,
		public readonly string $next_payment_at,
	) {}
}
