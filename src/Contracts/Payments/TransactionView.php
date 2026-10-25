<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A row of fxc_transactions as extensions see it. `data` is never included.
 *
 * @api
 */
final class TransactionView {
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly ?int $parent_id,
		public readonly string $type,
		public readonly string $status,
		public readonly string $method,
		public readonly string $gateway,
		public readonly Money $amount,
		public readonly ?string $gateway_reference,
		public readonly string $created_at,
		public readonly ?string $completed_at,
	) {}
}
