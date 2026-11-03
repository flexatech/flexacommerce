<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxcm_id_map (doc 05 §4.2), scoped to one remote system: 'wc'
 * for the WooCommerce store on this site, or the key of the site a JSONL
 * file came from (doc 05 §4.6).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared, rows change on every imported entity.
final class IdMapRepository {
	public const SYSTEM = 'wc';

	public function __construct( private readonly \wpdb $wpdb, private readonly string $system = self::SYSTEM ) {}

	public function for_system( string $system ): self {
		$wpdb = $this->wpdb;
		return $system === $this->system ? $this : new self( $wpdb, $system );
	}

	public function system(): string {
		return $this->system;
	}

	public function by_remote( string $entity, string $remote_id ): ?IdMapRow {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE remote_system = %s AND entity = %s AND remote_id = %s', Schema::table( 'id_map' ), $this->system, $entity, $remote_id ),
			ARRAY_A
		);
		return is_array( $row ) ? IdMapRow::from_row( $row ) : null;
	}

	public function by_local( string $entity, int $local_id ): ?IdMapRow {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE remote_system = %s AND entity = %s AND local_id = %d', Schema::table( 'id_map' ), $this->system, $entity, $local_id ),
			ARRAY_A
		);
		return is_array( $row ) ? IdMapRow::from_row( $row ) : null;
	}

	public function insert( string $entity, string $remote_id, int $local_id, string $origin, string $checksum, ?int $job_id, string $now ): int {
		$wpdb = $this->wpdb;
		$ok   = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (remote_system, entity, remote_id, local_id, origin, checksum, job_id, created_at, updated_at) VALUES (%s, %s, %s, %d, %s, %s, %d, %s, %s)',
				Schema::table( 'id_map' ),
				$this->system,
				$entity,
				$remote_id,
				$local_id,
				$origin,
				$checksum,
				(int) $job_id,
				$now,
				$now
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, string $remote_id, int $local_id, string $checksum, ?int $job_id, string $now ): void {
		$wpdb = $this->wpdb;
		$ok   = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET remote_id = %s, local_id = %d, checksum = %s, job_id = %d, updated_at = %s WHERE id = %d',
				Schema::table( 'id_map' ),
				$remote_id,
				$local_id,
				$checksum,
				(int) $job_id,
				$now,
				$id
			)
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
	}

	public function delete( int $id ): void {
		$wpdb = $this->wpdb;
		$wpdb->delete( Schema::table( 'id_map' ), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Rows a job created on its target side (origin = its source system), for purge.
	 *
	 * @return list<IdMapRow>
	 */
	public function created_by( int $job_id, string $origin, string $entity ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE remote_system = %s AND job_id = %d AND origin = %s AND entity = %s ORDER BY id DESC',
				Schema::table( 'id_map' ),
				$this->system,
				$job_id,
				$origin,
				$entity
			),
			ARRAY_A
		);
		return array_map( static fn ( array $row ): IdMapRow => IdMapRow::from_row( $row ), $rows ?: [] );
	}

	/** @return list<IdMapRow> every row of one entity, for the redirect map and verification */
	public function all( string $entity, int $after = 0, int $limit = 1000 ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE remote_system = %s AND entity = %s AND id > %d ORDER BY id LIMIT %d',
				Schema::table( 'id_map' ),
				$this->system,
				$entity,
				$after,
				$limit
			),
			ARRAY_A
		);
		return array_map( static fn ( array $row ): IdMapRow => IdMapRow::from_row( $row ), $rows ?: [] );
	}

	public function count( string $entity ): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE remote_system = %s AND entity = %s', Schema::table( 'id_map' ), $this->system, $entity )
		);
	}
}
