<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

use Flexa\Commerce\Catalog\SubscriptionPlan;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Orders\OrderItem;

defined( 'ABSPATH' ) || exit;

/**
 * What every renewal order of a subscription holds (ADR-073): the lines,
 * shipping and taxes priced at checkout without the trial, the sign-up
 * fee, coupons, fee rules and credit. Renewals copy it, so the price the
 * shopper agreed to stays the price until the merchant changes it.
 */
final class Snapshot {
	/** @param list<NewOrderItem> $items */
	public function __construct(
		public readonly SubscriptionPlan $plan,
		public readonly array $items,
		public readonly int $subtotal,
		public readonly int $shipping_total,
		public readonly int $tax_total,
		public readonly int $total,
		public readonly bool $prices_include_tax = false,
		public readonly int $discount_total = 0,
		public readonly int $fee_total = 0,
	) {}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'plan'               => $this->plan->to_array(),
			'items'              => array_map( static fn ( NewOrderItem $item ): array => $item->to_array(), $this->items ),
			'subtotal'           => $this->subtotal,
			'discount_total'     => $this->discount_total,
			'shipping_total'     => $this->shipping_total,
			'fee_total'          => $this->fee_total,
			'tax_total'          => $this->tax_total,
			'total'              => $this->total,
			'prices_include_tax' => $this->prices_include_tax,
		];
	}

	public function to_json(): string {
		return (string) wp_json_encode( $this->to_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function from_json( ?string $json ): ?self {
		if ( $json === null || $json === '' ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$plan = SubscriptionPlan::from_array( $data['plan'] ?? null );
		if ( $plan === null ) {
			return null;
		}
		$items = [];
		foreach ( (array) ( $data['items'] ?? [] ) as $row ) {
			$item = is_array( $row ) ? NewOrderItem::from_array( $row ) : null;
			if ( $item !== null ) {
				$items[] = $item;
			}
		}
		return new self(
			$plan,
			$items,
			(int) ( $data['subtotal'] ?? 0 ),
			(int) ( $data['shipping_total'] ?? 0 ),
			(int) ( $data['tax_total'] ?? 0 ),
			(int) ( $data['total'] ?? 0 ),
			(bool) ( $data['prices_include_tax'] ?? false ),
			(int) ( $data['discount_total'] ?? 0 ),
			(int) ( $data['fee_total'] ?? 0 ),
		);
	}

	/** @return list<NewOrderItem> */
	public function products(): array {
		return array_values( array_filter( $this->items, static fn ( NewOrderItem $item ): bool => $item->type === OrderItem::PRODUCT ) );
	}

	/** "Coffee beans × 2, Filter papers": what the subscription is for. */
	public function title(): string {
		$names = [];
		foreach ( $this->products() as $item ) {
			if ( isset( $item->meta['bundled_by'] ) ) {
				continue;
			}
			$names[] = $item->quantity > 1 ? sprintf( '%1$s × %2$d', $item->name, $item->quantity ) : $item->name;
		}
		return implode( ', ', $names );
	}
}
