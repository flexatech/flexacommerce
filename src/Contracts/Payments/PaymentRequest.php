<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Orders\OrderView;

defined( 'ABSPATH' ) || exit;

/**
 * One payment attempt. `idempotency_key` is stable for the attempt; send it
 * to the provider so a retried request never charges twice.
 *
 * @api
 */
final class PaymentRequest {
	/** @param array<string,mixed> $gateway_input */
	public function __construct(
		public readonly int $transaction_id,
		public readonly string $idempotency_key,
		public readonly OrderView $order,
		public readonly Money $amount,
		public readonly string $return_url,
		public readonly string $cancel_url,
		public readonly string $webhook_url,
		public readonly array $gateway_input = [],
	) {}
}
