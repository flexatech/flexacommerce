<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Targets\FxcOrderWriter;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Orders from the FlexaCommerce tables, in the shape WooOrderReader emits
 * (doc 05 §5.4). An order an earlier import wrote gives back what the
 * import kept: the source status, the stock flag and the source meta.
 */
final class FxcOrderReader {
	private const ADDRESS_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];

	private string $currency = '';

	private int $exponent = 2;

	public function __construct( private readonly FxcEnumerationRepository $repository ) {}

	public function order( int $id ): ?Record {
		$rows = $this->repository->order( $id );
		if ( $rows === null ) {
			return null;
		}
		$order          = $rows['order'];
		$this->currency = (string) $order['currency'];
		$this->exponent = isset( $order['currency_exponent'] ) ? (int) $order['currency_exponent'] : Currency::exponent( $this->currency );

		$meta = [];
		foreach ( $rows['meta'] as $row ) {
			$meta[ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}

		$status = (string) $order['status'];
		foreach ( $rows['history'] as $row ) {
			$context = self::json( $row['context'] ?? null );
			if ( $row['type'] === 'status' && (string) ( $row['to_status'] ?? '' ) === $status && is_string( $context['source_status'] ?? null ) ) {
				$status = $context['source_status'];
			}
		}

		$items   = [];
		$depends = [];
		$by_id   = [];
		$reduced = false;
		foreach ( $rows['items'] as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
			$reduced                   = $reduced || (int) $row['stock_reduced_qty'] > 0;
		}
		$stock_reduced = isset( $meta[ FxcOrderWriter::STOCK_REDUCED_META ] ) ? $meta[ FxcOrderWriter::STOCK_REDUCED_META ] === '1' : $reduced;
		foreach ( $rows['items'] as $row ) {
			$line = $this->item( $row );
			if ( $line === null ) {
				continue;
			}
			if ( is_string( $line['product'] ?? null ) ) {
				$depends[] = $line['product'];
			}
			$items[] = $line;
		}

		$transactions = [];
		foreach ( $rows['transactions'] as $row ) {
			if ( (string) $row['status'] !== Transaction::SUCCEEDED ) {
				continue;
			}
			$transactions[] = $this->transaction( $row, $by_id );
		}

		$history = [];
		foreach ( $rows['history'] as $row ) {
			if ( $row['type'] !== 'note' ) {
				continue;
			}
			$context   = self::json( $row['context'] ?? null );
			$history[] = [
				'type'             => 'note',
				'message'          => (string) $row['message'],
				'customer_visible' => (bool) (int) $row['customer_visible'],
				'author'           => is_string( $context['author'] ?? null ) ? $context['author'] : '',
				'created_at'       => Dates::from_db( (string) $row['created_at'] ),
			];
		}

		$addresses = [
			'billing'  => null,
			'shipping' => null,
		];
		foreach ( $rows['addresses'] as $row ) {
			$type = (string) $row['type'];
			if ( array_key_exists( $type, $addresses ) ) {
				$fields             = array_map( 'strval', array_intersect_key( $row, array_flip( self::ADDRESS_FIELDS ) ) );
				$addresses[ $type ] = new AddressView( $type, ...$fields );
			}
		}

		$customer = (int) ( $order['customer_id'] ?? 0 );
		return new Record(
			Record::ORDER,
			Ref::make( 'fxc', Record::ORDER, $id ),
			[
				'number'               => (string) $order['order_number'],
				'status'               => $status,
				'currency'             => $this->currency,
				'prices_include_tax'   => (bool) (int) $order['prices_include_tax'],
				'created_via'          => (string) $order['created_via'],
				'created_at'           => Dates::from_db( (string) $order['created_at'] ),
				'modified_at'          => Dates::from_db( (string) $order['updated_at'] ),
				'paid_at'              => Dates::from_db( $order['paid_at'] ?? null ),
				'completed_at'         => Dates::from_db( $order['completed_at'] ?? null ),
				'trashed'              => ( $order['deleted_at'] ?? null ) !== null,
				'customer'             => $customer > 0 ? Ref::make( 'wp', 'user', $customer ) : null,
				'customer_ip'          => (string) $order['customer_ip'],
				'customer_note'        => (string) ( $order['customer_note'] ?? '' ),
				'order_key'            => (string) $order['order_key'],
				'payment_method'       => (string) $order['payment_method'],
				'payment_method_title' => (string) $order['payment_method_title'],
				'stock_reduced'        => $stock_reduced,
				'totals'               => [
					'subtotal' => $this->decimal( $order['subtotal'] ),
					'discount' => $this->decimal( $order['discount_total'] ),
					'shipping' => $this->decimal( $order['shipping_total'] ),
					'fees'     => $this->decimal( $order['fee_total'] ),
					'tax'      => $this->decimal( $order['tax_total'] ),
					'total'    => $this->decimal( $order['total'] ),
				],
				'addresses'            => [
					'billing'  => FxcStoreReader::address( $addresses['billing'], 'billing' ),
					'shipping' => FxcStoreReader::address( $addresses['shipping'], 'shipping' ),
				],
				'items'                => $items,
				'transactions'         => $transactions,
				'history'              => $history,
				'downloads'            => array_map(
					static fn ( array $row ): array => [
						'item'       => Ref::make( 'fxc', 'order_item', (int) $row['order_item_id'] ),
						'file'       => (string) $row['file_id'],
						'email'      => (string) $row['email'],
						'remaining'  => $row['downloads_remaining'] === null ? null : (int) $row['downloads_remaining'],
						'expires_at' => Dates::from_db( is_string( $row['access_expires'] ) ? $row['access_expires'] : null ),
						'count'      => (int) $row['download_count'],
						'granted_at' => Dates::from_db( (string) $row['granted_at'] ),
					],
					$rows['downloads']
				),
				'extra'                => self::extra( $meta ),
			],
			array_values( array_unique( $depends ) )
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|null
	 */
	private function item( array $row ): ?array {
		$id    = Ref::make( 'fxc', 'order_item', (int) $row['id'] );
		$meta  = self::json( $row['meta'] ?? null );
		$extra = [
			'source'  => 'flexacommerce',
			'meta'    => is_array( $meta['import'] ?? null ) ? $meta['import'] : [],
			'dropped' => [],
		];
		$tax   = $this->decimal( $row['tax_total'] );
		switch ( (string) $row['type'] ) {
			case OrderItem::PRODUCT:
				$product    = (int) ( $row['product_id'] ?? 0 );
				$variant    = (int) ( $row['variant_id'] ?? 0 );
				$attributes = [];
				foreach ( self::json( $row['attributes'] ?? null ) as $pair ) {
					if ( is_array( $pair ) ) {
						$attributes[] = [
							'label' => (string) ( $pair['label'] ?? '' ),
							'value' => (string) ( $pair['value'] ?? '' ),
						];
					}
				}
				$sku = (string) ( $row['sku'] ?? '' );
				return [
					'id'            => $id,
					'type'          => 'product',
					'product'       => $product > 0 ? Ref::make( 'fxc', Record::PRODUCT, $product ) : null,
					'variant'       => $variant > 0 ? Ref::make( 'fxc', 'variant', $variant ) : null,
					'name'          => (string) $row['name'],
					'sku'           => $sku !== '' ? $sku : null,
					'attributes'    => $attributes,
					'quantity'      => (int) $row['quantity'],
					'subtotal'      => $this->decimal( $row['subtotal'] ),
					'subtotal_tax'  => isset( $meta['subtotal_tax'] ) ? $this->decimal( $meta['subtotal_tax'] ) : $tax,
					'total'         => $this->decimal( $row['total'] ),
					'tax'           => $tax,
					'taxes'         => $this->taxes( $meta ),
					'tax_class'     => is_string( $meta['tax_class'] ?? null ) ? $meta['tax_class'] : '',
					'reduced_stock' => (int) $row['stock_reduced_qty'],
					'restocked'     => (int) $row['restocked_qty'],
					'virtual'       => ! empty( $meta['virtual'] ),
					'downloadable'  => ! empty( $meta['downloadable'] ),
					'extra'         => $extra,
				];
			case OrderItem::SHIPPING:
				return [
					'id'    => $id,
					'type'  => 'shipping',
					'code'  => (string) $row['code'],
					'name'  => (string) $row['name'],
					'total' => $this->decimal( $row['total'] ),
					'tax'   => $tax,
					'taxes' => $this->taxes( $meta ),
					'extra' => $extra,
				];
			case OrderItem::FEE:
				return [
					'id'         => $id,
					'type'       => 'fee',
					'name'       => (string) $row['name'],
					'total'      => $this->decimal( $row['total'] ),
					'tax'        => $tax,
					'taxes'      => $this->taxes( $meta ),
					'tax_class'  => is_string( $meta['tax_class'] ?? null ) ? $meta['tax_class'] : '',
					'tax_status' => is_string( $meta['tax_status'] ?? null ) && $meta['tax_status'] !== '' ? $meta['tax_status'] : 'taxable',
					'extra'      => $extra,
				];
			case OrderItem::DISCOUNT:
				return [
					'id'    => $id,
					'type'  => 'coupon',
					'code'  => (string) ( $row['code'] !== '' ? $row['code'] : $row['name'] ),
					'total' => $this->decimal( $row['total'] ),
					'tax'   => $tax,
					'extra' => $extra,
				];
			case OrderItem::TAX:
				$rate = (int) ( $meta['rate_id'] ?? 0 );
				return [
					'id'           => $id,
					'type'         => 'tax',
					'code'         => (string) $row['code'],
					'name'         => (string) $row['name'],
					'rate'         => $rate > 0 ? Ref::make( 'fxc', Record::TAX_RATE, $rate ) : null,
					'rate_percent' => Decimal::clean( (string) ( $meta['rate'] ?? '' ) ),
					'compound'     => (bool) ( $meta['compound'] ?? false ),
					'tax'          => $this->decimal( $meta['items_tax'] ?? $row['total'] ),
					'shipping_tax' => $this->decimal( $meta['shipping_tax'] ?? 0 ),
				];
		}
		return null;
	}

	/**
	 * @param array<string,mixed>              $row
	 * @param array<int,array<string,mixed>>   $items item rows by id
	 * @return array<string,mixed>
	 */
	private function transaction( array $row, array $items ): array {
		$data      = self::json( $row['data'] ?? null );
		$reference = $row['gateway_reference'] ?? null;
		$reference = is_string( $reference ) && $reference !== '' ? $reference : ( is_string( $data['reference'] ?? null ) ? $data['reference'] : null );
		$out       = [
			'id'           => Ref::make( 'fxc', 'transaction', (int) $row['id'] ),
			'type'         => (string) $row['type'] === Transaction::REFUND ? 'refund' : 'payment',
			'status'       => 'succeeded',
			'gateway'      => (string) $row['gateway'],
			'reference'    => $reference,
			'amount'       => [
				'amount'   => $this->decimal( abs( (int) $row['amount'] ) ),
				'currency' => $this->currency,
			],
			'completed_at' => Dates::from_db( $row['completed_at'] ?? $row['created_at'] ),
		];
		if ( $out['type'] !== 'refund' ) {
			return $out;
		}
		$out['reference'] = null;
		$lines            = [];
		foreach ( self::json( $row['refund_lines'] ?? null ) as $line ) {
			$item_id = (int) ( $line['item_id'] ?? 0 );
			if ( $item_id <= 0 ) {
				continue;
			}
			$gross = (int) ( $line['amount'] ?? 0 );
			$tax   = isset( $line['tax'] ) ? (int) $line['tax'] : self::tax_share( $gross, $items[ $item_id ] ?? null );
			$qty   = (int) ( $line['qty'] ?? 0 );
			$type  = (string) ( $items[ $item_id ]['type'] ?? '' );
			if ( ! in_array( $type, [ OrderItem::PRODUCT, OrderItem::SHIPPING, OrderItem::FEE ], true ) ) {
				continue;
			}
			$lines[] = [
				'item'    => Ref::make( 'fxc', 'order_item', $item_id ),
				'qty'     => $type === OrderItem::PRODUCT ? $qty : 0,
				'amount'  => $this->decimal( $gross - $tax ),
				'tax'     => $this->decimal( $tax ),
				'restock' => $type === OrderItem::PRODUCT && $qty > 0 && ! empty( $line['restock'] ),
			];
		}
		return $out + [
			'reason'       => is_string( $data['reason'] ?? null ) ? $data['reason'] : '',
			'refunded_by'  => (int) ( $data['refunded_by'] ?? 0 ),
			'gateway_used' => isset( $data['gateway_used'] ) ? (bool) $data['gateway_used'] : (string) $row['method'] === 'gateway',
			'lines'        => $lines,
		];
	}

	/**
	 * Tax part of a gross refund amount when the refund did not record it,
	 * in proportion to the line's tax.
	 *
	 * @param array<string,mixed>|null $item
	 */
	private static function tax_share( int $gross, ?array $item ): int {
		if ( $item === null ) {
			return 0;
		}
		$tax   = (int) $item['tax_total'];
		$whole = (int) $item['total'] + $tax;
		return $whole > 0 && $tax > 0 ? (int) round( $gross * $tax / $whole ) : 0;
	}

	/**
	 * @param array<string,mixed> $meta item meta
	 * @return array<string,string>
	 */
	private function taxes( array $meta ): array {
		$out = [];
		foreach ( is_array( $meta['taxes'] ?? null ) ? $meta['taxes'] : [] as $key => $amount ) {
			$key = (string) $key;
			// A rate the earlier import could not map kept its source reference as the key.
			$ref         = ctype_digit( $key ) ? Ref::make( 'fxc', Record::TAX_RATE, (int) $key ) : $key;
			$out[ $ref ] = $this->decimal( $amount );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Order meta other than the import bookkeeping; the prefix an earlier
	 * import added is taken off again.
	 *
	 * @param array<string,string> $meta
	 * @return array{source:string,meta:array<string,mixed>,dropped:list<string>}
	 */
	private static function extra( array $meta ): array {
		$out = [];
		foreach ( $meta as $key => $value ) {
			if ( str_starts_with( $key, '_fxc_' ) ) {
				continue;
			}
			if ( str_starts_with( $key, FxcOrderWriter::META_PREFIX ) ) {
				$key = substr( $key, strlen( FxcOrderWriter::META_PREFIX ) );
			}
			$decoded     = $value !== '' && ( $value[0] === '[' || $value[0] === '{' ) ? json_decode( $value, true ) : null;
			$out[ $key ] = is_array( $decoded ) ? $decoded : $value;
		}
		ksort( $out, SORT_STRING );
		return [
			'source'  => 'flexacommerce',
			'meta'    => $out,
			'dropped' => [],
		];
	}

	private function decimal( mixed $minor ): string {
		return Decimal::trim( Decimal::from_minor( (int) $minor, $this->currency, $this->exponent ) );
	}

	/** @return array<array-key,mixed> */
	private static function json( mixed $raw ): array {
		$decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : [];
	}
}
