<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Catalog\ProductService;
use Flexa\Commerce\Contracts\Exceptions\ProductNotFound;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Database\Sequence;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\ImportRepository;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Orders into FlexaCommerce (doc 05 §5.4). Every column is written from the
 * source, children are replaced as a whole on an update, gateways are never
 * called. Runs inside the target's transaction.
 */
final class FxcOrderWriter {
	/** Order meta naming the job that wrote the order; purge and the pending expiry read it. */
	public const JOB_META = '_fxc_import_job';

	public const SOURCE_META = '_fxc_source_id';

	/** Order meta keeping the source's "stock reduced" flag, for a FlexaCommerce to WooCommerce job. */
	public const STOCK_REDUCED_META = '_fxc_stock_reduced';

	/** Unknown source meta is kept under this prefix (doc 05 §5.4). */
	public const META_PREFIX = 'wc:';

	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	private string $currency = '';

	private int $exponent = 2;

	/** @var list<Problem> */
	private array $problems = [];

	/** @var array<int,array<int,int>> product id => variant id => stock owner id (0 = not managed) */
	private array $owners = [];

	public function __construct(
		private readonly ImportRepository $repository,
		private readonly ProductService $products,
		private readonly Sequence $sequence,
		private readonly Clock $clock,
	) {}

	public function order( Record $record, Mapping $map, Job $job ): WriteResult {
		$data           = $record->data;
		$this->problems = [];
		$this->currency = strtoupper( (string) ( $data['currency'] ?? Settings::string( 'currency' ) ) );
		$this->exponent = Currency::exponent( $this->currency );

		$target = $map->target_id( Record::ORDER, $record->source_id() );
		if ( $target > 0 && ! $this->repository->order_exists( $target ) ) {
			$target = 0;
		}
		$number                          = $this->number( (string) ( $data['number'] ?? $record->source_id() ), $target, $job );
		[ $status, $attention, $custom ] = $this->status( (string) ( $data['status'] ?? 'pending' ), $job );

		$created = Dates::to_db( is_string( $data['created_at'] ?? null ) ? $data['created_at'] : null ) ?? $this->clock->db_now();
		$updated = Dates::to_db( is_string( $data['modified_at'] ?? null ) ? $data['modified_at'] : null ) ?? $created;
		$totals  = is_array( $data['totals'] ?? null ) ? $data['totals'] : [];
		$total   = $this->minor( $totals['total'] ?? '0', 'total' );

		[ $items, $sources ] = $this->items( is_array( $data['items'] ?? null ) ? $data['items'] : [], (bool) ( $data['prices_include_tax'] ?? false ), $map );
		$transactions        = is_array( $data['transactions'] ?? null ) ? array_values( array_filter( $data['transactions'], 'is_array' ) ) : [];
		$this->apply_refunds( $items, $sources, $transactions );

		$paid     = 0;
		$refunded = 0;
		foreach ( $transactions as $txn ) {
			$amount = $this->minor( is_array( $txn['amount'] ?? null ) ? ( $txn['amount']['amount'] ?? '0' ) : '0', 'transaction' );
			if ( ( $txn['type'] ?? '' ) === Transaction::REFUND ) {
				$refunded += abs( $amount );
			} else {
				$paid += $amount;
			}
		}
		$this->check_totals( $items, $totals, $total );

		$billing  = FxcStoreWriter::address( 'billing', is_array( $data['addresses']['billing'] ?? null ) ? $data['addresses']['billing'] : [] );
		$shipping = FxcStoreWriter::address( 'shipping', is_array( $data['addresses']['shipping'] ?? null ) ? $data['addresses']['shipping'] : [] );
		$customer = Ref::int_id( is_string( $data['customer'] ?? null ) ? $data['customer'] : '' );
		if ( $customer > 0 && get_userdata( $customer ) === false ) {
			$this->problems[] = Problem::warning( 'missing_customer', sprintf( 'User %d no longer exists; the order is a guest order.', $customer ) );
			$customer         = 0;
		}

		$row = [
			'order_number'         => $number,
			'order_key'            => substr( (string) ( $data['order_key'] ?? '' ), 0, 64 ) ?: 'fxc_' . wp_generate_password( 20, false ),
			'status'               => $status,
			'payment_status'       => OrderStatus::payment_status( $total, $paid, $refunded ),
			'needs_attention'      => $attention ? 1 : 0,
			'currency'             => $this->currency,
			'currency_exponent'    => $this->exponent,
			'prices_include_tax'   => ! empty( $data['prices_include_tax'] ) ? 1 : 0,
			'subtotal'             => $this->minor( $totals['subtotal'] ?? '0', 'subtotal' ),
			'discount_total'       => $this->minor( $totals['discount'] ?? '0', 'discount' ),
			'shipping_total'       => $this->minor( $totals['shipping'] ?? '0', 'shipping' ),
			'fee_total'            => $this->minor( $totals['fees'] ?? '0', 'fees' ),
			'tax_total'            => $this->minor( $totals['tax'] ?? '0', 'tax' ),
			'total'                => $total,
			'amount_paid'          => $paid,
			'amount_refunded'      => $refunded,
			'customer_id'          => $customer > 0 ? $customer : null,
			'customer_email'       => substr( (string) ( $billing->email ?? '' ), 0, 190 ),
			'customer_name'        => substr( trim( ( $billing->first_name ?? '' ) . ' ' . ( $billing->last_name ?? '' ) ), 0, 200 ),
			'customer_ip'          => substr( (string) ( $data['customer_ip'] ?? '' ), 0, 45 ),
			'customer_note'        => (string) ( $data['customer_note'] ?? '' ) === '' ? null : (string) $data['customer_note'],
			'payment_method'       => substr( (string) ( $data['payment_method'] ?? '' ), 0, 64 ),
			'payment_method_title' => substr( (string) ( $data['payment_method_title'] ?? '' ), 0, 190 ),
			'created_via'          => substr( (string) ( $data['created_via'] ?? '' ), 0, 32 ),
			'created_at'           => $created,
			'updated_at'           => $updated,
			'paid_at'              => Dates::to_db( is_string( $data['paid_at'] ?? null ) ? $data['paid_at'] : null ),
			'completed_at'         => Dates::to_db( is_string( $data['completed_at'] ?? null ) ? $data['completed_at'] : null ),
			'cancelled_at'         => $status === OrderStatus::CANCELLED ? $updated : null,
			'deleted_at'           => ! empty( $data['trashed'] ) ? $updated : null,
		];

		$action = $target > 0 ? WriteResult::UPDATED : WriteResult::CREATED;
		if ( $target > 0 ) {
			$this->repository->clear_children( $target );
		}
		$order_id = $this->repository->save_order( $target, $row );

		$ids = $this->repository->insert_items( $order_id, $items );
		$this->repository->save_addresses( $order_id, array_values( array_filter( [ $billing, $shipping ] ) ) );
		$this->write_transactions( $order_id, $record->source_id(), $transactions, $sources, $ids, $created );
		$this->write_history( $order_id, $data, $job, $custom, $status, $created );
		$this->repository->insert_meta( $order_id, $this->meta( $data, $record, $job ) );

		$numeric = preg_match( '/^\d{1,18}$/', $number ) === 1 ? (int) $number : 0;
		if ( $numeric > 0 ) {
			$this->sequence->raise_to( 'order_number', $numeric );
		}

		$map->remember( Record::ORDER, $record->source_id(), $order_id, $record->checksum );
		return new WriteResult( $action, $order_id, $this->problems );
	}

	public function modified_at( int $order_id ): ?string {
		return $this->repository->updated_at( $order_id );
	}

	public function delete( int $order_id ): bool {
		return $this->repository->delete_order( $order_id );
	}

	/** Collision policy of doc 05 §6: prefix (default) or skip. */
	private function number( string $number, int $target, Job $job ): string {
		$number = substr( trim( $number ), 0, 40 );
		if ( $number === '' ) {
			throw new Rejected( 'invalid_order_number', 'The order has no number.' );
		}
		$holder = $this->repository->order_with_number( $number );
		if ( $holder === 0 || $holder === $target ) {
			return $number;
		}
		if ( $job->option( 'order_number_conflict' ) === 'skip' ) {
			throw new Rejected( 'order_number_taken', sprintf( 'Order number %s is already used by another order.', $number ), [ 'order_id' => $holder ] );
		}
		$prefixed = substr( Settings::string( 'migration_number_prefix' ) . $number, 0, 40 );
		$holder   = $this->repository->order_with_number( $prefixed );
		if ( $prefixed === $number || ( $holder !== 0 && $holder !== $target ) ) {
			throw new Rejected( 'order_number_taken', sprintf( 'Order numbers %1$s and %2$s are both used by other orders.', $number, $prefixed ) );
		}
		$this->problems[] = Problem::warning( 'order_number_prefixed', sprintf( 'Order number %1$s is already used; the order was imported as %2$s.', $number, $prefixed ), [ 'number' => $number ] );
		return $prefixed;
	}

	/**
	 * Status per doc 05 §5.6. The job's status map wins; an unknown status
	 * becomes on-hold and needs attention.
	 *
	 * @return array{0:string,1:bool,2:?string} status, needs attention, original custom status
	 */
	private function status( string $source, Job $job ): array {
		$map    = (array) $job->option( 'status_map' );
		$mapped = isset( $map[ $source ] ) && is_string( $map[ $source ] ) ? $map[ $source ] : $source;
		if ( in_array( $mapped, OrderStatus::ALL, true ) ) {
			return [ $mapped, false, $mapped === $source ? null : $source ];
		}
		$this->problems[] = Problem::warning( 'custom_status', sprintf( 'The status "%s" has no equivalent; the order is on hold and needs attention.', $source ), [ 'status' => $source ] );
		return [ OrderStatus::ON_HOLD, true, $source ];
	}

	/**
	 * Item rows for ImportRepository, plus the source item ref of each row.
	 *
	 * @param array<array-key,mixed> $lines
	 * @return array{0:list<array<string,mixed>>,1:list<string>}
	 */
	private function items( array $lines, bool $inclusive, Mapping $map ): array {
		$items   = [];
		$sources = [];
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$item = match ( $line['type'] ?? '' ) {
				'product'  => $this->product_item( $line, $inclusive, $map ),
				'shipping' => $this->charge_item( OrderItem::SHIPPING, $line, $map ),
				'fee'      => $this->charge_item( OrderItem::FEE, $line, $map ),
				'coupon'   => $this->discount_item( $line ),
				'tax'      => $this->tax_item( $line, $map ),
				default    => null,
			};
			if ( $item === null ) {
				continue;
			}
			$extra = is_array( $line['extra']['meta'] ?? null ) ? $line['extra']['meta'] : [];
			if ( $extra !== [] ) {
				$item['meta']['import'] = $extra;
			}
			$items[]   = $item;
			$sources[] = (string) ( $line['id'] ?? '' );
		}
		return [ $items, $sources ];
	}

	/**
	 * @param array<array-key,mixed> $line
	 * @return array<string,mixed>
	 */
	private function product_item( array $line, bool $inclusive, Mapping $map ): array {
		$qty      = max( 0, (int) ( $line['quantity'] ?? 1 ) );
		$subtotal = $this->minor( $line['subtotal'] ?? '0', 'item' );
		$total    = $this->minor( $line['total'] ?? '0', 'item' );
		$shelf    = $inclusive ? $subtotal + $this->minor( $line['subtotal_tax'] ?? '0', 'item' ) : $subtotal;

		$product_ref = is_string( $line['product'] ?? null ) ? $line['product'] : null;
		$product_id  = $product_ref !== null ? $map->target_id( Record::PRODUCT, Ref::parse( $product_ref )->id ?? '' ) : 0;
		$variant_id  = 0;
		if ( $product_id > 0 ) {
			$variant_ref = is_string( $line['variant'] ?? null ) ? $line['variant'] : null;
			$variant_id  = $variant_ref !== null ? $map->target_id( 'variant', Ref::parse( $variant_ref )->id ?? '' ) : 0;
			if ( $variant_id === 0 ) {
				$variant_id = $map->target_id( 'variant', Ref::parse( $product_ref )->id ?? '' );
			}
		} elseif ( $product_ref !== null ) {
			$this->problems[] = Problem::warning( 'missing_product', 'An order line refers to a product that was not imported; the line keeps its name and price only.', [ 'product' => $product_ref ] );
		}

		$reduced = max( 0, (int) ( $line['reduced_stock'] ?? 0 ) );
		$meta    = $this->taxes_meta( $line, $map ) + [
			'subtotal_tax' => $this->minor( $line['subtotal_tax'] ?? '0', 'item' ),
			'tax_class'    => (string) ( $line['tax_class'] ?? '' ),
		];
		$owner   = $reduced > 0 ? $this->stock_owner( $product_id, $variant_id ) : 0;
		if ( $owner > 0 ) {
			$meta['stock_id'] = $owner;
		}
		$attributes = [];
		foreach ( is_array( $line['attributes'] ?? null ) ? $line['attributes'] : [] as $pair ) {
			if ( is_array( $pair ) ) {
				$attributes[] = [
					'label' => (string) ( $pair['label'] ?? '' ),
					'value' => (string) ( $pair['value'] ?? '' ),
				];
			}
		}
		return [
			'type'              => OrderItem::PRODUCT,
			'product_id'        => $product_id,
			'variant_id'        => $variant_id,
			'name'              => self::cut( $line['name'] ?? '', 255 ),
			'sku'               => self::cut( $line['sku'] ?? '', 100 ),
			'attributes'        => $attributes,
			'quantity'          => $qty,
			'unit_price'        => $qty > 0 ? (int) round( $shelf / $qty ) : $shelf,
			'subtotal'          => $subtotal,
			'discount_total'    => max( 0, $subtotal - $total ),
			'tax_total'         => $this->minor( $line['tax'] ?? '0', 'item' ),
			'total'             => $total,
			'stock_reduced_qty' => $reduced,
			'restocked_qty'     => max( 0, (int) ( $line['restocked'] ?? 0 ) ),
			'meta'              => $meta,
		];
	}

	/**
	 * Shipping and fee lines: one unit of the net amount, tax on top.
	 *
	 * @param array<array-key,mixed> $line
	 * @return array<string,mixed>
	 */
	private function charge_item( string $type, array $line, Mapping $map ): array {
		$total = $this->minor( $line['total'] ?? '0', 'item' );
		$meta  = $this->taxes_meta( $line, $map );
		if ( $type === OrderItem::FEE ) {
			$meta += [
				'tax_class'  => (string) ( $line['tax_class'] ?? '' ),
				'tax_status' => (string) ( $line['tax_status'] ?? '' ),
			];
		}
		return [
			'type'       => $type,
			'code'       => self::cut( $line['code'] ?? '', 100 ),
			'name'       => self::cut( $line['name'] ?? '', 255 ),
			'quantity'   => 1,
			'unit_price' => $total,
			'subtotal'   => $total,
			'tax_total'  => $this->minor( $line['tax'] ?? '0', 'item' ),
			'total'      => $total,
			'meta'       => $meta,
		];
	}

	/**
	 * Informational: the discount is already inside the product lines.
	 *
	 * @param array<array-key,mixed> $line
	 * @return array<string,mixed>
	 */
	private function discount_item( array $line ): array {
		$total = $this->minor( $line['total'] ?? '0', 'item' );
		$code  = self::cut( $line['code'] ?? '', 100 );
		return [
			'type'       => OrderItem::DISCOUNT,
			'code'       => $code,
			'name'       => $code,
			'quantity'   => 1,
			'unit_price' => $total,
			'subtotal'   => $total,
			'tax_total'  => $this->minor( $line['tax'] ?? '0', 'item' ),
			'total'      => $total,
		];
	}

	/**
	 * @param array<array-key,mixed> $line
	 * @return array<string,mixed>
	 */
	private function tax_item( array $line, Mapping $map ): array {
		$items_tax    = $this->minor( $line['tax'] ?? '0', 'item' );
		$shipping_tax = $this->minor( $line['shipping_tax'] ?? '0', 'item' );
		$total        = $items_tax + $shipping_tax;
		return [
			'type'       => OrderItem::TAX,
			'code'       => self::cut( $line['code'] ?? '', 100 ),
			'name'       => self::cut( $line['name'] ?? '', 255 ),
			'quantity'   => 1,
			'unit_price' => $total,
			'subtotal'   => $total,
			'total'      => $total,
			'meta'       => [
				'rate_id'      => $this->rate_key( is_string( $line['rate'] ?? null ) ? $line['rate'] : null, $map ),
				'rate'         => (string) ( $line['rate_percent'] ?? '0' ),
				'compound'     => (bool) ( $line['compound'] ?? false ),
				'items_tax'    => $items_tax,
				'shipping_tax' => $shipping_tax,
			],
		];
	}

	/**
	 * `taxes` item meta: FlexaCommerce rate key => tax on the line.
	 *
	 * @param array<array-key,mixed> $line
	 * @return array<string,mixed>
	 */
	private function taxes_meta( array $line, Mapping $map ): array {
		$taxes = [];
		foreach ( is_array( $line['taxes'] ?? null ) ? $line['taxes'] : [] as $ref => $amount ) {
			$tax = $this->minor( $amount, 'item' );
			if ( $tax === 0 ) {
				continue;
			}
			$key           = $this->rate_key( (string) $ref, $map );
			$key           = $key === '0' ? (string) $ref : $key;
			$taxes[ $key ] = ( $taxes[ $key ] ?? 0 ) + $tax;
		}
		return $taxes === [] ? [] : [ 'taxes' => $taxes ];
	}

	/** Mapped rate id as the rate key (`RateRow::key()`), '0' when the rate was not imported. */
	private function rate_key( ?string $ref, Mapping $map ): string {
		$id = $ref !== null ? $map->target_id( Record::TAX_RATE, Ref::parse( $ref )->id ?? '' ) : 0;
		return (string) $id;
	}

	/**
	 * Refund counters on the refunded lines (doc 02 §7.10): quantity, gross
	 * total, tax per rate in proportion to the line's taxes.
	 *
	 * @param list<array<string,mixed>> $items
	 * @param list<string>              $sources
	 * @param list<array<mixed>>        $transactions
	 */
	private function apply_refunds( array &$items, array $sources, array $transactions ): void {
		$index = array_flip( $sources );
		foreach ( $transactions as $txn ) {
			if ( ( $txn['type'] ?? '' ) !== Transaction::REFUND ) {
				continue;
			}
			foreach ( is_array( $txn['lines'] ?? null ) ? $txn['lines'] : [] as $line ) {
				$i = is_array( $line ) ? ( $index[ (string) ( $line['item'] ?? '' ) ] ?? null ) : null;
				if ( $i === null || ! isset( $items[ $i ] ) ) {
					continue;
				}
				$item   = &$items[ $i ];
				$tax    = $this->minor( $line['tax'] ?? '0', 'refund' );
				$amount = $this->minor( $line['amount'] ?? '0', 'refund' ) + $tax;
				$qty    = max( 0, (int) ( $line['qty'] ?? 0 ) );

				$item['refunded_qty']   = min( (int) ( $item['quantity'] ?? 0 ), (int) ( $item['refunded_qty'] ?? 0 ) + $qty );
				$item['refunded_total'] = (int) ( $item['refunded_total'] ?? 0 ) + $amount;
				$taxes                  = is_array( $item['meta']['taxes'] ?? null ) ? $item['meta']['taxes'] : [];
				if ( $tax > 0 && $taxes !== [] && array_sum( $taxes ) > 0 ) {
					$done  = is_array( $item['meta']['refunded_taxes'] ?? null ) ? $item['meta']['refunded_taxes'] : [];
					$parts = ( new Money( $tax, $this->currency ) )->allocate( array_map( 'intval', array_values( $taxes ) ) );
					foreach ( array_keys( $taxes ) as $n => $key ) {
						$done[ $key ] = (int) ( $done[ $key ] ?? 0 ) + $parts[ $n ]->amount;
					}
					$item['meta']['refunded_taxes'] = $done;
				}
				unset( $item );
			}
		}
	}

	/**
	 * Payment and refund transactions with `method = import`. A gateway
	 * reference another order already holds is kept in `data` only.
	 *
	 * @param list<array<mixed>> $transactions
	 * @param list<string>       $sources source item ref per item row
	 * @param list<int>          $ids     new item ids per item row
	 */
	private function write_transactions( int $order_id, string $source_id, array $transactions, array $sources, array $ids, string $created ): void {
		$item_ids  = array_combine( $sources, array_slice( $ids, 0, count( $sources ) ) );
		$payment   = null;
		$refund_no = 0;
		foreach ( $transactions as $txn ) {
			$type      = ( $txn['type'] ?? '' ) === Transaction::REFUND ? Transaction::REFUND : Transaction::PAYMENT;
			$gateway   = substr( (string) ( $txn['gateway'] ?? '' ), 0, 64 );
			$reference = is_string( $txn['reference'] ?? null ) && $txn['reference'] !== '' ? substr( $txn['reference'], 0, 191 ) : null;
			$data      = [ 'source' => (string) ( $txn['id'] ?? '' ) ];
			if ( $reference !== null && $this->repository->reference_taken( $gateway, $reference, $order_id ) ) {
				$this->problems[]  = Problem::warning( 'reference_taken', sprintf( 'The gateway reference %s belongs to another order; it was kept as a note on the transaction.', $reference ) );
				$data['reference'] = $reference;
				$reference         = null;
			}
			$completed = Dates::to_db( is_string( $txn['completed_at'] ?? null ) ? $txn['completed_at'] : null ) ?? $created;
			$amount    = abs( $this->minor( is_array( $txn['amount'] ?? null ) ? ( $txn['amount']['amount'] ?? '0' ) : '0', 'transaction' ) );
			$lines     = [];
			if ( $type === Transaction::REFUND ) {
				++$refund_no;
				foreach ( is_array( $txn['lines'] ?? null ) ? $txn['lines'] : [] as $line ) {
					$item_id = is_array( $line ) ? ( $item_ids[ (string) ( $line['item'] ?? '' ) ] ?? 0 ) : 0;
					if ( $item_id > 0 ) {
						$lines[] = [
							'item_id' => $item_id,
							'qty'     => max( 0, (int) ( $line['qty'] ?? 0 ) ),
							'amount'  => $this->minor( $line['amount'] ?? '0', 'refund' ) + $this->minor( $line['tax'] ?? '0', 'refund' ),
							'tax'     => $this->minor( $line['tax'] ?? '0', 'refund' ),
							'restock' => (bool) ( $line['restock'] ?? false ),
						];
					}
				}
				$data += array_filter(
					[
						'reason'      => (string) ( $txn['reason'] ?? '' ),
						'refunded_by' => (int) ( $txn['refunded_by'] ?? 0 ),
					]
				);
				if ( ! empty( $txn['gateway_used'] ) ) {
					$data['gateway_used'] = true;
				}
			}
			$id = $this->repository->insert_transaction(
				[
					'order_id'          => $order_id,
					'parent_id'         => $type === Transaction::REFUND ? $payment : null,
					'type'              => $type,
					'status'            => Transaction::SUCCEEDED,
					'method'            => 'import',
					'gateway'           => $gateway,
					'amount'            => $amount,
					'currency'          => $this->currency,
					'idempotency_key'   => $type === Transaction::REFUND ? sprintf( 'import:wc:%s:refund:%d', $source_id, $refund_no ) : sprintf( 'import:wc:%s:payment', $source_id ),
					'gateway_reference' => $reference,
					'refund_lines'      => $lines === [] ? null : (string) wp_json_encode( $lines, self::JSON_FLAGS ),
					'data'              => (string) wp_json_encode( $data, self::JSON_FLAGS ),
					'created_at'        => $completed,
					'updated_at'        => $completed,
					'completed_at'      => $completed,
				]
			);
			if ( $type === Transaction::PAYMENT && $payment === null ) {
				$payment = $id;
			}
		}
	}

	/**
	 * Source notes, a note for a remapped status, and the import row.
	 *
	 * @param array<string,mixed> $data
	 */
	private function write_history( int $order_id, array $data, Job $job, ?string $custom, string $status, string $created ): void {
		$rows = [];
		foreach ( is_array( $data['history'] ?? null ) ? $data['history'] : [] as $note ) {
			if ( ! is_array( $note ) ) {
				continue;
			}
			$rows[] = [
				'type'             => 'note',
				'message'          => (string) ( $note['message'] ?? '' ),
				'customer_visible' => (bool) ( $note['customer_visible'] ?? false ),
				'actor_type'       => 'migration',
				'actor_id'         => 0,
				'from_status'      => null,
				'to_status'        => null,
				'context'          => array_filter( [ 'author' => (string) ( $note['author'] ?? '' ) ] ),
				'created_at'       => Dates::to_db( is_string( $note['created_at'] ?? null ) ? $note['created_at'] : null ) ?? $created,
			];
		}
		$now = $this->clock->db_now();
		if ( $custom !== null ) {
			$rows[] = [
				'type'             => 'status',
				'message'          => sprintf( 'Imported with the status "%1$s", now %2$s.', $custom, $status ),
				'customer_visible' => false,
				'actor_type'       => 'migration',
				'actor_id'         => 0,
				'from_status'      => null,
				'to_status'        => $status,
				'context'          => [ 'source_status' => $custom ],
				'created_at'       => $now,
			];
		}
		$rows[] = [
			'type'             => 'import',
			'message'          => sprintf( 'Imported from WooCommerce by migration job %d.', $job->id ),
			'customer_visible' => false,
			'actor_type'       => 'migration',
			'actor_id'         => $job->created_by,
			'from_status'      => null,
			'to_status'        => null,
			'context'          => [ 'job_id' => $job->id ],
			'created_at'       => $now,
		];
		$this->repository->insert_history( $order_id, $rows );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,string>
	 */
	private function meta( array $data, Record $record, Job $job ): array {
		$meta  = [
			self::JOB_META           => (string) $job->id,
			self::SOURCE_META        => $record->id,
			self::STOCK_REDUCED_META => ! empty( $data['stock_reduced'] ) ? '1' : '0',
		];
		$extra = is_array( $data['extra']['meta'] ?? null ) ? $data['extra']['meta'] : [];
		foreach ( $extra as $key => $value ) {
			$meta[ substr( self::META_PREFIX . $key, 0, 191 ) ] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, self::JSON_FLAGS );
		}
		$dropped = is_array( $data['extra']['dropped'] ?? null ) ? $data['extra']['dropped'] : [];
		if ( $dropped !== [] ) {
			$this->problems[] = Problem::warning( 'meta_dropped', 'Some meta values are PHP objects and were not imported.', [ 'keys' => array_values( $dropped ) ] );
		}
		return $meta;
	}

	/**
	 * Source total wins; a recomputed difference is reported (doc 05 §5.4).
	 *
	 * @param list<array<string,mixed>> $items
	 * @param array<array-key,mixed>    $totals
	 */
	private function check_totals( array $items, array $totals, int $total ): void {
		$sum = 0;
		foreach ( $items as $item ) {
			$sum += match ( $item['type'] ) {
				OrderItem::PRODUCT, OrderItem::SHIPPING, OrderItem::FEE => (int) $item['total'],
				default => 0,
			};
		}
		$sum += $this->minor( $totals['tax'] ?? '0', 'tax' );
		if ( $sum !== $total ) {
			$this->problems[] = Problem::warning(
				'totals_mismatch',
				'The order lines do not add up to the order total; the source total was kept.',
				[
					'total' => $total,
					'lines' => $sum,
				]
			);
		}
	}

	/** Stock owner row of the line's variant, 0 when stock is not managed or the product is gone. */
	private function stock_owner( int $product_id, int $variant_id ): int {
		if ( $product_id <= 0 || $variant_id <= 0 ) {
			return 0;
		}
		if ( ! isset( $this->owners[ $product_id ] ) ) {
			$this->owners = count( $this->owners ) > 200 ? [] : $this->owners;
			$owners       = [];
			try {
				$product = $this->products->get( $product_id );
				foreach ( $product->rows() as $row ) {
					$owner              = $product->stock_owner( $row );
					$owners[ $row->id ] = $owner->manage_stock ? $owner->id : 0;
				}
			} catch ( ProductNotFound ) {
				$owners = [];
			}
			$this->owners[ $product_id ] = $owners;
		}
		return $this->owners[ $product_id ][ $variant_id ] ?? 0;
	}

	/** Decimal to minor units of the order currency; a value that does not fit is rounded and reported once. */
	private function minor( mixed $value, string $field ): int {
		$clean              = Decimal::clean( $value ) ?? '0';
		[ $amount, $exact ] = Decimal::to_minor( $clean, $this->currency, $this->exponent );
		if ( ! $exact ) {
			$this->problems[] = Problem::warning( 'amount_rounded', sprintf( 'The amount %1$s (%2$s) was rounded to the currency precision.', $clean, $field ) );
		}
		return $amount;
	}

	private static function cut( mixed $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( (string) $value, 0, $length ) : '';
	}
}
