<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Orders;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable read model of an order for extensions (doc 04 §5.3). Dates are
 * UTC in `Y-m-d H:i:s`.
 *
 * @api
 */
final class OrderView {
	/** @param list<OrderItemView> $items */
	public function __construct(
		public readonly int $id,
		public readonly string $number,
		public readonly string $status,
		public readonly string $payment_status,
		public readonly bool $needs_attention,
		public readonly string $currency,
		public readonly Money $subtotal,
		public readonly Money $discount_total,
		public readonly Money $shipping_total,
		public readonly Money $fee_total,
		public readonly Money $tax_total,
		public readonly Money $total,
		public readonly Money $amount_paid,
		public readonly Money $amount_refunded,
		public readonly ?int $customer_id,
		public readonly string $customer_email,
		public readonly string $customer_name,
		public readonly string $customer_note,
		public readonly string $payment_method,
		public readonly string $payment_method_title,
		public readonly string $created_via,
		public readonly string $created_at,
		public readonly ?string $paid_at,
		public readonly array $items,
		public readonly ?AddressView $billing,
		public readonly ?AddressView $shipping,
	) {}

	/** @return list<OrderItemView> */
	public function items_of_type( string $type ): array {
		return array_values( array_filter( $this->items, static fn ( OrderItemView $i ): bool => $i->type === $type ) );
	}

	/** Paid minus refunded: what a further refund may not exceed. */
	public function refundable(): Money {
		return $this->amount_paid->subtract( $this->amount_refunded );
	}
}
