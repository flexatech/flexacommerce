<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Storage for fxc_transactions. Writes run inside the caller's transaction. */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared, transactions are not object-cached.
final class TransactionRepository {
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find( int $id, bool $lock = false ): ?Transaction {
		$sql = 'SELECT * FROM %i WHERE id = %d' . ( $lock ? ' FOR UPDATE' : '' );
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, Schema::table( 'transactions' ), $id ), ARRAY_A );
		return is_array( $row ) ? Transaction::from_row( $row ) : null;
	}

	public function find_by_reference( string $gateway, string $reference, bool $lock = false ): ?Transaction {
		$sql = 'SELECT * FROM %i WHERE gateway = %s AND gateway_reference = %s' . ( $lock ? ' FOR UPDATE' : '' );
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, Schema::table( 'transactions' ), $gateway, $reference ), ARRAY_A );
		return is_array( $row ) ? Transaction::from_row( $row ) : null;
	}

	/** @return list<Transaction> oldest first */
	public function for_order( int $order_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'transactions' ), $order_id ),
			ARRAY_A
		);
		return array_map( static fn ( array $row ): Transaction => Transaction::from_row( $row ), $rows ?: [] );
	}

	/**
	 * @param list<array{item_id:int,qty:int,amount:int,restock:bool}> $refund_lines
	 * @param array<string,mixed>                                       $data already redacted
	 */
	public function insert(
		int $order_id,
		string $type,
		string $status,
		string $method,
		string $gateway,
		int $amount,
		string $currency,
		string $idempotency_key,
		string $now,
		?int $parent_id = null,
		?string $gateway_reference = null,
		array $refund_lines = [],
		array $data = [],
	): int {
		$ok = $this->wpdb->insert(
			Schema::table( 'transactions' ),
			[
				'order_id'          => $order_id,
				'parent_id'         => $parent_id,
				'type'              => $type,
				'status'            => $status,
				'method'            => $method,
				'gateway'           => $gateway,
				'amount'            => $amount,
				'currency'          => $currency,
				'idempotency_key'   => $idempotency_key,
				'gateway_reference' => $gateway_reference,
				'refund_lines'      => $refund_lines === [] ? null : wp_json_encode( $refund_lines, self::JSON_FLAGS ),
				'data'              => $data === [] ? null : wp_json_encode( $data, self::JSON_FLAGS ),
				'created_at'        => $now,
				'updated_at'        => $now,
				'completed_at'      => in_array( $status, Transaction::TERMINAL, true ) ? $now : null,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Status change plus the fields that come with it. A terminal status
	 * stamps completed_at.
	 *
	 * @param array<string,mixed> $data merged over the stored data, already redacted
	 */
	public function update_status( int $id, string $status, string $now, ?string $reference = null, string $failure_code = '', string $failure_message = '', array $data = [] ): void {
		$fields = [
			'status'     => $status,
			'updated_at' => $now,
		];
		if ( in_array( $status, Transaction::TERMINAL, true ) ) {
			$fields['completed_at'] = $now;
		}
		if ( $reference !== null && $reference !== '' ) {
			$fields['gateway_reference'] = substr( $reference, 0, 191 );
		}
		if ( $failure_code !== '' ) {
			$fields['failure_code']    = substr( $failure_code, 0, 64 );
			$fields['failure_message'] = substr( $failure_message, 0, 255 );
		}
		if ( $data !== [] ) {
			$current        = $this->find( $id );
			$fields['data'] = wp_json_encode( $data + ( $current !== null ? $current->data : [] ), self::JSON_FLAGS );
		}
		$this->update( $id, $fields );
	}

	public function set_reference( int $id, string $reference, string $now ): void {
		$this->update(
			$id,
			[
				'gateway_reference' => substr( $reference, 0, 191 ),
				'updated_at'        => $now,
			]
		);
	}

	/** @param array<string,mixed> $fields */
	private function update( int $id, array $fields ): void {
		if ( $this->wpdb->update( Schema::table( 'transactions' ), $fields, [ 'id' => $id ] ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}
}
