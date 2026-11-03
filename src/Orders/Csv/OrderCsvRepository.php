<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders\Csv;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderFields;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Reads for the order export (ADR-070). A page is the orders after an id,
 * oldest first, up to the newest id seen when the export began, so an order
 * placed during the export does not move later pages. The lines, addresses,
 * fields, payments and packages of a page come in one query each.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared; an export reads fresh rows.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the filter is built from fixed fragments in OrderQuery; id lists are %d placeholders.
final class OrderCsvRepository {
	private const ADDRESS_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];

	public function __construct( private readonly \wpdb $wpdb ) {}

	/** @return array{until:int,total:int} the newest matching id and how many orders match */
	public function scope( OrderQuery $query ): array {
		$wpdb             = $this->wpdb;
		[ $where, $args ] = $query->where();
		$row              = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders and fixed SQL only; every value is a prepare() replacement.
			$wpdb->prepare( "SELECT COUNT(*) AS n, MAX(id) AS until FROM %i WHERE {$where}", Schema::table( 'orders' ), ...$args ),
			ARRAY_A
		);
		return [
			'until' => (int) ( $row['until'] ?? 0 ),
			'total' => (int) ( $row['n'] ?? 0 ),
		];
	}

	/** @return list<Order> with lines and addresses, oldest first */
	public function page( OrderQuery $query, int $after, int $until, int $limit ): array {
		$wpdb             = $this->wpdb;
		[ $where, $args ] = $query->where();
		$rows             = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders and fixed SQL only; every value is a prepare() replacement.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$where} AND id > %d AND id <= %d ORDER BY id LIMIT %d",
				Schema::table( 'orders' ),
				...[ ...$args, $after, $until, $limit ]
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : [];
		$ids  = array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
		if ( $ids === [] ) {
			return [];
		}

		$items = [];
		foreach ( $this->in( 'SELECT * FROM %%i WHERE order_id IN (%s) ORDER BY order_id, position, id', [ Schema::table( 'order_items' ) ], $ids ) as $item ) {
			$items[ (int) $item['order_id'] ][] = OrderItem::from_row( $item );
		}
		$addresses = [];
		foreach ( $this->in( 'SELECT * FROM %%i WHERE order_id IN (%s)', [ Schema::table( 'order_addresses' ) ], $ids ) as $address ) {
			$fields = array_map( 'strval', array_intersect_key( $address, array_flip( self::ADDRESS_FIELDS ) ) );
			$addresses[ (int) $address['order_id'] ][ (string) $address['type'] ] = new AddressView( (string) $address['type'], ...$fields );
		}

		return array_map(
			static fn ( array $row ): Order => Order::from_row( $row, $items[ (int) $row['id'] ] ?? [], $addresses[ (int) $row['id'] ] ?? [] ),
			$rows
		);
	}

	/**
	 * The checkout field answers of each order (ADR-031).
	 *
	 * @param list<int> $ids
	 * @return array<int,list<array{key:string,label:string,value:string,email:bool}>>
	 */
	public function fields( array $ids ): array {
		$out = [];
		foreach ( $this->in( 'SELECT order_id, meta_value FROM %%i WHERE meta_key = %%s AND order_id IN (%s) ORDER BY id', [ Schema::table( 'order_meta' ), OrderFields::META ], $ids ) as $row ) {
			$out[ (int) $row['order_id'] ] ??= OrderFields::parse( (string) $row['meta_value'] );
		}
		return $out;
	}

	/**
	 * What one gateway kept of each order: its succeeded payments less the
	 * succeeded refunds back to it.
	 *
	 * @param list<int> $ids
	 * @return array<int,int> minor units
	 */
	public function net_by_gateway( array $ids, string $gateway ): array {
		$out = [];
		$sql = 'SELECT order_id, SUM( CASE WHEN type = %%s THEN amount ELSE -amount END ) AS net FROM %%i WHERE gateway = %%s AND status = %%s AND order_id IN (%s) GROUP BY order_id';
		foreach ( $this->in( $sql, [ 'payment', Schema::table( 'transactions' ), $gateway, 'succeeded' ], $ids ) as $row ) {
			$out[ (int) $row['order_id'] ] = (int) $row['net'];
		}
		return $out;
	}

	/**
	 * The tracking numbers of each order's packages, oldest first (ADR-067).
	 *
	 * @param list<int> $ids
	 * @return array<int,list<string>>
	 */
	public function tracking( array $ids ): array {
		$out = [];
		foreach ( $this->in( 'SELECT order_id, tracking_number FROM %%i WHERE order_id IN (%s) ORDER BY shipped_at, id', [ Schema::table( 'shipments' ) ], $ids ) as $row ) {
			if ( (string) $row['tracking_number'] !== '' ) {
				$out[ (int) $row['order_id'] ][] = (string) $row['tracking_number'];
			}
		}
		return $out;
	}

	/**
	 * Runs `$sql`, whose `%s` is the id list. Its other placeholders are
	 * written `%%i` and `%%s` and take `$before`, in order, ahead of the ids.
	 *
	 * @param list<string>     $before
	 * @param list<int>        $ids
	 * @return list<array<string,mixed>>
	 */
	private function in( string $sql, array $before, array $ids ): array {
		$wpdb = $this->wpdb;
		$sql  = sprintf( $sql, implode( ',', array_fill( 0, count( $ids ), '%d' ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and fixed SQL only; every value is a prepare() replacement.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...[ ...$before, ...$ids ] ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}
}
