<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * An item to insert. Product items name their stock owner row (`stock_id`,
 * 0 when stock is not managed) so OrderService can deduct and later restore
 * without the catalog.
 */
final class NewOrderItem {
	/**
	 * @param list<array{label:string,value:string}> $attributes
	 * @param array<string,mixed>                    $meta       item meta; `taxes` is rate key => tax on the line
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $name,
		public readonly int $quantity,
		public readonly int $unit_price,
		public readonly int $subtotal,
		public readonly int $total,
		public readonly ?int $product_id = null,
		public readonly ?int $variant_id = null,
		public readonly int $stock_id = 0,
		public readonly string $code = '',
		public readonly ?string $sku = null,
		public readonly array $attributes = [],
		public readonly int $discount_total = 0,
		public readonly int $tax_total = 0,
		public readonly array $meta = [],
	) {
		if ( ! in_array( $type, OrderItem::TYPES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown order item type "%s".', $type ) );
		}
	}

	/**
	 * With prices entered including tax, `$unit_price` is the shop price and
	 * `$net` the line amount without tax (doc 03 §4.13). `$discount` is the
	 * coupons' share of the line without tax; the taxes are on what is left.
	 * `$fee` is what the product options charge once for the line, on top
	 * of unit price x quantity (ADR-029); it is kept in the meta.
	 *
	 * @param list<array{label:string,value:string}> $attributes
	 * @param array<string,int>                      $taxes        rate key => tax on the line
	 * @param array<string,string>                   $option_stock option choices that track stock, key => name (ADR-040)
	 */
	public static function product( int $product_id, int $variant_id, int $stock_id, string $name, ?string $sku, array $attributes, int $quantity, int $unit_price, ?int $net = null, array $taxes = [], int $discount = 0, int $fee = 0, array $option_stock = [] ): self {
		$subtotal = $net ?? $unit_price * $quantity + $fee;
		$meta     = self::taxes_meta( $taxes ) + ( $fee > 0 ? [ 'fee' => $fee ] : [] ) + ( $option_stock !== [] ? [ 'option_stock' => $option_stock ] : [] );
		return new self( OrderItem::PRODUCT, $name, $quantity, $unit_price, $subtotal, $subtotal - $discount, $product_id, $variant_id, $stock_id, '', $sku, $attributes, $discount, array_sum( $taxes ), $meta );
	}

	/**
	 * One line per coupon, a record of what it took off (doc 02 §7.13). The
	 * amount is already inside the product lines, without tax.
	 *
	 * @param array<string,mixed> $meta {coupon_id, type, amount, discount_tax}
	 */
	public static function discount( string $code, int $total, array $meta ): self {
		return new self( OrderItem::DISCOUNT, $code, 1, $total, $total, $total, code: $code, meta: $meta );
	}

	/** @param array<string,int> $taxes rate key => tax on the shipping cost */
	public static function shipping( string $rate_id, string $label, int $cost, array $taxes = [] ): self {
		return new self( OrderItem::SHIPPING, $label, 1, $cost, $cost, $cost, code: $rate_id, tax_total: array_sum( $taxes ), meta: self::taxes_meta( $taxes ) );
	}

	/**
	 * A charge from a fee rule (ADR-043), without tax.
	 *
	 * @param array<string,mixed> $meta {rule_id}
	 */
	public static function fee( string $name, int $total, array $meta = [] ): self {
		return new self( OrderItem::FEE, $name, 1, $total, $total, $total, meta: $meta );
	}

	/**
	 * One line per tax rate, a snapshot of the rate (doc 02 §7.12.10).
	 *
	 * @param array<string,mixed> $meta {rate_id, rate, compound, items_tax, shipping_tax}
	 */
	public static function tax( string $code, string $label, int $total, array $meta ): self {
		return new self( OrderItem::TAX, $label, 1, $total, $total, $total, code: $code, meta: $meta );
	}

	/**
	 * @param array<string,int> $taxes
	 * @return array<string,mixed>
	 */
	private static function taxes_meta( array $taxes ): array {
		return $taxes === [] ? [] : [ 'taxes' => $taxes ];
	}
}
