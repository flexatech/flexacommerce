<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Orders\OrderView;

defined( 'ABSPATH' ) || exit;

/**
 * A row of fxc_orders with its items and addresses. Search results carry no
 * items (`items_loaded` false).
 */
final class Order {
	/** @param list<OrderItem> $items */
	public function __construct(
		public readonly int $id,
		public readonly string $order_number,
		public readonly string $order_key,
		public readonly string $status,
		public readonly string $payment_status,
		public readonly bool $needs_attention,
		public readonly string $currency,
		public readonly int $currency_exponent,
		public readonly int $subtotal,
		public readonly int $discount_total,
		public readonly int $shipping_total,
		public readonly int $fee_total,
		public readonly int $tax_total,
		public readonly int $total,
		public readonly int $amount_paid,
		public readonly int $amount_refunded,
		public readonly ?int $customer_id,
		public readonly string $customer_email,
		public readonly string $customer_name,
		public readonly string $customer_ip,
		public readonly string $customer_note,
		public readonly string $payment_method,
		public readonly string $payment_method_title,
		public readonly ?string $checkout_token,
		public readonly string $created_via,
		public readonly string $locale,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly ?string $paid_at,
		public readonly ?string $completed_at,
		public readonly ?string $cancelled_at,
		public readonly ?string $anonymized_at,
		public readonly array $items = [],
		public readonly ?AddressView $billing = null,
		public readonly ?AddressView $shipping = null,
		public readonly bool $items_loaded = false,
		public readonly bool $prices_include_tax = false,
	) {}

	/**
	 * @param array<string,mixed>          $row
	 * @param list<OrderItem>              $items
	 * @param array<string,AddressView>    $addresses by type
	 */
	public static function from_row( array $row, ?array $items = null, array $addresses = [] ): self {
		$nullable = static fn ( string $key ): ?string => isset( $row[ $key ] ) && $row[ $key ] !== '' ? (string) $row[ $key ] : null;
		return new self(
			id: (int) $row['id'],
			order_number: (string) $row['order_number'],
			order_key: (string) $row['order_key'],
			status: (string) $row['status'],
			payment_status: (string) $row['payment_status'],
			needs_attention: (bool) (int) $row['needs_attention'],
			currency: (string) $row['currency'],
			currency_exponent: (int) $row['currency_exponent'],
			subtotal: (int) $row['subtotal'],
			discount_total: (int) $row['discount_total'],
			shipping_total: (int) $row['shipping_total'],
			fee_total: (int) $row['fee_total'],
			tax_total: (int) $row['tax_total'],
			total: (int) $row['total'],
			amount_paid: (int) $row['amount_paid'],
			amount_refunded: (int) $row['amount_refunded'],
			customer_id: isset( $row['customer_id'] ) ? (int) $row['customer_id'] : null,
			customer_email: (string) $row['customer_email'],
			customer_name: (string) $row['customer_name'],
			customer_ip: (string) $row['customer_ip'],
			customer_note: (string) ( $row['customer_note'] ?? '' ),
			payment_method: (string) $row['payment_method'],
			payment_method_title: (string) $row['payment_method_title'],
			checkout_token: $nullable( 'checkout_token' ),
			created_via: (string) $row['created_via'],
			locale: (string) $row['locale'],
			created_at: (string) $row['created_at'],
			updated_at: (string) $row['updated_at'],
			paid_at: $nullable( 'paid_at' ),
			completed_at: $nullable( 'completed_at' ),
			cancelled_at: $nullable( 'cancelled_at' ),
			anonymized_at: $nullable( 'anonymized_at' ),
			items: $items ?? [],
			billing: $addresses['billing'] ?? null,
			shipping: $addresses['shipping'] ?? null,
			items_loaded: $items !== null,
			prices_include_tax: (bool) (int) ( $row['prices_include_tax'] ?? 0 ),
		);
	}

	public function money( int $amount ): Money {
		return new Money( $amount, $this->currency );
	}

	/** @return list<OrderItem> */
	public function items_of_type( string $type ): array {
		return array_values( array_filter( $this->items, static fn ( OrderItem $i ): bool => $i->type === $type ) );
	}

	public function item( int $item_id ): ?OrderItem {
		foreach ( $this->items as $item ) {
			if ( $item->id === $item_id ) {
				return $item;
			}
		}
		return null;
	}

	public function refundable(): int {
		return max( 0, $this->amount_paid - $this->amount_refunded );
	}

	public function balance_due(): int {
		return max( 0, $this->total - $this->amount_paid );
	}

	public function item_count(): int {
		return array_sum( array_map( static fn ( OrderItem $i ): int => $i->quantity, $this->items_of_type( OrderItem::PRODUCT ) ) );
	}

	public function is_paid(): bool {
		return $this->amount_paid >= $this->total && ( $this->amount_paid > 0 || $this->total === 0 );
	}

	public function view(): OrderView {
		return new OrderView(
			id: $this->id,
			number: $this->order_number,
			status: $this->status,
			payment_status: $this->payment_status,
			needs_attention: $this->needs_attention,
			currency: $this->currency,
			subtotal: $this->money( $this->subtotal ),
			discount_total: $this->money( $this->discount_total ),
			shipping_total: $this->money( $this->shipping_total ),
			fee_total: $this->money( $this->fee_total ),
			tax_total: $this->money( $this->tax_total ),
			total: $this->money( $this->total ),
			amount_paid: $this->money( $this->amount_paid ),
			amount_refunded: $this->money( $this->amount_refunded ),
			customer_id: $this->customer_id,
			customer_email: $this->customer_email,
			customer_name: $this->customer_name,
			customer_note: $this->customer_note,
			payment_method: $this->payment_method,
			payment_method_title: $this->payment_method_title,
			created_via: $this->created_via,
			created_at: $this->created_at,
			paid_at: $this->paid_at,
			items: array_map( fn ( OrderItem $i ): \Flexa\Commerce\Contracts\Orders\OrderItemView => $i->view( $this->currency ), $this->items ),
			billing: $this->billing,
			shipping: $this->shipping,
		);
	}
}
