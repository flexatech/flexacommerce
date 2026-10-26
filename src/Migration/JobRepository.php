<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Canonical\Problem;

defined( 'ABSPATH' ) || exit;

/** Storage for fxc_migration_jobs and fxc_migration_log. */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared, job rows change every entity.
final class JobRepository {
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	/** @var array<int,string> job id => direction */
	private array $directions = [];

	public function __construct( private readonly \wpdb $wpdb ) {}

	/** @param array<string,mixed> $options */
	public function create( string $direction, bool $dry_run, array $options, int $user_id, string $now ): int {
		$ok = $this->wpdb->insert(
			Schema::table( 'migration_jobs' ),
			[
				'direction'      => $direction,
				'status'         => JobStatus::DRAFT,
				'dry_run'        => $dry_run ? 1 : 0,
				'format_version' => Canonical\Record::FORMAT_VERSION,
				'options'        => wp_json_encode( Job::options( $options ), self::JSON_FLAGS ),
				'stage'          => '',
				'created_by'     => $user_id,
				'created_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		if ( $ok === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return (int) $this->wpdb->insert_id;
	}

	public function find( int $id ): ?Job {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'migration_jobs' ), $id ), ARRAY_A );
		return is_array( $row ) ? Job::from_row( $row ) : null;
	}

	/** @return list<Job> newest first */
	public function recent( int $limit = 20 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Schema::table( 'migration_jobs' ), $limit ),
			ARRAY_A
		);
		return array_map( static fn ( array $row ): Job => Job::from_row( $row ), $rows ?: [] );
	}

	/** Direction of the job that last wrote an id map row, cached per request. */
	public function direction_of( int $job_id ): ?string {
		if ( $job_id <= 0 ) {
			return null;
		}
		if ( ! isset( $this->directions[ $job_id ] ) ) {
			$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT direction FROM %i WHERE id = %d', Schema::table( 'migration_jobs' ), $job_id ) );
			if ( ! is_string( $value ) ) {
				return null;
			}
			$this->directions[ $job_id ] = $value;
		}
		return $this->directions[ $job_id ];
	}

	/** @param array<string,mixed> $fields column => value; arrays are stored as JSON */
	public function update( int $id, array $fields, string $now ): void {
		$fields['updated_at'] = $now;
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$fields[ $key ] = wp_json_encode( $value === [] ? new \stdClass() : $value, self::JSON_FLAGS );
			} elseif ( is_bool( $value ) ) {
				$fields[ $key ] = $value ? 1 : 0;
			}
		}
		if ( $this->wpdb->update( Schema::table( 'migration_jobs' ), $fields, [ 'id' => $id ] ) === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/**
	 * Move the job when its current status is `$from`. False when another
	 * request moved it first.
	 *
	 * @param list<string>        $from
	 * @param array<string,mixed> $fields
	 */
	public function transition( int $id, array $from, string $to, string $now, array $fields = [] ): bool {
		$set  = [ 'status = %s', 'updated_at = %s' ];
		$args = [ $to, $now ];
		foreach ( $fields as $column => $value ) {
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value === [] ? new \stdClass() : $value, self::JSON_FLAGS );
			}
			if ( $value === null ) {
				$set[] = sanitize_key( $column ) . ' = NULL';
				continue;
			}
			$set[]  = sanitize_key( $column ) . ' = %s';
			$args[] = (string) $value;
		}
		$in  = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		$sql = 'UPDATE %i SET ' . implode( ', ', $set ) . " WHERE id = %d AND status IN ({$in})";
		$n   = $this->wpdb->query( $this->wpdb->prepare( $sql, Schema::table( 'migration_jobs' ), ...[ ...$args, $id, ...$from ] ) );
		if ( $n === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return $n > 0;
	}

	/**
	 * Take or extend the lease (doc 05 §4.4). An expired lease can be taken
	 * over, which is how a crashed batch is recovered.
	 */
	public function lease( int $id, string $token, string $now, string $until ): bool {
		$n = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET lock_token = %s, locked_until = %s WHERE id = %d AND ( lock_token IS NULL OR lock_token = %s OR locked_until IS NULL OR locked_until < %s )',
				Schema::table( 'migration_jobs' ),
				$token,
				$until,
				$id,
				$token,
				$now
			)
		);
		if ( $n === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		if ( $n > 0 ) {
			return true;
		}
		// Same token and same second: MySQL reports zero changed rows.
		$held = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT lock_token FROM %i WHERE id = %d', Schema::table( 'migration_jobs' ), $id ) );
		return $held === $token;
	}

	public function release( int $id, string $token ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET lock_token = NULL, locked_until = NULL WHERE id = %d AND lock_token = %s',
				Schema::table( 'migration_jobs' ),
				$id,
				$token
			)
		);
	}

	/**
	 * Cursor and counters after every entity.
	 *
	 * @param array<string,mixed> $cursor
	 * @param array<string,mixed> $counters
	 */
	public function save_progress( int $id, string $stage, array $cursor, array $counters, string $now ): void {
		$this->update(
			$id,
			[
				'stage'        => $stage,
				'cursor_state' => $cursor,
				'counters'     => $counters,
			],
			$now
		);
	}

	public function log( int $job_id, string $entity, string $source_id, Problem $problem, string $now ): void {
		$this->wpdb->insert(
			Schema::table( 'migration_log' ),
			[
				'job_id'     => $job_id,
				'entity'     => substr( $entity, 0, 32 ),
				'source_id'  => substr( $source_id, 0, 64 ),
				'level'      => $problem->level,
				'code'       => substr( $problem->code, 0, 64 ),
				'message'    => $problem->message,
				'context'    => $problem->context === [] ? null : wp_json_encode( $problem->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ),
				'created_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * @return array{rows:list<array<string,mixed>>,total:int}
	 */
	public function log_page( int $job_id, string $level = '', int $page = 1, int $per_page = 100 ): array {
		$table = Schema::table( 'migration_log' );
		$where = $level !== '' ? $this->wpdb->prepare( 'job_id = %d AND level = %s', $job_id, $level ) : $this->wpdb->prepare( 'job_id = %d', $job_id );
		$total = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id LIMIT %d OFFSET %d", $table, $per_page, max( 0, $page - 1 ) * $per_page ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
			ARRAY_A
		);
		$out   = [];
		foreach ( $rows ?: [] as $row ) {
			$context        = is_string( $row['context'] ) ? json_decode( $row['context'], true ) : null;
			$row['id']      = (int) $row['id'];
			$row['job_id']  = (int) $row['job_id'];
			$row['context'] = is_array( $context ) ? $context : [];
			$out[]          = $row;
		}
		return [
			'rows'  => $out,
			'total' => $total,
		];
	}

	/** @return array<string,int> level => rows */
	public function log_counts( int $job_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT level, COUNT(*) AS n FROM %i WHERE job_id = %d GROUP BY level', Schema::table( 'migration_log' ), $job_id ),
			ARRAY_A
		);
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['level'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** @return list<string> source ids with an error in this job, for one entity */
	public function rejected( int $job_id, string $entity ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT source_id FROM %i WHERE job_id = %d AND entity = %s AND level = %s',
				Schema::table( 'migration_log' ),
				$job_id,
				$entity,
				Problem::ERROR
			)
		);
		return array_map( 'strval', $ids ?: [] );
	}

	public function delete( int $id ): void {
		$this->wpdb->delete( Schema::table( 'migration_log' ), [ 'job_id' => $id ], [ '%d' ] );
		$this->wpdb->delete( Schema::table( 'migration_jobs' ), [ 'id' => $id ], [ '%d' ] );
	}
}
