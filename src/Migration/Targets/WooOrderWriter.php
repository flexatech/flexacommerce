<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Orders into WooCommerce through its CRUD (doc 05 §5.5), so legacy, HPOS
 * and sync storage need no branch. Runs inside ImportGuard: no mail, no
 * stock change. Status is set without a transition, so no note or hook
 * fires for it; the source history is written as order notes.
 */
final class WooOrderWriter {
	public const SOURCE_META = '_fxc_source_id';
	public const NUMBER_META = '_fxc_order_number';
	/** The key sequential-number plugins read (doc 05 §10). */
	public const PLUGIN_NUMBER_META = '_order_number';

	/** @var list<Problem> */
	private array $problems = [];

	public function order( Record $record, Mapping $map, Job $job ): WriteResult {
		unset( $job );
		$data           = $record->data;
		$this->problems = [];

		$target = $map->target_id( Record::ORDER, $record->source_id() );
		$order  = $target > 0 ? wc_get_order( $target ) : false;
		if ( $order instanceof \WC_Order && $order->get_type() === 'shop_order' ) {
			if ( $order->get_status( 'edit' ) === 'trash' ) {
				$order->untrash();
			}
			$this->clear( $order );
			$action = WriteResult::UPDATED;
		} else {
			$order  = new \WC_Order();
			$action = WriteResult::CREATED;
		}

		$order->set_currency( strtoupper( (string) ( $data['currency'] ?? get_woocommerce_currency() ) ) );
		$order->set_prices_include_tax( ! empty( $data['prices_include_tax'] ) );
		$order->set_created_via( (string) ( $data['created_via'] ?? '' ) );
		foreach ( [
			'created_at'   => 'set_date_created',
			'paid_at'      => 'set_date_paid',
			'completed_at' => 'set_date_completed',
		] as $key => $setter ) {
			$order->{$setter}( Dates::to_timestamp( is_string( $data[ $key ] ?? null ) ? $data[ $key ] : null ) );
		}
		$account  = Ref::int_id( is_string( $data['customer'] ?? null ) ? $data['customer'] : null );
		$customer = $map->user_id( $account, true );
		if ( $account > 0 && $customer === 0 ) {
			$this->problems[] = Problem::warning( 'missing_customer', sprintf( 'User %d no longer exists; the order is a guest order.', $account ) );
		}
		$order->set_customer_id( $customer );
		$order->set_customer_ip_address( (string) ( $data['customer_ip'] ?? '' ) );
		$order->set_customer_note( (string) ( $data['customer_note'] ?? '' ) );
		if ( is_string( $data['order_key'] ?? null ) && $data['order_key'] !== '' ) {
			$order->set_order_key( $data['order_key'] );
		}
		$order->set_payment_method( (string) ( $data['payment_method'] ?? '' ) );
		$order->set_payment_method_title( (string) ( $data['payment_method_title'] ?? '' ) );
		$transactions = is_array( $data['transactions'] ?? null ) ? array_values( array_filter( $data['transactions'], 'is_array' ) ) : [];
		foreach ( $transactions as $txn ) {
			if ( ( $txn['type'] ?? '' ) === 'payment' && is_string( $txn['reference'] ?? null ) ) {
				$order->set_transaction_id( $txn['reference'] );
				break;
			}
		}
		$addresses = is_array( $data['addresses'] ?? null ) ? $data['addresses'] : [];
		foreach ( [ 'billing', 'shipping' ] as $type ) {
			WooStoreWriter::set_address( $order, $type, is_array( $addresses[ $type ] ?? null ) ? $addresses[ $type ] : [], $this->problems );
		}

		$stock_reduced = ! empty( $data['stock_reduced'] );
		$sources       = [];
		foreach ( is_array( $data['items'] ?? null ) ? $data['items'] : [] as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$item = $this->item( $line, $map, $stock_reduced );
			if ( $item === null ) {
				continue;
			}
			$order->add_item( $item );
			if ( is_string( $line['id'] ?? null ) ) {
				$sources[ $line['id'] ] = $item;
			}
		}

		$totals = is_array( $data['totals'] ?? null ) ? $data['totals'] : [];
		$order->set_discount_total( (string) ( $totals['discount'] ?? '0' ) );
		$order->set_shipping_total( (string) ( $totals['shipping'] ?? '0' ) );
		$discount_tax = 0.0;
		$shipping_tax = 0.0;
		$cart_tax     = 0.0;
		foreach ( $order->get_items( [ 'coupon', 'tax' ] ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Coupon ) {
				$discount_tax += (float) $item->get_discount_tax( 'edit' );
			} elseif ( $item instanceof \WC_Order_Item_Tax ) {
				$cart_tax     += (float) $item->get_tax_total( 'edit' );
				$shipping_tax += (float) $item->get_shipping_tax_total( 'edit' );
			}
		}
		$order->set_discount_tax( wc_format_decimal( $discount_tax ) );
		$order->set_shipping_tax( wc_format_decimal( $shipping_tax ) );
		$order->set_cart_tax( wc_format_decimal( $cart_tax ) );
		$order->set_total( (string) ( $totals['total'] ?? '0' ) );
		$order->set_order_stock_reduced( $stock_reduced );

		foreach ( WooCatalogWriter::extra_meta( $data['extra'] ?? null, $this->problems ) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->update_meta_data( self::NUMBER_META, (string) ( $data['number'] ?? '' ) );
		$order->update_meta_data( self::PLUGIN_NUMBER_META, (string) ( $data['number'] ?? '' ) );
		$order->update_meta_data( self::SOURCE_META, $record->id );
		$status = $this->status( (string) ( $data['status'] ?? 'pending' ) );
		self::set_status( $order, $status );
		try {
			$order_id = $order->save();
		} catch ( \Exception $e ) {
			throw new Rejected( 'order_failed', $e->getMessage() );
		}
		if ( $order_id <= 0 ) {
			throw new Rejected( 'order_failed', 'WooCommerce did not save the order.' );
		}

		$refunded = false;
		foreach ( $transactions as $txn ) {
			if ( ( $txn['type'] ?? '' ) === 'refund' && ( $txn['status'] ?? 'succeeded' ) === 'succeeded' ) {
				$refunded = $this->refund( $order_id, $txn, $sources, $map ) || $refunded;
			}
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new Rejected( 'order_failed', 'The order disappeared while it was written.' );
		}
		if ( $refunded && $order->get_status( 'edit' ) !== $status ) {
			self::set_status( $order, $status );
			$order->save();
		}
		$this->history( $order_id, is_array( $data['history'] ?? null ) ? $data['history'] : [] );
		$this->downloads( $order, is_array( $data['downloads'] ?? null ) ? $data['downloads'] : [], $sources );
		if ( ! empty( $data['trashed'] ) ) {
			$order->delete( false );
		}

		$map->remember( Record::ORDER, $record->source_id(), $order_id, $record->checksum );
		return new WriteResult( $action, $order_id, $this->problems );
	}

	public function modified_at( int $order_id ): ?string {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$date = $order->get_date_modified( 'edit' );
		return $date instanceof \DateTimeInterface ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : null;
	}

	/**
	 * What the verify report compares, in minor units, like ImportRepository::order_summary().
	 *
	 * @return array{order_number:string,status:string,currency:string,total:int,tax_total:int,amount_refunded:int,product_lines:int}|null
	 */
	public static function summary( int $order_id ): ?array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->get_type() !== 'shop_order' ) {
			return null;
		}
		$currency = $order->get_currency( 'edit' );
		$exponent = Currency::exponent( $currency );
		$minor    = static fn ( mixed $value ): int => abs( Decimal::to_minor( Decimal::clean( $value ) ?? '0', $currency, $exponent )[0] );
		return [
			'order_number'    => (string) $order->get_order_number(),
			'status'          => $order->get_status( 'edit' ),
			'currency'        => $currency,
			'total'           => $minor( $order->get_total( 'edit' ) ),
			'tax_total'       => $minor( $order->get_total_tax( 'edit' ) ),
			'amount_refunded' => $minor( $order->get_total_refunded() ),
			'product_lines'   => count( $order->get_items( 'line_item' ) ),
		];
	}

	public function delete( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order && $order->get_type() === 'shop_order' && (bool) $order->delete( true );
	}

	/**
	 * @param array<array-key,mixed> $line
	 */
	private function item( array $line, Mapping $map, bool $stock_reduced ): ?\WC_Order_Item {
		$type  = (string) ( $line['type'] ?? '' );
		$taxes = $this->taxes( $line['taxes'] ?? null, $map );
		switch ( $type ) {
			case 'product':
				$item       = new \WC_Order_Item_Product();
				$product_id = $map->target_id( Record::PRODUCT, Ref::parse( is_string( $line['product'] ?? null ) ? $line['product'] : null )->id ?? '' );
				$variant_id = $map->target_id( 'variant', Ref::parse( is_string( $line['variant'] ?? null ) ? $line['variant'] : null )->id ?? '' );
				$variation  = $variant_id > 0 ? wc_get_product( $variant_id ) : null;
				if ( ! $variation instanceof \WC_Product_Variation ) {
					// A product without variations is mapped as a variant too; only a real variation counts here.
					$variation  = null;
					$variant_id = 0;
				}
				if ( $product_id === 0 && is_string( $line['product'] ?? null ) ) {
					$this->problems[] = Problem::warning( 'missing_product', 'An ordered product was not imported; the line keeps its name only.', [ 'product' => $line['product'] ] );
				}
				$item->set_product_id( $product_id );
				$item->set_variation_id( $variant_id );
				$item->set_name( (string) ( $line['name'] ?? '' ) );
				$item->set_quantity( (int) ( $line['quantity'] ?? 1 ) );
				$item->set_tax_class( (string) ( $line['tax_class'] ?? '' ) );
				$item->set_subtotal( (string) ( $line['subtotal'] ?? '0' ) );
				$item->set_total( (string) ( $line['total'] ?? '0' ) );
				$item->set_taxes(
					[
						'total'    => $taxes,
						'subtotal' => self::subtotal_taxes( $taxes, (string) ( $line['subtotal_tax'] ?? '0' ) ),
					]
				);
				$item->set_subtotal_tax( (string) ( $line['subtotal_tax'] ?? '0' ) );
				$item->set_total_tax( (string) ( $line['tax'] ?? '0' ) );
				$this->item_attributes( $item, $variation, is_array( $line['attributes'] ?? null ) ? $line['attributes'] : [] );
				$reduced = (int) ( $line['reduced_stock'] ?? 0 );
				if ( $stock_reduced && $reduced > 0 ) {
					$item->update_meta_data( '_reduced_stock', (string) $reduced );
				}
				$restocked = (int) ( $line['restocked'] ?? 0 );
				if ( $restocked > 0 ) {
					$item->update_meta_data( '_restock_refunded_items', (string) $restocked );
				}
				break;
			case 'shipping':
				$item                  = new \WC_Order_Item_Shipping();
				[ $method, $instance ] = array_pad( explode( ':', (string) ( $line['code'] ?? '' ), 2 ), 2, '' );
				$item->set_method_id( $method );
				$item->set_instance_id( $instance );
				$item->set_method_title( (string) ( $line['name'] ?? '' ) );
				$item->set_total( (string) ( $line['total'] ?? '0' ) );
				$item->set_taxes( [ 'total' => $taxes ] );
				break;
			case 'fee':
				$item = new \WC_Order_Item_Fee();
				$item->set_name( (string) ( $line['name'] ?? '' ) );
				$item->set_amount( (string) ( $line['total'] ?? '0' ) );
				$item->set_total( (string) ( $line['total'] ?? '0' ) );
				$item->set_tax_class( (string) ( $line['tax_class'] ?? '' ) );
				$item->set_tax_status( ( $line['tax_status'] ?? 'taxable' ) === 'none' ? 'none' : 'taxable' );
				$item->set_taxes( [ 'total' => $taxes ] );
				break;
			case 'coupon':
				$item = new \WC_Order_Item_Coupon();
				$item->set_code( (string) ( $line['code'] ?? '' ) );
				$item->set_discount( (string) ( $line['total'] ?? '0' ) );
				$item->set_discount_tax( (string) ( $line['tax'] ?? '0' ) );
				break;
			case 'tax':
				$item = new \WC_Order_Item_Tax();
				$item->set_rate_id( $map->target_id( Record::TAX_RATE, Ref::parse( is_string( $line['rate'] ?? null ) ? $line['rate'] : null )->id ?? '' ) );
				$item->set_rate_code( (string) ( $line['code'] ?? '' ) );
				$item->set_label( (string) ( $line['name'] ?? '' ) );
				$item->set_compound( ! empty( $line['compound'] ) );
				$item->set_tax_total( (string) ( $line['tax'] ?? '0' ) );
				$item->set_shipping_tax_total( (string) ( $line['shipping_tax'] ?? '0' ) );
				if ( is_string( $line['rate_percent'] ?? null ) ) {
					$item->set_rate_percent( (float) $line['rate_percent'] );
				}
				return $item;
			default:
				$this->problems[] = Problem::warning( 'unknown_item', sprintf( 'An order line of type "%s" was left out.', $type ) );
				return null;
		}
		foreach ( WooCatalogWriter::extra_meta( $line['extra'] ?? null, $this->problems ) as $key => $value ) {
			$item->update_meta_data( $key, $value );
		}
		if ( is_string( $line['id'] ?? null ) ) {
			$item->update_meta_data( self::SOURCE_META, $line['id'] );
		}
		return $item;
	}

	/**
	 * Variation attributes as WooCommerce item meta: the term slug under the
	 * taxonomy for a global attribute, the value under the sanitized name
	 * for a local one. Pairs that match no attribute stay as label meta.
	 *
	 * @param array<array-key,mixed> $pairs
	 */
	private function item_attributes( \WC_Order_Item_Product $item, ?\WC_Product_Variation $variation, array $pairs ): void {
		$keys = [];
		if ( $variation !== null ) {
			$parent = wc_get_product( $variation->get_parent_id() );
			foreach ( $parent instanceof \WC_Product ? $parent->get_attributes( 'edit' ) : [] as $attribute ) {
				if ( $attribute instanceof \WC_Product_Attribute ) {
					$name = $attribute->get_name();
					$keys[ $attribute->is_taxonomy() ? wc_attribute_label( $name ) : $name ] = [ sanitize_title( $name ), $attribute->is_taxonomy() ? $name : '' ];
				}
			}
		}
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$label = (string) ( $pair['label'] ?? '' );
			$value = (string) ( $pair['value'] ?? '' );
			if ( $label === '' ) {
				continue;
			}
			[ $key, $taxonomy ] = $keys[ $label ] ?? [ $label, '' ];
			if ( $taxonomy !== '' ) {
				$term  = get_term_by( 'name', $value, $taxonomy );
				$value = $term instanceof \WP_Term ? $term->slug : $value;
			}
			$item->add_meta_data( $key, $value, true );
		}
	}

	/**
	 * @param array<string,mixed>          $txn
	 * @param array<string,\WC_Order_Item> $sources
	 */
	private function refund( int $order_id, array $txn, array $sources, Mapping $map ): bool {
		unset( $map );
		$lines = [];
		foreach ( is_array( $txn['lines'] ?? null ) ? $txn['lines'] : [] as $line ) {
			if ( ! is_array( $line ) || ! is_string( $line['item'] ?? null ) || ! isset( $sources[ $line['item'] ] ) ) {
				continue;
			}
			$item                     = $sources[ $line['item'] ];
			$rates                    = is_callable( [ $item, 'get_taxes' ] ) ? array_keys( (array) ( $item->get_taxes()['total'] ?? [] ) ) : [];
			$tax                      = (string) ( $line['tax'] ?? '0' );
			$lines[ $item->get_id() ] = [
				'qty'          => (int) ( $line['qty'] ?? 0 ),
				'refund_total' => (string) ( $line['amount'] ?? '0' ),
				// The canonical line keeps one tax figure; it goes to the first rate of the item.
				'refund_tax'   => $rates !== [] && (float) $tax !== 0.0 ? [ (int) $rates[0] => $tax ] : [],
			];
		}
		$amount      = is_array( $txn['amount'] ?? null ) ? ltrim( (string) ( $txn['amount']['amount'] ?? '0' ), '-' ) : '0';
		$keep_status = static fn (): bool => false;
		add_filter( 'woocommerce_order_fully_refunded_status', $keep_status, PHP_INT_MAX );
		try {
			$refund = wc_create_refund(
				[
					'amount'         => $amount,
					'reason'         => (string) ( $txn['reason'] ?? '' ),
					'order_id'       => $order_id,
					'line_items'     => $lines,
					'refund_payment' => false,
					'restock_items'  => false,
					'date_created'   => is_string( $txn['completed_at'] ?? null ) ? $txn['completed_at'] : null,
				]
			);
		} finally {
			remove_filter( 'woocommerce_order_fully_refunded_status', $keep_status, PHP_INT_MAX );
		}
		if ( is_wp_error( $refund ) ) {
			$this->problems[] = Problem::warning( 'refund_failed', sprintf( 'A refund of %1$s was not written: %2$s', $amount, $refund->get_error_message() ) );
			return false;
		}
		$refund->set_refunded_by( (int) ( $txn['refunded_by'] ?? 0 ) );
		$refund->set_refunded_payment( ! empty( $txn['gateway_used'] ) );
		$refund->save();
		return true;
	}

	/** @param array<array-key,mixed> $history */
	private function history( int $order_id, array $history ): void {
		foreach ( wc_get_order_notes( [ 'order_id' => $order_id ] ) as $note ) {
			wc_delete_order_note( (int) $note->id );
		}
		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || ( $entry['type'] ?? 'note' ) !== 'note' ) {
				continue;
			}
			$author = (string) ( $entry['author'] ?? 'system' );
			$time   = Dates::to_timestamp( is_string( $entry['created_at'] ?? null ) ? $entry['created_at'] : null ) ?? time();
			$id     = wp_insert_comment(
				[
					'comment_post_ID'      => $order_id,
					'comment_author'       => $author === 'system' || $author === '' ? 'WooCommerce' : $author,
					'comment_author_email' => '',
					'comment_author_url'   => '',
					'comment_content'      => (string) ( $entry['message'] ?? '' ),
					'comment_agent'        => 'WooCommerce',
					'comment_type'         => 'order_note',
					'comment_parent'       => 0,
					'comment_approved'     => 1,
					'comment_date'         => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $time ) ),
					'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $time ),
				]
			);
			if ( $id > 0 && ! empty( $entry['customer_visible'] ) ) {
				add_comment_meta( (int) $id, 'is_customer_note', 1 );
			}
		}
	}

	/**
	 * Download permissions with their counts (ADR-058). WooCommerce may have
	 * granted fresh ones when the status was set; those are replaced, and the
	 * order is marked as granted so it does not grant again.
	 *
	 * @param array<array-key,mixed>        $downloads
	 * @param array<string,\WC_Order_Item> $sources source item ref => written item
	 */
	private function downloads( \WC_Order $order, array $downloads, array $sources ): void {
		( new \WC_Customer_Download_Data_Store() )->delete_by_order_id( $order->get_id() );
		$skipped = 0;
		foreach ( $downloads as $entry ) {
			$item = is_array( $entry ) ? ( $sources[ (string) ( $entry['item'] ?? '' ) ] ?? null ) : null;
			if ( ! is_array( $entry ) || ! $item instanceof \WC_Order_Item_Product || ! is_string( $entry['file'] ?? null ) ) {
				++$skipped;
				continue;
			}
			$product = $item->get_variation_id() > 0 ? $item->get_variation_id() : $item->get_product_id();
			if ( $product <= 0 ) {
				++$skipped;
				continue;
			}
			$granted  = Dates::to_timestamp( is_string( $entry['granted_at'] ?? null ) ? $entry['granted_at'] : null );
			$download = new \WC_Customer_Download();
			$download->set_props(
				[
					'download_id'         => $entry['file'],
					'product_id'          => $product,
					'order_id'            => $order->get_id(),
					'order_key'           => $order->get_order_key(),
					'user_id'             => $order->get_customer_id(),
					'user_email'          => is_string( $entry['email'] ?? null ) && $entry['email'] !== '' ? $entry['email'] : $order->get_billing_email(),
					'downloads_remaining' => is_int( $entry['remaining'] ?? null ) ? (string) max( 0, $entry['remaining'] ) : '',
					'download_count'      => max( 0, (int) ( $entry['count'] ?? 0 ) ),
					'access_granted'      => $granted ?? time(),
					'access_expires'      => Dates::to_timestamp( is_string( $entry['expires_at'] ?? null ) ? $entry['expires_at'] : null ),
				]
			);
			$download->save();
		}
		if ( $skipped > 0 ) {
			$this->problems[] = Problem::warning( 'download_skipped', sprintf( '%d download permissions point at a product that was not imported; they were skipped.', $skipped ) );
		}
		$store_order = $order->get_data_store();
		if ( method_exists( $store_order, 'set_download_permissions_granted' ) ) {
			$store_order->set_download_permissions_granted( $order, true );
		}
	}

	/** Remove what a previous run wrote, so the order is rebuilt from the record. */
	private function clear( \WC_Order $order ): void {
		foreach ( $order->get_refunds() as $refund ) {
			$refund->delete( true );
		}
		$order->remove_order_items();
		foreach ( $order->get_meta_data() as $meta ) {
			$order->delete_meta_data( $meta->key );
		}
	}

	private function status( string $status ): string {
		$known = array_map( static fn ( string $key ): string => substr( $key, 3 ), array_keys( wc_get_order_statuses() ) );
		if ( in_array( $status, $known, true ) ) {
			return $status;
		}
		$this->problems[] = Problem::warning( 'custom_status', sprintf( 'WooCommerce does not know the status "%s"; the order is on hold.', $status ), [ 'status' => $status ] );
		return 'on-hold';
	}

	/** Status without a transition: no status note, no status hooks. */
	private static function set_status( \WC_Order $order, string $status ): void {
		$order->set_object_read( false );
		$order->set_status( $status );
		$order->set_object_read( true );
	}

	/**
	 * Tax amounts keyed by the mapped WooCommerce rate id.
	 *
	 * @return array<int,string>
	 */
	private function taxes( mixed $taxes, Mapping $map ): array {
		$out = [];
		foreach ( is_array( $taxes ) ? $taxes : [] as $ref => $amount ) {
			$rate = $map->target_id( Record::TAX_RATE, Ref::parse( (string) $ref )->id ?? '' );
			if ( $rate > 0 ) {
				$out[ $rate ] = (string) $amount;
			} else {
				$this->problems[] = Problem::warning( 'missing_tax_rate', 'A tax rate of an order line was not imported; its amount stays in the line total tax only.', [ 'rate' => $ref ] );
			}
		}
		return $out;
	}

	/**
	 * The canonical line has one subtotal tax; with one rate it is that
	 * rate's amount, with several it is split in the ratio of the totals.
	 *
	 * @param array<int,string> $taxes
	 * @return array<int,string>
	 */
	private static function subtotal_taxes( array $taxes, string $subtotal_tax ): array {
		if ( count( $taxes ) <= 1 ) {
			return $taxes === [] ? [] : [ (int) array_key_first( $taxes ) => $subtotal_tax ];
		}
		$sum = array_sum( array_map( 'floatval', $taxes ) );
		$out = [];
		foreach ( $taxes as $rate => $amount ) {
			$out[ $rate ] = $sum > 0 ? wc_format_decimal( (float) $subtotal_tax * (float) $amount / $sum ) : '0';
		}
		return $out;
	}
}
