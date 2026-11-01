<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices;

use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The invoice number and date of an order, and the credit notes of its
 * refunds (one `_fxc_credit_note` row each, ADR-064), in fxc_order_meta (ADR-060).
 * fxc_order_meta has no unique key, so creating an invoice holds a named
 * lock for the order: two requests at once (an email and the admin) still
 * give the order one number.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- order meta and a named lock; values always prepared.
final class InvoiceRepository {
	public const NUMBER = '_fxc_invoice_number';

	public const DATE = '_fxc_invoice_date';

	public const CREDIT_NOTE = '_fxc_credit_note';

	private const LOCK_WAIT = 10;

	public function __construct( private readonly \wpdb $wpdb ) {}

	public function find( int $order_id ): ?Invoice {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i WHERE order_id = %d AND meta_key IN (%s, %s)',
				Schema::table( 'order_meta' ),
				$order_id,
				self::NUMBER,
				self::DATE
			),
			ARRAY_A
		);
		return self::from_rows( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Invoices of several orders with one query, by order id.
	 *
	 * @param list<int> $order_ids
	 * @return array<int,Invoice>
	 */
	public function find_many( array $order_ids ): array {
		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( $order_ids === [] ) {
			return [];
		}
		$rows     = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT order_id, meta_key, meta_value FROM %i WHERE meta_key IN (%s, %s) AND order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')',
				Schema::table( 'order_meta' ),
				self::NUMBER,
				self::DATE,
				...$order_ids
			),
			ARRAY_A
		);
		$by_order = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$by_order[ (int) $row['order_id'] ][] = $row;
		}
		$out = [];
		foreach ( $by_order as $order_id => $order_rows ) {
			$invoice = self::from_rows( $order_rows );
			if ( $invoice !== null ) {
				$out[ $order_id ] = $invoice;
			}
		}
		return $out;
	}

	public function save( int $order_id, Invoice $invoice ): void {
		$table = Schema::table( 'order_meta' );
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d AND meta_key IN (%s, %s)', $table, $order_id, self::NUMBER, self::DATE ) );
		$this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (order_id, meta_key, meta_value) VALUES (%d, %s, %s), (%d, %s, %s)',
				$table,
				$order_id,
				self::NUMBER,
				$invoice->number,
				$order_id,
				self::DATE,
				$invoice->date
			)
		);
	}

	/**
	 * The credit notes of the order's refunds, by refund id.
	 *
	 * @return array<int,CreditNote>
	 */
	public function credit_notes( int $order_id ): array {
		$values = $this->wpdb->get_col(
			$this->wpdb->prepare( 'SELECT meta_value FROM %i WHERE order_id = %d AND meta_key = %s ORDER BY id', Schema::table( 'order_meta' ), $order_id, self::CREDIT_NOTE )
		);
		$out    = [];
		foreach ( $values as $value ) {
			$data = json_decode( (string) $value, true );
			if ( is_array( $data ) && (int) ( $data['refund_id'] ?? 0 ) > 0 && is_string( $data['number'] ?? null ) && $data['number'] !== '' ) {
				$note                      = new CreditNote( (int) $data['refund_id'], $data['number'], is_string( $data['date'] ?? null ) ? $data['date'] : '' );
				$out[ $note->refund_id ] ??= $note;
			}
		}
		return $out;
	}

	public function save_credit_note( int $order_id, CreditNote $note ): void {
		$this->wpdb->insert(
			Schema::table( 'order_meta' ),
			[
				'order_id'   => $order_id,
				'meta_key'   => self::CREDIT_NOTE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- plugin table with an order_id key.
				'meta_value' => (string) wp_json_encode( $note->to_array() ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			],
			[ '%d', '%s', '%s' ]
		);
	}

	/** True when the lock was taken within the wait. */
	public function lock( int $order_id ): bool {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name( $order_id ), self::LOCK_WAIT ) ) === 1;
	}

	public function unlock( int $order_id ): void {
		$this->wpdb->query( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $order_id ) ) );
	}

	/** Named locks are server wide, so the name carries the table prefix. */
	private function lock_name( int $order_id ): string {
		return substr( $this->wpdb->prefix . 'fxc_invoice_' . $order_id, 0, 64 );
	}

	/** @param array<int,array<string,mixed>> $rows */
	private static function from_rows( array $rows ): ?Invoice {
		$values = [];
		foreach ( $rows as $row ) {
			$values[ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}
		if ( ( $values[ self::NUMBER ] ?? '' ) === '' ) {
			return null;
		}
		return new Invoice( $values[ self::NUMBER ], $values[ self::DATE ] ?? '' );
	}
}
