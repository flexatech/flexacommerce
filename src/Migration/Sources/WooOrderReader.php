<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;

defined( 'ABSPATH' ) || exit;

/**
 * Orders from WooCommerce CRUD (doc 05 §5.4). `wc_get_order()` reads the
 * authoritative store, so legacy, HPOS and HPOS with sync need no branch.
 */
final class WooOrderReader {
	/** Order meta covered by the mapping. */
	private const MAPPED_META = [ '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug', '_edit_lock', '_edit_last', '_fxc_source_id', '_fxc_order_number', '_fxc_import_job' ];

	/** Item meta covered by the mapping. */
	private const MAPPED_ITEM_META = [ '_reduced_stock', '_restock_refunded_items', '_refunded_item_id', '_fxc_source_id' ];

	private const PAID_STATUSES = [ 'processing', 'completed', 'refunded' ];

	public function order( int $id ): ?Record {
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || $order->get_type() !== 'shop_order' ) {
			return null;
		}
		$status  = $order->get_status( 'edit' );
		$trashed = $status === 'trash';
		if ( $trashed ) {
			$before = (string) $order->get_meta( '_wp_trash_meta_status', true, 'edit' );
			$status = $before !== '' ? ( str_starts_with( $before, 'wc-' ) ? substr( $before, 3 ) : $before ) : 'pending';
		}
		if ( $status === 'checkout-draft' ) {
			return null;
		}
		$currency = $order->get_currency( 'edit' );
		$money    = static fn ( mixed $value ): array => [
			'amount'   => self::decimal( $value ),
			'currency' => $currency,
		];

		$stock_reduced = (bool) $order->get_order_stock_reduced( 'edit' );
		$items         = [];
		$depends       = [];
		foreach ( $order->get_items( [ 'line_item', 'shipping', 'fee', 'coupon', 'tax' ] ) as $item ) {
			$line = $this->item( $item, $stock_reduced );
			if ( $line === null ) {
				continue;
			}
			if ( isset( $line['product'] ) && is_string( $line['product'] ) ) {
				$depends[] = $line['product'];
			}
			$items[] = $line;
		}

		$transactions = [];
		$paid_at      = Dates::from_wc( $order->get_date_paid( 'edit' ) );
		$total        = self::decimal( $order->get_total( 'edit' ) );
		if ( ( $paid_at !== null || in_array( $status, self::PAID_STATUSES, true ) ) && ! str_starts_with( $total, '-' ) && $total !== '0' ) {
			$reference      = $order->get_transaction_id( 'edit' );
			$transactions[] = [
				'id'           => Ref::make( 'wc', 'transaction', 'payment-' . $id ),
				'type'         => 'payment',
				'status'       => 'succeeded',
				'gateway'      => $order->get_payment_method( 'edit' ),
				'reference'    => $reference !== '' ? $reference : null,
				'amount'       => $money( $total ),
				'completed_at' => $paid_at ?? Dates::from_wc( $order->get_date_created( 'edit' ) ),
			];
		}
		$refunds = $order->get_refunds();
		usort( $refunds, static fn ( \WC_Order_Refund $a, \WC_Order_Refund $b ): int => $a->get_id() <=> $b->get_id() );
		foreach ( $refunds as $refund ) {
			$transactions[] = $this->refund( $refund, $order, $money );
		}

		$history = [];
		foreach ( wc_get_order_notes( [ 'order_id' => $id ] ) as $note ) {
			$history[] = [
				'id'               => (int) $note->id,
				'type'             => 'note',
				'message'          => (string) $note->content,
				'customer_visible' => (bool) $note->customer_note,
				'author'           => (string) $note->added_by,
				'created_at'       => Dates::from_wc( $note->date_created instanceof \DateTimeInterface ? $note->date_created : null ),
			];
		}
		usort( $history, static fn ( array $a, array $b ): int => [ $a['created_at'], $a['id'] ] <=> [ $b['created_at'], $b['id'] ] );
		$history = array_map(
			static function ( array $note ): array {
				unset( $note['id'] );
				return $note;
			},
			$history
		);

		$customer = $order->get_customer_id( 'edit' );
		return new Record(
			Record::ORDER,
			Ref::make( 'wc', Record::ORDER, $id ),
			[
				'number'               => (string) $order->get_order_number(),
				'status'               => $status,
				'currency'             => $currency,
				'prices_include_tax'   => $order->get_prices_include_tax( 'edit' ),
				'created_via'          => $order->get_created_via( 'edit' ),
				'created_at'           => Dates::from_wc( $order->get_date_created( 'edit' ) ),
				'modified_at'          => Dates::from_wc( $order->get_date_modified( 'edit' ) ),
				'paid_at'              => $paid_at,
				'completed_at'         => Dates::from_wc( $order->get_date_completed( 'edit' ) ),
				'trashed'              => $trashed,
				'customer'             => $customer > 0 ? Ref::make( 'wp', 'user', $customer ) : null,
				'customer_ip'          => $order->get_customer_ip_address( 'edit' ),
				'customer_note'        => $order->get_customer_note( 'edit' ),
				'order_key'            => $order->get_order_key( 'edit' ),
				'payment_method'       => $order->get_payment_method( 'edit' ),
				'payment_method_title' => $order->get_payment_method_title( 'edit' ),
				'stock_reduced'        => $stock_reduced,
				'totals'               => [
					'subtotal' => self::decimal( $order->get_subtotal() ),
					'discount' => self::decimal( $order->get_discount_total( 'edit' ) ),
					'shipping' => self::decimal( $order->get_shipping_total( 'edit' ) ),
					'fees'     => self::decimal( $order->get_total_fees() ),
					'tax'      => self::decimal( $order->get_total_tax( 'edit' ) ),
					'total'    => $total,
				],
				'addresses'            => [
					'billing'  => WooStoreReader::address( $order, 'billing' ),
					'shipping' => WooStoreReader::address( $order, 'shipping' ),
				],
				'items'                => $items,
				'transactions'         => $transactions,
				'history'              => $history,
				'extra'                => WooCatalogReader::extra( WooCatalogReader::meta_of( $order ), self::MAPPED_META ),
			],
			array_values( array_unique( $depends ) )
		);
	}

	/** @return array<string,mixed>|null */
	private function item( \WC_Order_Item $item, bool $stock_reduced ): ?array {
		$id   = Ref::make( 'wc', 'order_item', $item->get_id() );
		$meta = WooCatalogReader::meta_of( $item );
		if ( $item instanceof \WC_Order_Item_Product ) {
			$product_id            = $item->get_product_id( 'edit' );
			$variation_id          = $item->get_variation_id( 'edit' );
			$product               = $item->get_product();
			[ $attributes, $meta ] = $this->attributes( $meta, $product );
			$quantity              = $item->get_quantity( 'edit' );
			return [
				'id'            => $id,
				'type'          => 'product',
				'product'       => $product_id > 0 ? Ref::make( 'wc', Record::PRODUCT, $product_id ) : null,
				'variant'       => $variation_id > 0 ? Ref::make( 'wc', 'variant', $variation_id ) : null,
				'name'          => $item->get_name( 'edit' ),
				'sku'           => $product instanceof \WC_Product && $product->get_sku( 'edit' ) !== '' ? $product->get_sku( 'edit' ) : null,
				'attributes'    => $attributes,
				'quantity'      => $quantity,
				'subtotal'      => self::decimal( $item->get_subtotal( 'edit' ) ),
				'subtotal_tax'  => self::decimal( $item->get_subtotal_tax( 'edit' ) ),
				'total'         => self::decimal( $item->get_total( 'edit' ) ),
				'tax'           => self::decimal( $item->get_total_tax( 'edit' ) ),
				'taxes'         => self::taxes( $item->get_taxes( 'edit' ) ),
				'tax_class'     => $item->get_tax_class( 'edit' ),
				'reduced_stock' => $stock_reduced ? (int) $item->get_meta( '_reduced_stock', true, 'edit' ) : 0,
				'restocked'     => (int) $item->get_meta( '_restock_refunded_items', true, 'edit' ),
				'extra'         => WooCatalogReader::extra( $meta, self::MAPPED_ITEM_META ),
			];
		}
		if ( $item instanceof \WC_Order_Item_Shipping ) {
			$instance = $item->get_instance_id( 'edit' );
			return [
				'id'    => $id,
				'type'  => 'shipping',
				'code'  => $item->get_method_id( 'edit' ) . ( $instance !== '' && $instance !== '0' ? ':' . $instance : '' ),
				'name'  => $item->get_name( 'edit' ),
				'total' => self::decimal( $item->get_total( 'edit' ) ),
				'tax'   => self::decimal( $item->get_total_tax( 'edit' ) ),
				'taxes' => self::taxes( $item->get_taxes( 'edit' ) ),
				'extra' => WooCatalogReader::extra( $meta, self::MAPPED_ITEM_META ),
			];
		}
		if ( $item instanceof \WC_Order_Item_Fee ) {
			return [
				'id'         => $id,
				'type'       => 'fee',
				'name'       => $item->get_name( 'edit' ),
				'total'      => self::decimal( $item->get_total( 'edit' ) ),
				'tax'        => self::decimal( $item->get_total_tax( 'edit' ) ),
				'taxes'      => self::taxes( $item->get_taxes( 'edit' ) ),
				'tax_class'  => $item->get_tax_class( 'edit' ),
				'tax_status' => $item->get_tax_status( 'edit' ),
				'extra'      => WooCatalogReader::extra( $meta, self::MAPPED_ITEM_META ),
			];
		}
		if ( $item instanceof \WC_Order_Item_Coupon ) {
			unset( $meta['coupon_data'], $meta['coupon_info'] );
			return [
				'id'    => $id,
				'type'  => 'coupon',
				'code'  => $item->get_code( 'edit' ),
				'total' => self::decimal( $item->get_discount( 'edit' ) ),
				'tax'   => self::decimal( $item->get_discount_tax( 'edit' ) ),
				'extra' => WooCatalogReader::extra( $meta, self::MAPPED_ITEM_META ),
			];
		}
		if ( $item instanceof \WC_Order_Item_Tax ) {
			$rate_id = $item->get_rate_id( 'edit' );
			$percent = $item->get_rate_percent( 'edit' );
			return [
				'id'           => $id,
				'type'         => 'tax',
				'code'         => $item->get_rate_code( 'edit' ),
				'name'         => $item->get_label( 'edit' ),
				'rate'         => $rate_id > 0 ? Ref::make( 'wc', Record::TAX_RATE, $rate_id ) : null,
				'rate_percent' => Decimal::clean( $percent ),
				'compound'     => $item->get_compound( 'edit' ),
				'tax'          => self::decimal( $item->get_tax_total( 'edit' ) ),
				'shipping_tax' => self::decimal( $item->get_shipping_tax_total( 'edit' ) ),
			];
		}
		return null;
	}

	/**
	 * Variation attributes as display pairs; the rest stays item meta.
	 *
	 * @param array<string,list<mixed>> $meta
	 * @return array{0:list<array{label:string,value:string}>,1:array<string,list<mixed>>}
	 */
	private function attributes( array $meta, mixed $product ): array {
		$local = [];
		if ( $product instanceof \WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				foreach ( $parent->get_attributes( 'edit' ) as $attribute ) {
					if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
						$local[ sanitize_title( $attribute->get_name() ) ] = $attribute->get_name();
					}
				}
			}
		}
		$out = [];
		foreach ( $meta as $key => $values ) {
			$value = is_scalar( $values[0] ?? null ) ? (string) $values[0] : null;
			if ( $value === null || count( $values ) !== 1 ) {
				continue;
			}
			if ( str_starts_with( $key, 'pa_' ) && taxonomy_exists( $key ) ) {
				$term  = get_term_by( 'slug', $value, $key );
				$out[] = [
					'label' => wc_attribute_label( $key ),
					'value' => $term instanceof \WP_Term ? $term->name : $value,
				];
				unset( $meta[ $key ] );
			} elseif ( isset( $local[ $key ] ) ) {
				$out[] = [
					'label' => $local[ $key ],
					'value' => $value,
				];
				unset( $meta[ $key ] );
			}
		}
		return [ $out, $meta ];
	}

	/**
	 * @param callable(mixed):array{amount:string,currency:string} $money
	 * @return array<string,mixed>
	 */
	private function refund( \WC_Order_Refund $refund, \WC_Order $order, callable $money ): array {
		$lines = [];
		foreach ( $refund->get_items( [ 'line_item', 'shipping', 'fee' ] ) as $line ) {
			$original = (int) $line->get_meta( '_refunded_item_id', true, 'edit' );
			if ( $original <= 0 || ! ( $line instanceof \WC_Order_Item_Product || $line instanceof \WC_Order_Item_Shipping || $line instanceof \WC_Order_Item_Fee ) ) {
				continue;
			}
			$amount  = Decimal::trim( ltrim( self::decimal( $line->get_total( 'edit' ) ), '-' ) );
			$tax     = Decimal::trim( ltrim( self::decimal( $line->get_total_tax( 'edit' ) ), '-' ) );
			$parent  = $order->get_item( $original );
			$qty     = $line instanceof \WC_Order_Item_Product ? abs( (int) $line->get_quantity( 'edit' ) ) : 0;
			$lines[] = [
				'item'    => Ref::make( 'wc', 'order_item', $original ),
				'qty'     => $qty,
				'amount'  => $amount,
				'tax'     => $tax,
				'restock' => $parent instanceof \WC_Order_Item && $qty > 0 && (int) $parent->get_meta( '_restock_refunded_items', true, 'edit' ) > 0,
			];
		}
		return [
			'id'           => Ref::make( 'wc', 'refund', $refund->get_id() ),
			'type'         => 'refund',
			'status'       => 'succeeded',
			'gateway'      => $order->get_payment_method( 'edit' ),
			'reference'    => null,
			'amount'       => $money( $refund->get_amount( 'edit' ) ),
			'reason'       => $refund->get_reason( 'edit' ),
			'refunded_by'  => $refund->get_refunded_by( 'edit' ),
			'gateway_used' => $refund->get_refunded_payment( 'edit' ),
			'completed_at' => Dates::from_wc( $refund->get_date_created( 'edit' ) ),
			'lines'        => $lines,
		];
	}

	/**
	 * `get_taxes()` total amounts keyed by tax rate reference.
	 *
	 * @param array<string,mixed> $taxes
	 * @return array<string,string>
	 */
	private static function taxes( array $taxes ): array {
		$out = [];
		foreach ( is_array( $taxes['total'] ?? null ) ? $taxes['total'] : [] as $rate_id => $amount ) {
			$value = Decimal::clean( $amount );
			if ( $value !== null && (int) $rate_id > 0 ) {
				$out[ Ref::make( 'wc', Record::TAX_RATE, (int) $rate_id ) ] = $value;
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function decimal( mixed $value ): string {
		return Decimal::clean( $value ) ?? '0';
	}
}
