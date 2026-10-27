<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Orders\OrderItemView;

defined( 'ABSPATH' ) || exit;

/** A row of fxc_order_items. Amounts are minor units of the order currency. */
final class OrderItem {
	public const PRODUCT  = 'product';
	public const SHIPPING = 'shipping';
	public const FEE      = 'fee';
	public const DISCOUNT = 'discount';
	public const TAX      = 'tax';

	public const TYPES = [ self::PRODUCT, self::SHIPPING, self::FEE, self::DISCOUNT, self::TAX ];

	/**
	 * @param list<array{label:string,value:string}> $attributes
	 * @param array<string,mixed>                    $meta
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly string $type,
		public readonly int $position,
		public readonly ?int $product_id,
		public readonly ?int $variant_id,
		public readonly string $code,
		public readonly string $name,
		public readonly ?string $sku,
		public readonly array $attributes,
		public readonly int $quantity,
		public readonly int $unit_price,
		public readonly int $subtotal,
		public readonly int $discount_total,
		public readonly int $tax_total,
		public readonly int $total,
		public readonly int $stock_reduced_qty,
		public readonly int $refunded_qty,
		public readonly int $refunded_total,
		public readonly int $restocked_qty,
		public readonly array $meta,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$attributes = json_decode( (string) ( $row['attributes'] ?? '' ), true );
		$meta       = json_decode( (string) ( $row['meta'] ?? '' ), true );
		return new self(
			id: (int) $row['id'],
			order_id: (int) $row['order_id'],
			type: (string) $row['type'],
			position: (int) $row['position'],
			product_id: isset( $row['product_id'] ) ? (int) $row['product_id'] : null,
			variant_id: isset( $row['variant_id'] ) ? (int) $row['variant_id'] : null,
			code: (string) $row['code'],
			name: (string) $row['name'],
			sku: isset( $row['sku'] ) ? (string) $row['sku'] : null,
			attributes: is_array( $attributes ) ? array_values( $attributes ) : [],
			quantity: (int) $row['quantity'],
			unit_price: (int) $row['unit_price'],
			subtotal: (int) $row['subtotal'],
			discount_total: (int) $row['discount_total'],
			tax_total: (int) $row['tax_total'],
			total: (int) $row['total'],
			stock_reduced_qty: (int) $row['stock_reduced_qty'],
			refunded_qty: (int) $row['refunded_qty'],
			refunded_total: (int) $row['refunded_total'],
			restocked_qty: (int) $row['restocked_qty'],
			meta: is_array( $meta ) ? $meta : [],
		);
	}

	/** The stock owner row recorded at checkout, 0 when stock was not managed. */
	public function stock_id(): int {
		return (int) ( $this->meta['stock_id'] ?? 0 );
	}

	/** @return array<string,string> the option choices that track stock (ADR-040), OptionStockRepository key => name */
	public function option_stock(): array {
		$keys = [];
		foreach ( is_array( $this->meta['option_stock'] ?? null ) ? $this->meta['option_stock'] : [] as $key => $label ) {
			$keys[ (string) $key ] = (string) $label;
		}
		return $keys;
	}

	/** How many units of the line's option choices are taken from stock. */
	public function option_held(): int {
		return (int) ( $this->meta['option_held'] ?? 0 );
	}

	public function refundable_qty(): int {
		return max( 0, $this->quantity - $this->refunded_qty );
	}

	/** @return array<string,int> rate key => tax on this line, from checkout */
	public function taxes(): array {
		return self::int_map( $this->meta['taxes'] ?? [] );
	}

	/** @return array<string,int> rate key => tax already refunded on this line */
	public function refunded_taxes(): array {
		return self::int_map( $this->meta['refunded_taxes'] ?? [] );
	}

	/**
	 * Split a gross refund on this line into net and tax per rate, in the
	 * proportions of what is still unrefunded (doc 02 §7.12.10). Refunding
	 * the rest of the line returns exactly the tax left on it, so partial
	 * refunds add up to the line's tax.
	 *
	 * @return array{net:int,taxes:array<string,int>}
	 */
	public function refund_split( int $amount ): array {
		$done = $this->refunded_taxes();
		$left = [];
		foreach ( $this->taxes() as $key => $tax ) {
			$rest = $tax - ( $done[ $key ] ?? 0 );
			if ( $rest > 0 ) {
				$left[ $key ] = $rest;
			}
		}
		$tax_left = array_sum( $left );
		$net_left = max( 0, $this->total - ( $this->refunded_total - array_sum( $done ) ) );
		if ( $amount <= 0 || $tax_left === 0 ) {
			return [
				'net'   => max( 0, $amount ),
				'taxes' => [],
			];
		}
		if ( $amount >= $net_left + $tax_left ) {
			return [
				'net'   => $amount - $tax_left,
				'taxes' => $left,
			];
		}
		$weights = [ '' => $net_left ] + $left;
		$parts   = ( new Money( $amount, 'USD' ) )->allocate( array_values( $weights ) );
		$keys    = array_keys( $weights );
		$taxes   = [];
		foreach ( $parts as $i => $part ) {
			if ( $i > 0 && $part->amount > 0 ) {
				$taxes[ (string) $keys[ $i ] ] = $part->amount;
			}
		}
		return [
			'net'   => $parts[0]->amount,
			'taxes' => $taxes,
		];
	}

	/** @return array<string,int> */
	private static function int_map( mixed $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $key => $amount ) {
			if ( is_numeric( $amount ) ) {
				$out[ (string) $key ] = (int) $amount;
			}
		}
		return $out;
	}

	public function view( string $currency ): OrderItemView {
		return new OrderItemView(
			id: $this->id,
			type: $this->type,
			product_id: $this->product_id,
			variant_id: $this->variant_id,
			code: $this->code,
			name: $this->name,
			sku: $this->sku,
			attributes: $this->attributes,
			quantity: $this->quantity,
			unit_price: new Money( $this->unit_price, $currency ),
			subtotal: new Money( $this->subtotal, $currency ),
			tax_total: new Money( $this->tax_total, $currency ),
			total: new Money( $this->total, $currency ),
			refunded_qty: $this->refunded_qty,
			refunded_total: new Money( $this->refunded_total, $currency ),
		);
	}
}
