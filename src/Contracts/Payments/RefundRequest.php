<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/** @api */
final class RefundRequest {
	public function __construct(
		public readonly int $refund_transaction_id,
		public readonly string $payment_reference,
		public readonly Money $amount,
		public readonly string $reason,
		public readonly string $idempotency_key,
	) {}
}
