<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Storage for fxc_shipments (ADR-067). */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared.
final class ShipmentRepository {
	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find( int $id ): ?Shipment {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'shipments' ), $id ), ARRAY_A );
		return is_array( $row ) ? Shipment::from_row( $row ) : null;
	}

	/** @return list<Shipment> oldest first */
	public function for_order( int $order_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY shipped_at, id', Schema::table( 'shipments' ), $order_id ),
			ARRAY_A
		);
		return array_map( [ Shipment::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Which of these orders have a shipment, with one query.
	 *
	 * @param list<int> $order_ids
	 * @return array<int,true>
	 */
	public function shipped( array $order_ids ): array {
		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( $order_ids === [] ) {
			return [];
		}
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT order_id FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')',
				Schema::table( 'shipments' ),
				...$order_ids
			)
		);
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	/**
	 * The carrier of the latest shipment, so the form starts on it.
	 *
	 * @return array{carrier:string,carrier_name:string}|null
	 */
	public function last_carrier(): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT carrier, carrier_name FROM %i ORDER BY id DESC LIMIT 1', Schema::table( 'shipments' ) ), ARRAY_A );
		return is_array( $row ) ? [
			'carrier'      => (string) $row['carrier'],
			'carrier_name' => (string) $row['carrier_name'],
		] : null;
	}

	/**
	 * @param array{order_id:int,carrier:string,carrier_name:string,tracking_number:string,tracking_url:string,items:?array<int,int>,shipped_at:string,created_at:string} $row
	 */
	public function insert( array $row ): int {
		$ok = $this->wpdb->insert(
			Schema::table( 'shipments' ),
			[
				'order_id'        => $row['order_id'],
				'carrier'         => $row['carrier'],
				'carrier_name'    => $row['carrier_name'],
				'tracking_number' => $row['tracking_number'],
				'tracking_url'    => $row['tracking_url'],
				'items'           => $row['items'] === null ? null : (string) wp_json_encode( (object) $row['items'] ),
				'shipped_at'      => $row['shipped_at'],
				'created_at'      => $row['created_at'],
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	public function delete( int $id ): bool {
		return (int) $this->wpdb->delete( Schema::table( 'shipments' ), [ 'id' => $id ], [ '%d' ] ) > 0;
	}
}
