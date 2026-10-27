<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Sources\SourceAdapter;
use Flexa\Commerce\Migration\Targets\TargetAdapter;
use Flexa\Commerce\Migration\Transport\FileExport;
use Flexa\Commerce\Migration\Transport\FileIndex;
use Flexa\Commerce\Migration\Transport\Uploads;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs from the admin screen, REST and WP-CLI (doc 05 §4.5): create with a
 * preflight, start, pause, cancel, run batches, verify again, purge. Every
 * status change goes through JobRepository::transition so two requests
 * cannot move a job twice.
 */
final class MigrationService {
	/** Entities purge removes, children before parents (doc 05 §9.3). */
	private const PURGE_ORDER = [ Record::OPTION_SET, Record::COUPON, Record::ORDER, Record::PRODUCT, Record::TERM, Record::ATTRIBUTE ];

	/**
	 * @param \Closure(Job):array{0:SourceAdapter,1:TargetAdapter} $adapters
	 */
	public function __construct(
		private readonly JobRepository $jobs,
		private readonly IdMapRepository $ids,
		private readonly Runner $runner,
		private readonly Clock $clock,
		private readonly \Closure $adapters,
	) {}

	/**
	 * A draft job with its preflight report; it moves to ready when the
	 * preflight found no error.
	 *
	 * @param array<string,mixed> $options
	 */
	public function create( string $direction, array $options, bool $dry_run, int $user_id ): Job {
		// A file path only comes from create_from_file(), never from a REST body.
		unset( $options['file'], $options['file_name'], $options['id_system'] );
		return $this->insert( $direction, $options, $dry_run, $user_id );
	}

	/**
	 * A job that imports a JSONL export from another site (doc 05 §4.6). The
	 * file's header sets the direction; the index is built here, so a broken
	 * file fails before the job exists.
	 *
	 * @param array<string,mixed> $options
	 */
	public function create_from_file( string $path, array $options, bool $dry_run, int $user_id ): Job {
		$real = realpath( $path );
		if ( $real === false ) {
			throw new MigrationError( 'file_missing', sprintf( 'The file %s does not exist.', $path ) );
		}
		$index                = FileIndex::build( $real );
		$options['file']      = $real;
		$options['id_system'] = $index->id_system();
		return $this->insert( FileExport::direction( $index->system() ), $options, $dry_run, $user_id );
	}

	/** @param array<string,mixed> $options */
	private function insert( string $direction, array $options, bool $dry_run, int $user_id ): Job {
		if ( ! in_array( $direction, Direction::ALL, true ) ) {
			throw new MigrationError( 'invalid_direction', sprintf( 'Unknown direction "%s".', $direction ) );
		}
		$id  = $this->jobs->create( $direction, $dry_run, $options, $user_id, $this->clock->db_now() );
		$job = $this->find( $id );
		return $this->check( $job );
	}

	/** Run the preflight again, for a draft that was blocked or a ready job before it starts. */
	public function recheck( int $job_id ): Job {
		$job = $this->find( $job_id );
		if ( ! in_array( $job->status, [ JobStatus::DRAFT, JobStatus::READY ], true ) ) {
			throw new MigrationError( 'invalid_status', 'Only a job that has not started can be checked again.' );
		}
		return $this->check( $job );
	}

	/** @return list<Problem> */
	public function preflight( Job $job ): array {
		try {
			[ $source, $target ] = ( $this->adapters )( $job );
		} catch ( MigrationError $e ) {
			return [ Problem::error( $e->reason, $e->getMessage() ) ];
		}
		$problems = $source->preflight( $job );
		foreach ( $problems as $problem ) {
			if ( $problem->level === Problem::ERROR ) {
				return $problems;
			}
		}
		try {
			$settings = $source->read( 'settings', 1, $job );
		} catch ( Rejected ) {
			$settings = null;
		}
		$inventory = [];
		foreach ( $job->stages() as $stage ) {
			$inventory[ $stage ] = $source->count( $stage, $job );
		}
		return [
			...$problems,
			...$target->preflight( $job, $settings ),
			Problem::info( 'inventory', 'Entities to read per stage.', [ 'counts' => $inventory ] ),
			...self::environment( $inventory ),
		];
	}

	/**
	 * Start a ready job, resume a paused or failed one, or start an
	 * incremental re-run of a finished one (cursor and counters reset, the id
	 * map keeps what was imported).
	 */
	public function start( int $job_id ): Job {
		$job = $this->find( $job_id );
		if ( ! $job->dry_run && ! $job->option( 'backup_confirmed' ) ) {
			throw new MigrationError( 'backup_required', 'Confirm that you have a current database backup before a real run.' );
		}
		if ( $job->is_file() && ! is_file( (string) $job->option( 'file' ) ) ) {
			throw new MigrationError( 'file_missing', 'The export file of this job has been deleted. Upload it again in a new job.' );
		}
		$now    = $this->clock->db_now();
		$fields = [];
		if ( $job->started_at === null ) {
			$fields['started_at'] = $now;
		}
		if ( in_array( $job->status, JobStatus::FINISHED, true ) ) {
			$fields += [
				'cursor_state' => [],
				'counters'     => [],
				'stage'        => '',
				'finished_at'  => null,
			];
		}
		if ( ! JobStatus::can_move( $job->status, JobStatus::RUNNING ) || ! $this->jobs->transition( $job->id, [ $job->status ], JobStatus::RUNNING, $now, $fields ) ) {
			throw new MigrationError( 'invalid_status', sprintf( 'A %s job cannot start.', $job->status ) );
		}
		return $this->find( $job->id );
	}

	/** Record the backup confirmation a real run needs (doc 05 §4.5 step 3). */
	public function confirm_backup( int $job_id ): Job {
		$job = $this->find( $job_id );
		if ( ! $job->option( 'backup_confirmed' ) ) {
			$this->jobs->update( $job->id, [ 'options' => [ 'backup_confirmed' => true ] + $job->options ], $this->clock->db_now() );
		}
		return $this->find( $job->id );
	}

	public function pause( int $job_id ): Job {
		return $this->move( $job_id, [ JobStatus::RUNNING ], JobStatus::PAUSED );
	}

	public function cancel( int $job_id ): Job {
		return $this->move( $job_id, [ JobStatus::DRAFT, JobStatus::READY, JobStatus::RUNNING, JobStatus::PAUSED, JobStatus::FAILED ], JobStatus::CANCELLED );
	}

	/**
	 * Deletes the uploaded export file of a job that is not running. The job
	 * and its log stay; it can no longer start or verify again.
	 */
	public function remove_file( int $job_id ): Job {
		$job = $this->find( $job_id );
		if ( ! $job->is_file() || ! Uploads::owns( (string) $job->option( 'file' ) ) ) {
			throw new MigrationError( 'invalid_file', 'Only a file uploaded to the Migration screen can be deleted here.' );
		}
		if ( in_array( $job->status, [ JobStatus::RUNNING, JobStatus::VERIFYING ], true ) ) {
			throw new MigrationError( 'invalid_status', 'Pause or cancel the job before deleting its file.' );
		}
		if ( ! Uploads::remove( (string) $job->option( 'file' ) ) ) {
			throw new MigrationError( 'file_unwritable', 'Could not delete the file.' );
		}
		return $job;
	}

	/** @return array{job:?Job,processed:int,done:bool,locked:bool,peak:int,growth:int} */
	public function run_batch( int $job_id ): array {
		return $this->runner->run_batch( $job_id );
	}

	/**
	 * Batches until the job leaves running and verifying (WP-CLI). Stops on a
	 * lease held by another runner.
	 *
	 * @param (callable(array{job:?Job,processed:int,done:bool,locked:bool,peak:int,growth:int}):void)|null $progress after every batch
	 */
	public function run( int $job_id, ?callable $progress = null ): Job {
		do {
			$result = $this->runner->run_batch( $job_id );
			if ( $progress !== null ) {
				$progress( $result );
			}
		} while ( ! $result['done'] && ! $result['locked'] );
		return $this->find( $job_id );
	}

	/** Build the verification report again for a finished job (doc 05 §9.1). */
	public function verify( int $job_id ): Job {
		$job = $this->find( $job_id );
		if ( ! in_array( $job->status, JobStatus::FINISHED, true ) || ! $this->jobs->transition( $job->id, JobStatus::FINISHED, JobStatus::VERIFYING, $this->clock->db_now() ) ) {
			throw new MigrationError( 'invalid_status', 'Only a finished job can be verified again.' );
		}
		return $this->run( $job->id );
	}

	/**
	 * Delete what a job created on its target, leaving entities that changed
	 * after the import (doc 05 §9.3). Settings, tax rates and customers stay.
	 *
	 * @return array<string,array{deleted:int,kept:int}>
	 */
	public function purge( int $job_id ): array {
		$job = $this->find( $job_id );
		if ( $job->dry_run || in_array( $job->status, [ JobStatus::RUNNING, JobStatus::VERIFYING ], true ) ) {
			throw new MigrationError( 'invalid_status', 'A dry run or a job that is still running cannot be purged.' );
		}
		[ , $target ] = ( $this->adapters )( $job );
		$ids          = $this->ids->for_system( $job->id_system() );
		$out          = [];
		return ImportGuard::run(
			function () use ( $job, $target, $ids, $out ): array {
				foreach ( self::PURGE_ORDER as $entity ) {
					$deleted = 0;
					$kept    = 0;
					foreach ( $ids->created_by( $job->id, $job->source(), $entity ) as $row ) {
						$target_id = (int) $row->id_on( $job->target() );
						$modified  = $target->modified_at( $entity, $target_id );
						if ( $modified !== null && $modified > $row->updated_at ) {
							++$kept;
							continue;
						}
						$target->delete( $entity, $target_id );
						$ids->delete( $row->id );
						++$deleted;
					}
					$out[ $entity ] = [
						'deleted' => $deleted,
						'kept'    => $kept,
					];
				}
				if ( $out[ Record::PRODUCT ]['kept'] === 0 ) {
					foreach ( $ids->created_by( $job->id, $job->source(), 'variant' ) as $row ) {
						$ids->delete( $row->id );
					}
				}
				return $out;
			}
		);
	}

	public function find( int $job_id ): Job {
		return $this->jobs->find( $job_id ) ?? throw new MigrationError( 'not_found', sprintf( 'Migration job %d does not exist.', $job_id ) );
	}

	/** @return list<Job> */
	public function recent( int $limit = 20 ): array {
		return $this->jobs->recent( $limit );
	}

	/** @return array{rows:list<array<string,mixed>>,total:int} */
	public function log( int $job_id, string $level = '', int $page = 1, int $per_page = 100 ): array {
		return $this->jobs->log_page( $job_id, $level, $page, $per_page );
	}

	private function check( Job $job ): Job {
		$problems = $this->preflight( $job );
		$errors   = array_filter( $problems, static fn ( Problem $p ): bool => $p->level === Problem::ERROR );
		$report   = array_merge(
			$job->report,
			[
				'preflight'  => array_map( [ self::class, 'problem' ], $problems ),
				'checked_at' => $this->clock->db_now(),
			]
		);
		$now      = $this->clock->db_now();
		$this->jobs->update( $job->id, [ 'report' => $report ], $now );
		if ( $errors === [] && $job->status === JobStatus::DRAFT ) {
			$this->jobs->transition( $job->id, [ JobStatus::DRAFT ], JobStatus::READY, $now );
		} elseif ( $errors !== [] && $job->status === JobStatus::READY ) {
			$this->jobs->transition( $job->id, [ JobStatus::READY ], JobStatus::DRAFT, $now );
		}
		return $this->find( $job->id );
	}

	/** @param list<string> $from */
	private function move( int $job_id, array $from, string $to ): Job {
		$job = $this->find( $job_id );
		if ( ! in_array( $job->status, $from, true ) || ! $this->jobs->transition( $job->id, $from, $to, $this->clock->db_now() ) ) {
			throw new MigrationError( 'invalid_status', sprintf( 'A %1$s job cannot become %2$s.', $job->status, $to ) );
		}
		return $this->find( $job->id );
	}

	/** @return array{level:string,code:string,message:string,context:array<string,mixed>} */
	public static function problem( Problem $problem ): array {
		return [
			'level'   => $problem->level,
			'code'    => $problem->code,
			'message' => $problem->message,
			'context' => $problem->context,
		];
	}

	/**
	 * Server limits that decide which driver fits (doc 05 §5.5).
	 *
	 * @param array<string,int> $inventory
	 * @return list<Problem>
	 */
	private static function environment( array $inventory ): array {
		$problems   = [];
		$memory     = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$time       = (int) ini_get( 'max_execution_time' );
		$cron       = defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' );
		$problems[] = Problem::info(
			'environment',
			'Server limits for one batch.',
			[
				'memory_limit'       => $memory,
				'max_execution_time' => $time,
				'wp_cron_disabled'   => $cron,
			]
		);
		$headroom   = $memory - memory_get_usage();
		if ( $memory > 0 && $headroom < Job::DEFAULT_OPTIONS['memory_budget'] ) {
			$problems[] = Problem::warning( 'memory_limit', sprintf( 'PHP memory_limit is %1$s and the site already uses %2$s; a batch can grow by %3$s, so batches will be short.', size_format( $memory ), size_format( memory_get_usage() ), size_format( Job::DEFAULT_OPTIONS['memory_budget'] ) ) );
		}
		if ( $time > 0 && $time < 30 ) {
			$problems[] = Problem::warning( 'max_execution_time', sprintf( 'max_execution_time is %d seconds; a batch can take 20.', $time ) );
		}
		if ( ( $inventory['orders'] ?? 0 ) > 10000 ) {
			$problems[] = Problem::info( 'use_cli', 'More than 10,000 orders: run the job with `wp flexacommerce migrate run`.' );
		}
		return $problems;
	}
}
