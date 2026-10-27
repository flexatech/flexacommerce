<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxc_orders, fxc_order_items, fxc_order_addresses and
 * fxc_order_meta. Writes run inside the caller's transaction.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared, orders are not object-cached.
final class OrderRepository {
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	private const ADDRESS_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];

	public function __construct( private readonly \wpdb $wpdb ) {}

	/** Order row, items and addresses: three queries. `lock` takes the row FOR UPDATE. */
	public function find( int $order_id, bool $lock = false ): ?Order {
		$sql = 'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL' . ( $lock ? ' FOR UPDATE' : '' );
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, Schema::table( 'orders' ), $order_id ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function find_by_number( string $order_number ): ?Order {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE order_number = %s AND deleted_at IS NULL', Schema::table( 'orders' ), $order_number ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * `lock` takes a shared lock, which also sees a row committed after this
	 * transaction's snapshot. Shared, because every submit that lost the
	 * insert race already holds one from the duplicate-key check; asking
	 * for an exclusive lock there deadlocks the losers against each other.
	 */
	public function find_by_checkout_token( string $token, bool $lock = false ): ?Order {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE checkout_token = %s' . ( $lock ? ' LOCK IN SHARE MODE' : '' ), Schema::table( 'orders' ), $token ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Pending orders past the expiry, imported ones excluded (doc 05 §5.6).
	 *
	 * @return list<Order> without items
	 */
	public function stale_pending( string $older_than, int $limit ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i o WHERE status = %s AND created_at < %s AND deleted_at IS NULL AND NOT EXISTS ( SELECT 1 FROM %i m WHERE m.order_id = o.id AND m.meta_key = %s ) ORDER BY created_at, id LIMIT %d',
				Schema::table( 'orders' ),
				OrderStatus::PENDING,
				$older_than,
				Schema::table( 'order_meta' ),
				'_fxc_import_job',
				$limit
			),
			ARRAY_A
		);
		return array_map( static fn ( array $row ): Order => Order::from_row( $row ), $rows ?: [] );
	}

	/**
	 * @return array{orders:list<Order>,total:int} rows without items
	 */
	public function search( OrderQuery $query ): array {
		[ $where, $args ] = $query->where();
		$table            = Schema::table( 'orders' );
		$total            = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $table, ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from fixed fragments in OrderQuery.
		if ( $total === 0 ) {
			return [
				'orders' => [],
				'total'  => 0,
			];
		}
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE {$where} ORDER BY {$query->order_by()} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
				$table,
				...[ ...$args, $query->per_page, ( $query->page - 1 ) * $query->per_page ]
			),
			ARRAY_A
		);
		return [
			'orders' => array_map( static fn ( array $row ): Order => Order::from_row( $row ), $rows ?: [] ),
			'total'  => $total,
		];
	}

	/** @return array<string,int> status => count, for the list tabs */
	public function count_by_status(): array {
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE deleted_at IS NULL GROUP BY status', Schema::table( 'orders' ) ),
			ARRAY_A
		);
		$counts = [];
		foreach ( $rows ?: [] as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	public function count_needs_attention(): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE needs_attention = 1 AND deleted_at IS NULL', Schema::table( 'orders' ) )
		);
	}

	/**
	 * Insert the order, its items and addresses. Returns 0 when the checkout
	 * token is already used (a resubmitted checkout).
	 */
	public function insert( NewOrder $order, string $number, string $key, string $now ): int {
		$suppressed = $this->wpdb->suppress_errors( true );
		try {
			$ok = $this->wpdb->insert(
				Schema::table( 'orders' ),
				[
					'order_number'         => $number,
					'order_key'            => $key,
					'status'               => $order->status,
					'payment_status'       => OrderStatus::payment_status( $order->total, 0, 0 ),
					'currency'             => $order->currency,
					'currency_exponent'    => $order->currency_exponent,
					'subtotal'             => $order->subtotal,
					'discount_total'       => $order->discount_total,
					'shipping_total'       => $order->shipping_total,
					'fee_total'            => $order->fee_total,
					'tax_total'            => $order->tax_total,
					'total'                => $order->total,
					'customer_id'          => $order->customer_id,
					'customer_email'       => $order->customer_email,
					'customer_name'        => $order->customer_name(),
					'customer_ip'          => $order->customer_ip,
					'customer_note'        => $order->customer_note,
					'payment_method'       => $order->payment_method,
					'payment_method_title' => $order->payment_method_title,
					'checkout_token'       => $order->checkout_token,
					'created_via'          => $order->created_via,
					'locale'               => $order->locale,
					'prices_include_tax'   => $order->prices_include_tax ? 1 : 0,
					'created_at'           => $now,
					'updated_at'           => $now,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);
		} finally {
			$this->wpdb->suppress_errors( $suppressed );
		}
		if ( $ok === false ) {
			$error = QueryFailed::from_wpdb( $this->wpdb );
			if ( $error->is_duplicate_key() && $order->checkout_token !== null && str_contains( $error->getMessage(), 'checkout_token' ) ) {
				return 0;
			}
			throw $error;
		}
		$order_id = (int) $this->wpdb->insert_id;
		$this->insert_items( $order_id, $order->items );
		$this->save_addresses( $order_id, array_values( array_filter( [ $order->billing, $order->shipping ] ) ) );
		$this->insert_meta( $order_id, $order->meta );
		return $order_id;
	}

	/** @param array<string,string> $meta */
	private function insert_meta( int $order_id, array $meta ): void {
		if ( $meta === [] ) {
			return;
		}
		$values = [];
		$args   = [ Schema::table( 'order_meta' ) ];
		foreach ( $meta as $key => $value ) {
			$values[] = '(%d, %s, %s)';
			array_push( $args, $order_id, (string) $key, $value );
		}
		$sql = 'INSERT INTO %i (order_id, meta_key, meta_value) VALUES ' . implode( ', ', $values );
		if ( $this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @param list<NewOrderItem> $items */
	private function insert_items( int $order_id, array $items ): void {
		if ( $items === [] ) {
			return;
		}
		$values = [];
		$args   = [ Schema::table( 'order_items' ) ];
		foreach ( $items as $position => $item ) {
			$values[] = '(%d, %s, %d, NULLIF(%d, 0), NULLIF(%d, 0), %s, %s, NULLIF(%s, %s), %s, %d, %d, %d, %d, %d, %d, %s)';
			array_push(
				$args,
				$order_id,
				$item->type,
				$position,
				(int) $item->product_id,
				(int) $item->variant_id,
				$item->code,
				$item->name,
				(string) $item->sku,
				'',
				(string) wp_json_encode( $item->attributes, self::JSON_FLAGS ),
				$item->quantity,
				$item->unit_price,
				$item->subtotal,
				$item->discount_total,
				$item->tax_total,
				$item->total,
				(string) wp_json_encode( self::item_meta( $item ), self::JSON_FLAGS )
			);
		}
		$sql = 'INSERT INTO %i (order_id, type, position, product_id, variant_id, code, name, sku, attributes, quantity, unit_price, subtotal, discount_total, tax_total, total, meta) VALUES ' . implode( ', ', $values );
		if ( $this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @return array<string,mixed>|\stdClass */
	private static function item_meta( NewOrderItem $item ): array|\stdClass {
		$meta = $item->stock_id > 0 ? [ 'stock_id' => $item->stock_id ] + $item->meta : $item->meta;
		return $meta === [] ? new \stdClass() : $meta;
	}

	/**
	 * Billing and shipping in one statement.
	 *
	 * @param list<AddressView> $addresses
	 */
	public function save_addresses( int $order_id, array $addresses ): void {
		if ( $addresses === [] ) {
			return;
		}
		$columns = null;
		$rows    = [];
		$args    = [];
		foreach ( $addresses as $address ) {
			$data            = [
				'order_id' => $order_id,
				'type'     => $address->type,
			] + $address->to_array();
			$data['country'] = strtoupper( substr( (string) $data['country'], 0, 2 ) );
			$columns       ??= array_keys( $data );
			$rows[]          = '(' . implode( ', ', array_fill( 0, count( $columns ), '%s' ) ) . ')';
			foreach ( $columns as $column ) {
				$args[] = (string) ( $data[ $column ] ?? '' );
			}
		}
		$names = implode( ', ', array_map( static fn ( string $c ): string => '`' . $c . '`', $columns ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column names come from AddressView, $rows holds placeholders only.
		$sql = $this->wpdb->prepare( "REPLACE INTO %i ({$names}) VALUES " . implode( ', ', $rows ), Schema::table( 'order_addresses' ), ...$args );
		if ( $this->wpdb->query( $sql ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @param array<string,string|int|null> $timestamps extra columns such as completed_at */
	public function update_status( int $order_id, string $status, string $now, array $timestamps = [] ): void {
		$data = [
			'status'     => $status,
			'updated_at' => $now,
		] + $timestamps;
		$this->update( $order_id, $data );
	}

	public function update_payment( int $order_id, int $paid, int $refunded, string $payment_status, string $now, ?string $paid_at = null ): void {
		$data = [
			'amount_paid'     => $paid,
			'amount_refunded' => $refunded,
			'payment_status'  => $payment_status,
			'updated_at'      => $now,
		];
		if ( $paid_at !== null ) {
			$data['paid_at'] = $paid_at;
		}
		$this->update( $order_id, $data );
	}

	public function set_needs_attention( int $order_id, bool $flag, string $now ): void {
		$this->update(
			$order_id,
			[
				'needs_attention' => $flag ? 1 : 0,
				'updated_at'      => $now,
			]
		);
	}

	public function set_order_key( int $order_id, string $key, string $now ): void {
		$this->update(
			$order_id,
			[
				'order_key'  => $key,
				'updated_at' => $now,
			]
		);
	}

	/** @param array<string,mixed> $data `meta` as an array is stored as JSON */
	public function update_item( int $item_id, array $data ): void {
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$data['meta'] = (string) wp_json_encode( $data['meta'] === [] ? new \stdClass() : $data['meta'], self::JSON_FLAGS );
		}
		if ( $this->wpdb->update( Schema::table( 'order_items' ), $data, [ 'id' => $item_id ] ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/**
	 * Set `stock_reduced_qty` on several lines in one statement, so taking
	 * stock costs one query per line only for the stock itself (P6q).
	 *
	 * @param array<int,int> $quantities item id => units now held
	 */
	public function set_stock_reduced( array $quantities ): void {
		if ( $quantities === [] ) {
			return;
		}
		$cases = [];
		$args  = [ Schema::table( 'order_items' ) ];
		foreach ( $quantities as $item_id => $qty ) {
			$cases[] = 'WHEN %d THEN %d';
			$args[]  = $item_id;
			$args[]  = $qty;
		}
		$ids = implode( ',', array_fill( 0, count( $quantities ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $cases and $ids are placeholders only.
		$sql = $this->wpdb->prepare( 'UPDATE %i SET stock_reduced_qty = CASE id ' . implode( ' ', $cases ) . " END WHERE id IN ({$ids})", ...[ ...$args, ...array_keys( $quantities ) ] );
		if ( $this->wpdb->query( $sql ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @param array<string,int|string|null> $data */
	public function update( int $order_id, array $data ): void {
		if ( $this->wpdb->update( Schema::table( 'orders' ), $data, [ 'id' => $order_id ] ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** Replace personal data in place (privacy eraser). */
	public function anonymize( int $order_id, string $now ): void {
		$this->update(
			$order_id,
			[
				'customer_email' => '',
				'customer_name'  => '',
				'customer_ip'    => '',
				'customer_note'  => '',
				'anonymized_at'  => $now,
				'updated_at'     => $now,
			]
		);
		foreach ( OrderFields::for_order( $this, $order_id ) as $answer ) {
			if ( $answer['key'] !== '' ) {
				$this->delete_meta( $order_id, $answer['key'] );
			}
		}
		$this->delete_meta( $order_id, OrderFields::META );
		$blank = array_fill_keys( self::ADDRESS_FIELDS, '' );
		unset( $blank['country'] );
		$this->wpdb->update( Schema::table( 'order_addresses' ), $blank, [ 'order_id' => $order_id ] );
		// Notes and email rows may name the customer; customer-visible rows were written for them.
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET message = NULL, context = NULL WHERE order_id = %d AND ( type IN (%s, %s) OR customer_visible = 1 )',
				Schema::table( 'order_history' ),
				$order_id,
				'note',
				'email'
			)
		);
	}

	/** @return list<int> order ids for a customer email or user id, newest first */
	public function ids_for_customer( string $email, int $user_id, int $limit, int $offset ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT id FROM %i WHERE ( ( %s <> %s AND customer_email = %s ) OR ( %d > 0 AND customer_id = %d ) ) AND deleted_at IS NULL ORDER BY id DESC LIMIT %d OFFSET %d',
				Schema::table( 'orders' ),
				$email,
				'',
				$email,
				$user_id,
				$user_id,
				$limit,
				$offset
			)
		);
		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Orders of a customer that still hold personal data, split by status:
	 * with `$in` true those in `$statuses`, else those outside them. Trashed
	 * orders count too, since they still hold the data.
	 *
	 * @param list<string> $statuses
	 * @return list<array{id:int,order_number:string}> oldest first
	 */
	public function unanonymized_for_customer( string $email, int $user_id, array $statuses, bool $in, int $limit ): array {
		$statuses = $statuses !== [] ? $statuses : [ '' ];
		$list     = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = 'SELECT id, order_number FROM %i WHERE ( ( %s <> %s AND customer_email = %s ) OR ( %d > 0 AND customer_id = %d ) ) AND anonymized_at IS NULL AND status ' . ( $in ? 'IN' : 'NOT IN' ) . " ( {$list} ) ORDER BY id ASC LIMIT %d";
		$rows     = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, Schema::table( 'orders' ), $email, '', $email, $user_id, $user_id, ...$statuses, ...[ $limit ] ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
			ARRAY_A
		);
		$out      = [];
		foreach ( $rows ?: [] as $row ) {
			$out[] = [
				'id'           => (int) $row['id'],
				'order_number' => (string) $row['order_number'],
			];
		}
		return $out;
	}

	public function get_meta( int $order_id, string $key ): ?string {
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT meta_value FROM %i WHERE order_id = %d AND meta_key = %s ORDER BY id LIMIT 1', Schema::table( 'order_meta' ), $order_id, $key )
		);
		return $value === null ? null : (string) $value;
	}

	public function set_meta( int $order_id, string $key, string $value ): void {
		$table   = Schema::table( 'order_meta' );
		$updated = $this->wpdb->query(
			$this->wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE order_id = %d AND meta_key = %s', $table, $value, $order_id, $key )
		);
		if ( $updated === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		if ( $updated === 0 && $this->get_meta( $order_id, $key ) === null ) {
			$this->wpdb->insert(
				$table,
				[
					'order_id'   => $order_id,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- own indexed table.
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- own table.
				],
				[ '%d', '%s', '%s' ]
			);
		}
	}

	public function delete_meta( int $order_id, string $key ): void {
		$this->wpdb->delete(
			Schema::table( 'order_meta' ),
			[
				'order_id' => $order_id,
				'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- own indexed table.
			],
			[ '%d', '%s' ]
		);
	}

	/** @param array<string,mixed> $row */
	private function hydrate( array $row ): Order {
		$order_id  = (int) $row['id'];
		$items     = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY position, id', Schema::table( 'order_items' ), $order_id ),
			ARRAY_A
		);
		$rows      = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', Schema::table( 'order_addresses' ), $order_id ),
			ARRAY_A
		);
		$addresses = [];
		foreach ( $rows ?: [] as $address ) {
			$fields                                 = array_map( 'strval', array_intersect_key( $address, array_flip( self::ADDRESS_FIELDS ) ) );
			$addresses[ (string) $address['type'] ] = new AddressView( (string) $address['type'], ...$fields );
		}
		return Order::from_row(
			$row,
			array_map( static fn ( array $item ): OrderItem => OrderItem::from_row( $item ), $items ?: [] ),
			$addresses
		);
	}
}
