<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Contracts\Exceptions\OrderTotalsMismatch;
use Flexa\Commerce\Contracts\Orders\AddressView;

defined( 'ABSPATH' ) || exit;

/** Input of OrderService::create(). Totals are checked before anything is written. */
final class NewOrder {
	/**
	 * @param list<NewOrderItem>   $items
	 * @param array<string,string> $meta      written to fxc_order_meta (extra checkout fields)
	 * @param ?string              $attention set needs_attention with this history note (tax fallback)
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $currency,
		public readonly int $currency_exponent,
		public readonly array $items,
		public readonly int $subtotal,
		public readonly int $shipping_total,
		public readonly int $total,
		public readonly string $customer_email,
		public readonly ?int $customer_id = null,
		public readonly ?AddressView $billing = null,
		public readonly ?AddressView $shipping = null,
		public readonly string $customer_note = '',
		public readonly string $customer_ip = '',
		public readonly string $payment_method = '',
		public readonly string $payment_method_title = '',
		public readonly ?string $checkout_token = null,
		public readonly string $created_via = 'checkout',
		public readonly string $locale = '',
		public readonly int $discount_total = 0,
		public readonly int $fee_total = 0,
		public readonly int $tax_total = 0,
		public readonly array $meta = [],
		public readonly bool $prices_include_tax = false,
		public readonly ?string $attention = null,
	) {}

	public function customer_name(): string {
		return $this->billing?->name() ?? '';
	}

	/** @throws OrderTotalsMismatch */
	public function assert_totals(): void {
		$expected = $this->subtotal - $this->discount_total + $this->shipping_total + $this->fee_total + $this->tax_total;
		if ( $expected !== $this->total ) {
			throw new OrderTotalsMismatch( sprintf( 'Order total %d does not equal its parts (%d).', $this->total, $expected ) );
		}
		$lines     = 0;
		$shipping  = 0;
		$taxed     = 0;
		$tax       = 0;
		$taken     = 0;
		$coupons   = 0;
		$has_lines = false;
		foreach ( $this->items as $item ) {
			if ( $item->type === OrderItem::DISCOUNT ) {
				$coupons  += $item->total;
				$has_lines = true;
				continue;
			}
			if ( $item->type === OrderItem::PRODUCT ) {
				$lines += $item->subtotal;
				$taken += $item->discount_total;
				// Inclusive prices: the net line is the gross minus its tax, so it is not unit price x quantity.
				// A graduated line (ADR-042) shows its average, rounded: off by at most half a minor unit per unit.
				$unit_ok = $this->prices_include_tax ? $item->subtotal >= 0 : 2 * abs( $item->subtotal - (int) ( $item->meta['fee'] ?? 0 ) - $item->unit_price * $item->quantity ) <= $item->quantity;
				if ( ! $unit_ok || $item->total !== $item->subtotal - $item->discount_total ) {
					throw new OrderTotalsMismatch( sprintf( 'Item "%s" does not add up.', $item->name ) );
				}
			} elseif ( $item->type === OrderItem::SHIPPING ) {
				$shipping += $item->total;
			} elseif ( $item->type === OrderItem::TAX ) {
				$tax += $item->total;
				continue;
			}
			$taxed += $item->tax_total;
			if ( $item->tax_total < 0 || array_sum( (array) ( $item->meta['taxes'] ?? [] ) ) !== $item->tax_total ) {
				throw new OrderTotalsMismatch( sprintf( 'The tax split of item "%s" does not add up.', $item->name ) );
			}
		}
		if ( $lines !== $this->subtotal || $shipping !== $this->shipping_total ) {
			throw new OrderTotalsMismatch( 'Order items do not add up to the subtotal or shipping total.' );
		}
		if ( $taken !== $this->discount_total || ( $has_lines && $coupons !== $this->discount_total ) ) {
			throw new OrderTotalsMismatch( sprintf( 'Order discount %d does not equal the item discounts (%d) or the coupon lines (%d).', $this->discount_total, $taken, $coupons ) );
		}
		if ( $tax !== $this->tax_total || $taxed !== $this->tax_total ) {
			throw new OrderTotalsMismatch( sprintf( 'Order tax %d does not equal its tax lines (%d) or the item taxes (%d).', $this->tax_total, $tax, $taxed ) );
		}
		if ( $this->total < 0 ) {
			throw new OrderTotalsMismatch( 'An order total cannot be negative.' );
		}
	}
}
