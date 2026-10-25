<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The customers list (doc 02 §6.2): read-only, derived from the orders
 * table. A customer is a user id when the order has one, else the guest
 * email, so a user who changed their address stays one row. Anonymized
 * orders are left out.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared, the list is an admin report.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the key, conditions and ORDER BY are fixed fragments from this class.
final class CustomerRepository {
	public const ORDER_BY = [ 'last_order', 'orders', 'spent' ];

	public const TYPES = [ 'registered', 'guest' ];

	private const KEY = "IF( COALESCE( customer_id, 0 ) > 0, CONCAT( 'u', customer_id ), CONCAT( 'e', customer_email ) )";

	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * `spent` is what was paid less refunds, in minor units, counting only
	 * orders in `$currency`. `search` is an exact email, an email prefix or
	 * part of a name, matched against any of the customer's orders.
	 *
	 * @return array{items:list<array{customer_id:int,email:string,name:string,orders:int,spent:int,first_order:string,last_order:string}>,total:int}
	 */
	public function search( string $currency, string $type = '', string $search = '', string $orderby = 'last_order', string $order = 'desc', int $page = 1, int $per_page = 20 ): array {
		$table = Schema::table( 'orders' );
		$where = [ 'deleted_at IS NULL', 'anonymized_at IS NULL', "( COALESCE( customer_id, 0 ) > 0 OR customer_email <> '' )" ];
		$args  = [];
		if ( $type === 'registered' ) {
			$where[] = 'COALESCE( customer_id, 0 ) > 0';
		} elseif ( $type === 'guest' ) {
			$where[] = 'COALESCE( customer_id, 0 ) = 0';
		}
		$having = '';
		$search = trim( $search );
		if ( $search !== '' ) {
			$like   = $this->wpdb->esc_like( strtolower( $search ) );
			$having = 'HAVING SUM( customer_email LIKE %s OR customer_name LIKE %s ) > 0';
			$hargs  = [ $like . '%', '%' . $like . '%' ];
		}
		$where = implode( ' AND ', $where );
		$key   = self::KEY;

		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT {$key} AS ckey FROM %i WHERE {$where} GROUP BY ckey {$having} ) AS customers",
				$table,
				...[ ...$args, ...( $hargs ?? [] ) ]
			)
		);
		if ( $total === 0 ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		$column    = in_array( $orderby, self::ORDER_BY, true ) ? $orderby : 'last_order';
		$direction = $order === 'asc' ? 'ASC' : 'DESC';
		$rows      = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT {$key} AS ckey, MAX( COALESCE( customer_id, 0 ) ) AS customer_id, COUNT(*) AS orders,
					SUM( IF( currency = %s, amount_paid - amount_refunded, 0 ) ) AS spent,
					MIN( created_at ) AS first_order, MAX( created_at ) AS last_order, MAX( id ) AS last_id
				FROM %i WHERE {$where} GROUP BY ckey {$having}
				ORDER BY {$column} {$direction}, last_id {$direction} LIMIT %d OFFSET %d",
				...[ $currency, $table, ...$args, ...( $hargs ?? [] ), $per_page, ( max( 1, $page ) - 1 ) * $per_page ]
			),
			ARRAY_A
		) ?: [];

		$latest = $this->latest_contact( array_map( static fn ( array $row ): int => (int) $row['last_id'], $rows ) );
		$items  = [];
		foreach ( $rows as $row ) {
			$contact = $latest[ (int) $row['last_id'] ] ?? [ '', '' ];
			$items[] = [
				'customer_id' => (int) $row['customer_id'],
				'email'       => $contact[0],
				'name'        => $contact[1],
				'orders'      => (int) $row['orders'],
				'spent'       => (int) $row['spent'],
				'first_order' => (string) $row['first_order'],
				'last_order'  => (string) $row['last_order'],
			];
		}
		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Email and name from each customer's newest order.
	 *
	 * @param list<int> $ids
	 * @return array<int,array{0:string,1:string}>
	 */
	private function latest_contact( array $ids ): array {
		if ( $ids === [] ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT id, customer_email, customer_name FROM %i WHERE id IN ({$placeholders})", Schema::table( 'orders' ), ...$ids ),
			ARRAY_A
		) ?: [];
		$out          = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = [ (string) $row['customer_email'], (string) $row['customer_name'] ];
		}
		return $out;
	}
}
