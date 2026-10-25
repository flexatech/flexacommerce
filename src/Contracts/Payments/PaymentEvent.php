<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A fact reported by the provider, from a webhook or a signed return.
 * `transaction_ref` is either our transaction id (as a string) or the
 * provider's reference for it. `event_id` must be unique per gateway; core
 * drops repeats.
 *
 * @api
 */
final class PaymentEvent {
	public const TYPES = [ 'succeeded', 'failed', 'authorized', 'cancelled', 'refund_succeeded', 'refund_failed' ];

	/** @param array<string,mixed> $raw already redacted */
	public function __construct(
		public readonly string $event_id,
		public readonly string $type,
		public readonly string $transaction_ref,
		public readonly Money $amount,
		public readonly string $occurred_at = '',
		public readonly array $raw = [],
		public readonly string $failure_code = '',
		public readonly string $failure_message = '',
	) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown payment event type "%s".', $type ) );
		}
		if ( $event_id === '' || strlen( $event_id ) > 191 ) {
			throw new \InvalidArgumentException( 'A payment event needs an id of 1 to 191 characters.' );
		}
	}
}
