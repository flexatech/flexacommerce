<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * fxc_webhook_events: one row per gateway event id, so a redelivered event
 * is found by its unique key (doc 02 §7.8).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared, dedupe rows are never cached.
final class WebhookEventRepository {
	public function __construct( private readonly \wpdb $wpdb ) {}

	/** Returns the new row id, or 0 when this event was seen before. */
	public function claim( string $gateway, string $event_id, string $event_type, string $now ): int {
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT IGNORE INTO %i (gateway, event_id, event_type, result, received_at) VALUES (%s, %s, %s, %s, %s)',
				Schema::table( 'webhook_events' ),
				$gateway,
				$event_id,
				substr( $event_type, 0, 100 ),
				'received',
				$now
			)
		);
		if ( $affected === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $affected === 1 ? (int) $this->wpdb->insert_id : 0;
	}

	public function finish( int $id, string $result, ?int $transaction_id, string $now ): void {
		$ok = $this->wpdb->update(
			Schema::table( 'webhook_events' ),
			[
				'result'         => substr( $result, 0, 20 ),
				'transaction_id' => $transaction_id,
				'processed_at'   => $now,
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s' ]
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/** @return array{gateway:string,event_id:string,result:string,transaction_id:?int}|null */
	public function find( string $gateway, string $event_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE gateway = %s AND event_id = %s', Schema::table( 'webhook_events' ), $gateway, $event_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return [
			'gateway'        => (string) $row['gateway'],
			'event_id'       => (string) $row['event_id'],
			'result'         => (string) $row['result'],
			'transaction_id' => isset( $row['transaction_id'] ) ? (int) $row['transaction_id'] : null,
		];
	}

	/** Delete rows received before `$before`, in batches; returns the count. */
	public function purge( string $before, int $limit = 1000 ): int {
		$affected = $this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s ORDER BY id LIMIT %d', Schema::table( 'webhook_events' ), $before, $limit )
		);
		if ( $affected === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $affected;
	}
}
