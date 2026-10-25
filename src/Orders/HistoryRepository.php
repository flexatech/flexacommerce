<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Storage for fxc_order_history: append-only. */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared, append-only log.
final class HistoryRepository {
	public const TYPES = [ 'status', 'note', 'payment', 'refund', 'stock', 'email', 'system', 'import' ];

	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	public function __construct( private readonly \wpdb $wpdb ) {}

	/** @param array<string,mixed> $context */
	public function add(
		int $order_id,
		string $type,
		string $message,
		string $now,
		string $actor_type = 'system',
		int $actor_id = 0,
		bool $customer_visible = false,
		?string $from_status = null,
		?string $to_status = null,
		array $context = [],
	): int {
		$ok = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (order_id, type, from_status, to_status, message, customer_visible, actor_type, actor_id, context, created_at) VALUES (%d, %s, NULLIF(%s, %s), NULLIF(%s, %s), %s, %d, %s, NULLIF(%d, 0), NULLIF(%s, %s), %s)',
				Schema::table( 'order_history' ),
				$order_id,
				$type,
				(string) $from_status,
				'',
				(string) $to_status,
				'',
				$message,
				$customer_visible ? 1 : 0,
				$actor_type,
				$actor_id,
				$context === [] ? '' : (string) wp_json_encode( $context, self::JSON_FLAGS ),
				'',
				$now
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @return list<array{id:int,type:string,from_status:?string,to_status:?string,message:string,customer_visible:bool,actor_type:string,actor_id:?int,context:array<string,mixed>,created_at:string}>
	 */
	public function for_order( int $order_id, bool $customer_visible_only = false ): array {
		$sql  = 'SELECT * FROM %i WHERE order_id = %d' . ( $customer_visible_only ? ' AND customer_visible = 1' : '' ) . ' ORDER BY id DESC';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, Schema::table( 'order_history' ), $order_id ), ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$context = json_decode( (string) ( $row['context'] ?? '' ), true );
			$out[]   = [
				'id'               => (int) $row['id'],
				'type'             => (string) $row['type'],
				'from_status'      => isset( $row['from_status'] ) ? (string) $row['from_status'] : null,
				'to_status'        => isset( $row['to_status'] ) ? (string) $row['to_status'] : null,
				'message'          => (string) ( $row['message'] ?? '' ),
				'customer_visible' => (bool) (int) $row['customer_visible'],
				'actor_type'       => (string) $row['actor_type'],
				'actor_id'         => isset( $row['actor_id'] ) ? (int) $row['actor_id'] : null,
				'context'          => is_array( $context ) ? $context : [],
				'created_at'       => (string) $row['created_at'],
			];
		}
		return $out;
	}
}
