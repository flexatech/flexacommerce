<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * What a registered customer bought before, for rule and coupon
 * conditions (ADR-041). Paid orders count: processing and completed.
 * A guest (user id 0) has no history.
 *
 * @api
 */
interface CustomerHistory {
	public function order_count( int $user_id ): int;

	/** Paid less refunded over all orders in the store currency, in minor units. */
	public function spent( int $user_id ): int;

	/** @param list<int> $product_ids */
	public function bought( int $user_id, array $product_ids ): bool;
}
