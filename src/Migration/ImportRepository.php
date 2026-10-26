<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Order storage for imports (doc 05 §5.4). An import sets every column,
 * including the ones checkout never writes (payment state, dates, refund
 * counters), so it bypasses OrderRepository. Runs in the caller's transaction.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared, orders are not object-cached.
final class ImportRepository {
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	/** Child tables keyed by order_id, cleared before an update writes them again. */
	private const CHILDREN = [ 'order_items', 'order_addresses', 'order_history', 'order_meta', 'transactions' ];

	private const ITEM_COLUMNS = [
		'type'              => '%s',
		'position'          => '%d',
		'product_id'        => 'NULLIF(%d, 0)',
		'variant_id'        => 'NULLIF(%d, 0)',
		'code'              => '%s',
		'name'              => '%s',
		'sku'               => 'NULLIF(%s, \'\')',
		'attributes'        => '%s',
		'quantity'          => '%d',
		'unit_price'        => '%d',
		'subtotal'          => '%d',
		'discount_total'    => '%d',
		'tax_total'         => '%d',
		'total'             => '%d',
		'stock_reduced_qty' => '%d',
		'refunded_qty'      => '%d',
		'refunded_total'    => '%d',
		'restocked_qty'     => '%d',
		'meta'              => '%s',
	];

	private const ADDRESS_COLUMNS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];

	public function __construct( private readonly \wpdb $wpdb ) {}

	/** Id of the order holding a number, trashed ones included (the index covers them). */
	public function order_with_number( string $number ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM %i WHERE order_number = %s', Schema::table( 'orders' ), $number )
		);
	}

	public function order_exists( int $order_id ): bool {
		return $order_id > 0 && (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', Schema::table( 'orders' ), $order_id )
		) > 0;
	}

	/** @return array{order_number:string,status:string,currency:string,total:int,tax_total:int,amount_refunded:int,product_lines:int}|null */
	public function order_summary( int $order_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT o.order_number, o.status, o.currency, o.total, o.tax_total, o.amount_refunded, (SELECT COUNT(*) FROM %i i WHERE i.order_id = o.id AND i.type = 'product') AS product_lines FROM %i o WHERE o.id = %d",
				Schema::table( 'order_items' ),
				Schema::table( 'orders' ),
				$order_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return [
			'order_number'    => (string) $row['order_number'],
			'status'          => (string) $row['status'],
			'currency'        => (string) $row['currency'],
			'total'           => (int) $row['total'],
			'tax_total'       => (int) $row['tax_total'],
			'amount_refunded' => (int) $row['amount_refunded'],
			'product_lines'   => (int) $row['product_lines'],
		];
	}

	/** @return array<string,int> currency => orders */
	public function order_currencies(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT currency, COUNT(*) AS n FROM %i GROUP BY currency', Schema::table( 'orders' ) ),
			ARRAY_A
		);
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['currency'] ] = (int) $row['n'];
		}
		return $out;
	}

	public function updated_at( int $order_id ): ?string {
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT updated_at FROM %i WHERE id = %d', Schema::table( 'orders' ), $order_id )
		);
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Insert (id 0) or update the order row.
	 *
	 * @param array<string,int|string|null> $row column => value
	 */
	public function save_order( int $order_id, array $row ): int {
		$table = Schema::table( 'orders' );
		if ( $order_id > 0 ) {
			if ( $this->wpdb->update( $table, $row, [ 'id' => $order_id ] ) === false ) {
				throw QueryFailed::from_wpdb( $this->wpdb );
			}
			return $order_id;
		}
		if ( $this->wpdb->insert( $table, $row ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	public function clear_children( int $order_id ): void {
		foreach ( self::CHILDREN as $name ) {
			if ( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::table( $name ), $order_id ) ) === false ) {
				throw QueryFailed::from_wpdb( $this->wpdb );
			}
		}
	}

	/** The order and everything under it. False when it was gone already. */
	public function delete_order( int $order_id ): bool {
		$this->clear_children( $order_id );
		$deleted = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'orders' ), $order_id ) );
		if ( $deleted === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return $deleted > 0;
	}

	/**
	 * All lines in one statement. Returns the new ids in position order,
	 * read back by position because a multi-row insert does not promise
	 * consecutive ids.
	 *
	 * @param list<array<string,mixed>> $items columns of ITEM_COLUMNS; arrays in `attributes` and `meta` become JSON
	 * @return list<int>
	 */
	public function insert_items( int $order_id, array $items ): array {
		if ( $items === [] ) {
			return [];
		}
		$table  = Schema::table( 'order_items' );
		$values = [];
		$args   = [ $table ];
		foreach ( $items as $position => $item ) {
			$item['position'] = $position;
			$values[]         = '(%d, ' . implode( ', ', self::ITEM_COLUMNS ) . ')';
			$args[]           = $order_id;
			foreach ( array_keys( self::ITEM_COLUMNS ) as $column ) {
				$value  = $item[ $column ] ?? null;
				$args[] = match ( $column ) {
					'attributes' => (string) wp_json_encode( is_array( $value ) ? $value : [], self::JSON_FLAGS ),
					'meta'       => (string) wp_json_encode( is_array( $value ) && $value !== [] ? $value : new \stdClass(), self::JSON_FLAGS ),
					default      => $value ?? ( self::ITEM_COLUMNS[ $column ] === '%s' ? '' : 0 ),
				};
			}
		}
		$sql = 'INSERT INTO %i (order_id, ' . implode( ', ', array_keys( self::ITEM_COLUMNS ) ) . ') VALUES ' . implode( ', ', $values );
		if ( $this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare( 'SELECT id FROM %i WHERE order_id = %d ORDER BY position, id', $table, $order_id )
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * @param list<array{type:string,message:string,customer_visible:bool,actor_type:string,actor_id:int,from_status:?string,to_status:?string,context:array<string,mixed>,created_at:string}> $rows
	 */
	public function insert_history( int $order_id, array $rows ): void {
		if ( $rows === [] ) {
			return;
		}
		$values = [];
		$args   = [ Schema::table( 'order_history' ) ];
		foreach ( $rows as $row ) {
			$values[] = '(%d, %s, NULLIF(%s, \'\'), NULLIF(%s, \'\'), %s, %d, %s, NULLIF(%d, 0), NULLIF(%s, \'\'), %s)';
			array_push(
				$args,
				$order_id,
				$row['type'],
				(string) $row['from_status'],
				(string) $row['to_status'],
				$row['message'],
				$row['customer_visible'] ? 1 : 0,
				$row['actor_type'],
				$row['actor_id'],
				$row['context'] === [] ? '' : (string) wp_json_encode( $row['context'], self::JSON_FLAGS ),
				$row['created_at']
			);
		}
		$sql = 'INSERT INTO %i (order_id, type, from_status, to_status, message, customer_visible, actor_type, actor_id, context, created_at) VALUES ' . implode( ', ', $values );
		if ( $this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @param list<AddressView> $addresses */
	public function save_addresses( int $order_id, array $addresses ): void {
		if ( $addresses === [] ) {
			return;
		}
		$values = [];
		$args   = [ Schema::table( 'order_addresses' ) ];
		foreach ( $addresses as $address ) {
			$fields   = $address->to_array();
			$values[] = '(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)';
			array_push( $args, $order_id, $address->type );
			foreach ( self::ADDRESS_COLUMNS as $column ) {
				$args[] = (string) ( $fields[ $column ] ?? '' );
			}
		}
		$sql = 'INSERT INTO %i (order_id, type, ' . implode( ', ', self::ADDRESS_COLUMNS ) . ') VALUES ' . implode( ', ', $values );
		if ( $this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @param array<string,string> $meta */
	public function insert_meta( int $order_id, array $meta ): void {
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

	/** @param array<string,int|string|null> $row column => value; arrays are not accepted, encode them first */
	public function insert_transaction( array $row ): int {
		if ( $this->wpdb->insert( Schema::table( 'transactions' ), $row ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	/** A gateway reference held by a transaction of another order. */
	public function reference_taken( string $gateway, string $reference, int $order_id ): bool {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE gateway = %s AND gateway_reference = %s AND order_id <> %d',
				Schema::table( 'transactions' ),
				$gateway,
				$reference,
				$order_id
			)
		) > 0;
	}

	/**
	 * Orders an import job wrote, for purge (doc 05 §8.4).
	 *
	 * @return list<int>
	 */
	public function orders_of_job( int $job_id, int $after, int $limit ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %s AND order_id > %d ORDER BY order_id LIMIT %d',
				Schema::table( 'order_meta' ),
				'_fxc_import_job',
				(string) $job_id,
				$after,
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}
}
