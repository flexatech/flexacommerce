<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders\Csv;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderQuery;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Support\Csv;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the orders the admin list shows as CSV lines (ADR-070), a page at
 * a time. The `orders` layout is one row per order with its addresses and
 * totals; the `items` layout is one row per product line. Amounts are
 * decimals in the order's currency, dates are store-local.
 */
final class OrderCsvExporter {
	public const PER_PAGE = 100;

	public const ORDERS = 'orders';
	public const ITEMS  = 'items';

	public const LAYOUTS = [ self::ORDERS, self::ITEMS ];

	private const ADDRESS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];

	public function __construct(
		private readonly OrderCsvRepository $repository,
		private readonly string $gift_card_gateway,
	) {}

	/** @return array{until:int,total:int} */
	public function scope( OrderQuery $query ): array {
		return $this->repository->scope( $query );
	}

	/** @return array<string,string> column key => header */
	public static function columns( string $layout ): array {
		$order = [
			'number'   => __( 'Order number', 'flexacommerce' ),
			'date'     => __( 'Date', 'flexacommerce' ),
			'status'   => __( 'Status', 'flexacommerce' ),
			'email'    => __( 'Email', 'flexacommerce' ),
			'currency' => __( 'Currency', 'flexacommerce' ),
		];
		if ( $layout === self::ITEMS ) {
			return $order + [
				'product'        => __( 'Product', 'flexacommerce' ),
				'sku'            => __( 'SKU', 'flexacommerce' ),
				'details'        => __( 'Details', 'flexacommerce' ),
				'quantity'       => __( 'Quantity', 'flexacommerce' ),
				'unit_price'     => __( 'Unit price', 'flexacommerce' ),
				'line_subtotal'  => __( 'Line subtotal', 'flexacommerce' ),
				'line_discount'  => __( 'Line discount', 'flexacommerce' ),
				'line_tax'       => __( 'Line tax', 'flexacommerce' ),
				'line_total'     => __( 'Line total', 'flexacommerce' ),
				'refunded_qty'   => __( 'Refunded quantity', 'flexacommerce' ),
				'refunded_total' => __( 'Refunded amount', 'flexacommerce' ),
			];
		}
		return $order + [
			'payment_status'      => __( 'Payment status', 'flexacommerce' ),
			'customer'            => __( 'Customer', 'flexacommerce' ),
			'phone'               => __( 'Phone', 'flexacommerce' ),
			'billing_first_name'  => __( 'Billing first name', 'flexacommerce' ),
			'billing_last_name'   => __( 'Billing last name', 'flexacommerce' ),
			'billing_company'     => __( 'Billing company', 'flexacommerce' ),
			'billing_address_1'   => __( 'Billing address 1', 'flexacommerce' ),
			'billing_address_2'   => __( 'Billing address 2', 'flexacommerce' ),
			'billing_city'        => __( 'Billing city', 'flexacommerce' ),
			'billing_state'       => __( 'Billing state', 'flexacommerce' ),
			'billing_postcode'    => __( 'Billing postcode', 'flexacommerce' ),
			'billing_country'     => __( 'Billing country', 'flexacommerce' ),
			'shipping_first_name' => __( 'Shipping first name', 'flexacommerce' ),
			'shipping_last_name'  => __( 'Shipping last name', 'flexacommerce' ),
			'shipping_company'    => __( 'Shipping company', 'flexacommerce' ),
			'shipping_address_1'  => __( 'Shipping address 1', 'flexacommerce' ),
			'shipping_address_2'  => __( 'Shipping address 2', 'flexacommerce' ),
			'shipping_city'       => __( 'Shipping city', 'flexacommerce' ),
			'shipping_state'      => __( 'Shipping state', 'flexacommerce' ),
			'shipping_postcode'   => __( 'Shipping postcode', 'flexacommerce' ),
			'shipping_country'    => __( 'Shipping country', 'flexacommerce' ),
			'payment_method'      => __( 'Payment method', 'flexacommerce' ),
			'shipping_method'     => __( 'Shipping method', 'flexacommerce' ),
			'coupons'             => __( 'Coupons', 'flexacommerce' ),
			'items'               => __( 'Items', 'flexacommerce' ),
			'subtotal'            => __( 'Subtotal', 'flexacommerce' ),
			'discount'            => __( 'Discount', 'flexacommerce' ),
			'shipping'            => __( 'Shipping', 'flexacommerce' ),
			'fees'                => __( 'Fees', 'flexacommerce' ),
			'tax'                 => __( 'Tax', 'flexacommerce' ),
			'total'               => __( 'Total', 'flexacommerce' ),
			'gift_card'           => __( 'Paid by gift card', 'flexacommerce' ),
			'paid'                => __( 'Paid', 'flexacommerce' ),
			'refunded'            => __( 'Refunded', 'flexacommerce' ),
			'tracking'            => __( 'Tracking numbers', 'flexacommerce' ),
			'note'                => __( 'Customer note', 'flexacommerce' ),
			'fields'              => __( 'Checkout fields', 'flexacommerce' ),
			'paid_at'             => __( 'Date paid', 'flexacommerce' ),
			'completed_at'        => __( 'Date completed', 'flexacommerce' ),
		];
	}

	public static function header( string $layout ): string {
		return Csv::line( array_map( [ Csv::class, 'escape' ], array_values( self::columns( $layout ) ) ) );
	}

	/**
	 * The orders after `$after`, up to `$until`, as CSV lines.
	 *
	 * @return array{lines:string,rows:int,orders:int,last:int}
	 */
	public function page( OrderQuery $query, string $layout, int $after, int $until, int $per_page = self::PER_PAGE ): array {
		$orders  = $this->repository->page( $query, $after, $until, $per_page );
		$ids     = array_map( static fn ( Order $order ): int => $order->id, $orders );
		$columns = array_keys( self::columns( $layout ) );
		$extra   = $layout === self::ORDERS && $ids !== [] ? [
			'fields'    => $this->repository->fields( $ids ),
			'gift_card' => $this->repository->net_by_gateway( $ids, $this->gift_card_gateway ),
			'tracking'  => $this->repository->tracking( $ids ),
		] : [];

		$lines = '';
		$rows  = 0;
		foreach ( $orders as $order ) {
			$records = $layout === self::ITEMS ? $this->item_records( $order ) : [ $this->order_record( $order, $extra ) ];
			foreach ( $records as $record ) {
				$cells = [];
				foreach ( $columns as $key ) {
					$cells[] = self::cell( $record[ $key ] ?? '' );
				}
				$lines .= Csv::line( $cells );
				++$rows;
			}
		}
		return [
			'lines'  => $lines,
			'rows'   => $rows,
			'orders' => count( $orders ),
			'last'   => $ids === [] ? $until : (int) end( $ids ),
		];
	}

	/** @return array<string,string> */
	private static function base( Order $order ): array {
		return [
			'number'   => $order->order_number,
			'date'     => self::date( $order->created_at ),
			'status'   => OrderStatus::label( $order->status ),
			'email'    => $order->customer_email,
			'currency' => $order->currency,
		];
	}

	/**
	 * @param array{fields?:array<int,list<array{key:string,label:string,value:string,email:bool}>>,gift_card?:array<int,int>,tracking?:array<int,list<string>>} $extra
	 * @return array<string,string>
	 */
	private function order_record( Order $order, array $extra ): array {
		$record = self::base( $order ) + [
			'payment_status'  => OrderStatus::payment_label( $order->payment_status ),
			'customer'        => $order->customer_name,
			'phone'           => $order->billing->phone ?? '',
			'payment_method'  => $order->payment_method_title,
			'shipping_method' => implode( ', ', array_map( static fn ( OrderItem $item ): string => $item->name, $order->items_of_type( OrderItem::SHIPPING ) ) ),
			'coupons'         => implode( ', ', array_filter( array_map( static fn ( OrderItem $item ): string => $item->code, $order->items_of_type( OrderItem::DISCOUNT ) ) ) ),
			'items'           => (string) $order->item_count(),
			'subtotal'        => self::money( $order, $order->subtotal ),
			'discount'        => self::money( $order, $order->discount_total ),
			'shipping'        => self::money( $order, $order->shipping_total ),
			'fees'            => self::money( $order, $order->fee_total ),
			'tax'             => self::money( $order, $order->tax_total ),
			'total'           => self::money( $order, $order->total ),
			'gift_card'       => self::money( $order, $extra['gift_card'][ $order->id ] ?? 0 ),
			'paid'            => self::money( $order, $order->amount_paid ),
			'refunded'        => self::money( $order, $order->amount_refunded ),
			'tracking'        => implode( ', ', $extra['tracking'][ $order->id ] ?? [] ),
			'note'            => $order->customer_note,
			'fields'          => implode( '; ', array_map( static fn ( array $answer ): string => $answer['label'] . ': ' . $answer['value'], $extra['fields'][ $order->id ] ?? [] ) ),
			'paid_at'         => self::date( $order->paid_at ),
			'completed_at'    => self::date( $order->completed_at ),
		];
		foreach ( [
			'billing'  => $order->billing,
			'shipping' => $order->shipping,
		] as $type => $address ) {
			foreach ( self::ADDRESS as $field ) {
				$record[ $type . '_' . $field ] = $address instanceof AddressView ? (string) $address->{$field} : '';
			}
		}
		return $record;
	}

	/** @return list<array<string,string>> one per product line */
	private function item_records( Order $order ): array {
		$base = self::base( $order );
		$out  = [];
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			$out[] = $base + [
				'product'        => $item->name,
				'sku'            => (string) $item->sku,
				'details'        => implode( ', ', array_map( static fn ( array $attribute ): string => $attribute['label'] . ': ' . $attribute['value'], $item->attributes ) ),
				'quantity'       => (string) $item->quantity,
				'unit_price'     => self::money( $order, $item->unit_price ),
				'line_subtotal'  => self::money( $order, $item->subtotal ),
				'line_discount'  => self::money( $order, $item->discount_total ),
				'line_tax'       => self::money( $order, $item->tax_total ),
				'line_total'     => self::money( $order, $item->total ),
				'refunded_qty'   => (string) $item->refunded_qty,
				'refunded_total' => self::money( $order, $item->refunded_total ),
			];
		}
		return $out;
	}

	/** A plain number is written as it is, so a spreadsheet adds it up; text gets the formula guard. */
	private static function cell( string $value ): string {
		return preg_match( '/^-?\d+(\.\d+)?$/', $value ) === 1 ? $value : Csv::escape( $value );
	}

	private static function money( Order $order, int $amount ): string {
		return ( new Money( $amount, $order->currency ) )->to_decimal_string( $order->currency_exponent );
	}

	/** A stored UTC time as the store's local time. */
	private static function date( ?string $utc ): string {
		if ( $utc === null || $utc === '' ) {
			return '';
		}
		$time = strtotime( $utc . ' UTC' );
		return $time === false ? '' : (string) wp_date( 'Y-m-d H:i:s', $time );
	}
}
