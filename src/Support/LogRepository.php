<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxc_logs. Rows are already redacted when they get here.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared.
final class LogRepository {
	public const PURGE_BATCH = 1000;

	public function __construct( private readonly \wpdb $wpdb ) {}

	public function insert( string $level, string $channel, string $message, ?string $context, string $created_at ): bool {
		$inserted = $this->wpdb->insert(
			Schema::table( 'logs' ),
			[
				'level'      => $level,
				'channel'    => substr( $channel, 0, 64 ),
				'message'    => $message,
				'context'    => $context,
				'created_at' => $created_at,
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
		return $inserted !== false;
	}

	/**
	 * @param array{level?:string,channel?:string,search?:string} $filters
	 * @return array{items:list<array{id:int,level:string,channel:string,message:string,context:mixed,created_at:string}>,total:int}
	 */
	public function list( array $filters, int $page = 1, int $per_page = 50 ): array {
		$where = [ '1=1' ];
		$args  = [ Schema::table( 'logs' ) ];
		if ( ( $filters['level'] ?? '' ) !== '' ) {
			$where[] = 'level = %s';
			$args[]  = $filters['level'];
		}
		if ( ( $filters['channel'] ?? '' ) !== '' ) {
			$where[] = 'channel = %s';
			$args[]  = $filters['channel'];
		}
		if ( ( $filters['search'] ?? '' ) !== '' ) {
			$where[] = 'message LIKE %s';
			$args[]  = '%' . $this->wpdb->esc_like( (string) $filters['search'] ) . '%';
		}
		$sql = 'FROM %i WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from fixed fragments above.
		$total = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) {$sql}", ...$args ) );

		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$rows     = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- as above.
			$this->wpdb->prepare( "SELECT * {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...[ ...$args, $per_page, $offset ] ),
			ARRAY_A
		);

		$items = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$context = $row['context'] !== null ? json_decode( (string) $row['context'], true ) : null;
			$items[] = [
				'id'         => (int) $row['id'],
				'level'      => (string) $row['level'],
				'channel'    => (string) $row['channel'],
				'message'    => (string) $row['message'],
				'context'    => $context,
				'created_at' => (string) $row['created_at'],
			];
		}
		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/** @return list<string> */
	public function channels(): array {
		$rows = $this->wpdb->get_col(
			$this->wpdb->prepare( 'SELECT DISTINCT channel FROM %i ORDER BY channel', Schema::table( 'logs' ) )
		);
		return array_values( array_map( 'strval', $rows ) );
	}

	/** Deletes at most one batch so a cron run stays short; returns rows deleted. */
	public function purge_older_than( string $cutoff, int $limit = self::PURGE_BATCH ): int {
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s ORDER BY id LIMIT %d',
				Schema::table( 'logs' ),
				$cutoff,
				$limit
			)
		);
		return (int) $deleted;
	}

	public function clear(): int {
		return (int) $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i', Schema::table( 'logs' ) ) );
	}
}
