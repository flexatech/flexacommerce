<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Canonical\Validator;
use Flexa\Commerce\Migration\Sources\SourceAdapter;
use Flexa\Commerce\Migration\Targets\RecordingTarget;
use Flexa\Commerce\Migration\Targets\TargetAdapter;
use Flexa\Commerce\Migration\Targets\WriteResult;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One batch of a job (doc 05 §4.4): take the lease, walk the stages from the
 * stored cursor, stop at the batch size or the time and memory budgets, and
 * persist the cursor after every entity. Every driver (admin polling, WP-CLI,
 * Action Scheduler) calls run_batch().
 */
final class Runner {
	public const LEASE_SECONDS = 60;
	private const MAX_DEPTH    = 20;
	/** Share of memory_limit at which a batch stops, whatever its budget. */
	private const LIMIT_SHARE = 0.8;

	/** @var array<string,bool> dependency refs handled in this batch */
	private array $seen = [];

	/** @var array<string,array{last_id:int,upper_id:int,done?:bool}> */
	private array $cursor = [];

	/** @var array<string,array<string,int>> */
	private array $counters = [];

	private ?Mapping $map          = null;
	private ?SourceAdapter $source = null;
	private ?TargetAdapter $target = null;
	private int $memory_start      = 0;

	/**
	 * @param \Closure(Job):array{0:SourceAdapter,1:TargetAdapter} $adapters
	 * @param \Closure(Job):array<string,mixed>                     $verify
	 */
	public function __construct(
		private readonly JobRepository $jobs,
		private readonly IdMapRepository $ids,
		private readonly Validator $validator,
		private readonly Clock $clock,
		private readonly \Closure $adapters,
		private readonly \Closure $verify,
	) {}

	/**
	 * `peak` is the batch's own peak memory in bytes (P9, doc 06 §3), `growth`
	 * that peak less the memory in use when the batch started.
	 *
	 * @return array{job:?Job,processed:int,done:bool,locked:bool,peak:int,growth:int}
	 */
	public function run_batch( int $job_id ): array {
		// PHP 8.2+, which the plugin requires; phpstan also reads 8.1 for the bootstrap guard.
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$this->memory_start = memory_get_usage();
		$job                = $this->jobs->find( $job_id );
		if ( $job === null || ! in_array( $job->status, [ JobStatus::RUNNING, JobStatus::VERIFYING ], true ) ) {
			return [
				'job'       => $job,
				'processed' => 0,
				'done'      => true,
				'locked'    => false,
				'peak'      => memory_get_peak_usage(),
				'growth'    => memory_get_peak_usage() - $this->memory_start,
			];
		}
		$token = wp_generate_uuid4();
		if ( ! $this->jobs->lease( $job->id, $token, $this->clock->db_now(), $this->lease_until() ) ) {
			return [
				'job'       => $job,
				'processed' => 0,
				'done'      => false,
				'locked'    => true,
				'peak'      => memory_get_peak_usage(),
				'growth'    => memory_get_peak_usage() - $this->memory_start,
			];
		}
		$processed = 0;
		ImportGuard::enable();
		try {
			if ( $job->status === JobStatus::VERIFYING ) {
				$this->finish( $job );
			} else {
				$processed = $this->stages( $job, $token );
			}
		} catch ( \Exception $e ) {
			$this->jobs->log( $job->id, 'job', '', Problem::error( 'fatal', $e->getMessage(), [ 'exception' => $e::class ] ), $this->clock->db_now() );
			$this->jobs->transition(
				$job->id,
				[ JobStatus::RUNNING, JobStatus::VERIFYING ],
				JobStatus::FAILED,
				$this->clock->db_now(),
				[ 'report' => array_merge( $job->report, [ 'error' => $e->getMessage() ] ) ]
			);
		} finally {
			ImportGuard::disable();
			$this->jobs->release( $job->id, $token );
			$this->map?->reset();
			$this->map    = null;
			$this->source = null;
			$this->target = null;
			$this->seen   = [];
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		$job = $this->jobs->find( $job_id );
		return [
			'job'       => $job,
			'processed' => $processed,
			'done'      => $job === null || ! in_array( $job->status, [ JobStatus::RUNNING, JobStatus::VERIFYING ], true ),
			'locked'    => false,
			'peak'      => memory_get_peak_usage(),
			'growth'    => memory_get_peak_usage() - $this->memory_start,
		];
	}

	private function stages( Job $job, string $token ): int {
		[ $source, $target ] = ( $this->adapters )( $job );
		$this->source        = $source;
		$this->target        = $job->dry_run ? new RecordingTarget( $target ) : $target;
		$this->map           = new Mapping( $this->ids, $job->direction, $job->dry_run ? null : $job->id, fn (): string => $this->clock->db_now() );
		$this->cursor        = $job->cursor;
		$this->counters      = $job->counters;

		$started   = microtime( true );
		$leased    = $started;
		$processed = 0;
		foreach ( $job->stages() as $stage ) {
			$cursor = $this->cursor[ $stage ] ?? null;
			if ( $cursor !== null && ! empty( $cursor['done'] ) ) {
				continue;
			}
			if ( $cursor === null ) {
				$cursor                 = [
					'last_id'  => 0,
					'upper_id' => $source->upper_id( $stage, $job ),
				];
				$this->cursor[ $stage ] = $cursor;
				$this->save( $job, $stage );
			}
			$size  = $job->batch_size( $stage );
			$count = 0;
			while ( true ) {
				$ids = $source->ids( $stage, (int) $cursor['last_id'], (int) $cursor['upper_id'], $size - $count, $job );
				if ( $ids === [] ) {
					$this->target->finish_stage( $stage, $job );
					$this->cursor[ $stage ]['done'] = true;
					$this->save( $job, $stage );
					break;
				}
				foreach ( $ids as $id ) {
					$this->entity( $job, $stage, $id );
					$cursor['last_id']                 = $id;
					$this->cursor[ $stage ]['last_id'] = $id;
					$this->save( $job, $stage );
					++$processed;
					++$count;
					if ( microtime( true ) - $leased > self::LEASE_SECONDS / 3 ) {
						$this->jobs->lease( $job->id, $token, $this->clock->db_now(), $this->lease_until() );
						$leased = microtime( true );
					}
					if ( $count >= $size || $this->over_budget( $job, $started ) ) {
						return $processed;
					}
				}
			}
			if ( $this->over_budget( $job, $started ) ) {
				return $processed;
			}
		}
		$this->jobs->transition( $job->id, [ JobStatus::RUNNING ], JobStatus::VERIFYING, $this->clock->db_now() );
		return $processed;
	}

	/** One source entity: read, validate, resolve, write, log. Exceptions reject the entity; errors stop the batch. */
	private function entity( Job $job, string $stage, int $id ): void {
		$entity = Job::entity( $stage );
		try {
			$record = $this->source()->read( $stage, $id, $job );
		} catch ( Rejected $e ) {
			$this->reject( $job, $stage, $entity, (string) $id, $e->problem() );
			return;
		}
		if ( $record === null ) {
			return;
		}
		$this->process( $job, $stage, $record, 0 );
	}

	private function process( Job $job, string $stage, Record $record, int $depth ): void {
		$entity    = $record->type;
		$source_id = $record->source_id();
		$problems  = $this->validator->validate( $record );
		foreach ( $problems as $problem ) {
			if ( $problem->level === Problem::ERROR ) {
				foreach ( $problems as $each ) {
					$this->log( $job, $entity, $source_id, $each );
				}
				$this->bump( $stage, 'rejected' );
				return;
			}
		}
		foreach ( $record->depends as $ref ) {
			$this->dependency( $job, $ref, $depth + 1 );
		}
		$skip = $this->unchanged( $job, $record );
		if ( $skip !== null ) {
			if ( $skip instanceof Problem ) {
				$this->log( $job, $entity, $source_id, $skip );
				$this->bump( $stage, 'skipped' );
				$this->bump( $stage, 'warnings' );
			} else {
				$this->bump( $stage, 'unchanged' );
			}
			return;
		}
		try {
			$result = $this->target()->write( $record, $this->map(), $job );
		} catch ( Rejected $e ) {
			$this->map()->reset();
			foreach ( $problems as $each ) {
				$this->log( $job, $entity, $source_id, $each );
			}
			$this->reject( $job, $stage, $entity, $source_id, $e->problem() );
			return;
		} catch ( \Exception $e ) {
			$this->map()->reset();
			$this->reject( $job, $stage, $entity, $source_id, Problem::error( 'write_failed', $e->getMessage(), [ 'exception' => $e::class ] ) );
			return;
		}
		$warnings = 0;
		foreach ( [ ...$problems, ...$result->problems ] as $problem ) {
			$this->log( $job, $entity, $source_id, $problem );
			$warnings += $problem->level === Problem::WARNING ? 1 : 0;
		}
		$this->bump( $stage, $result->action );
		if ( $warnings > 0 ) {
			$this->bump( $stage, 'warnings', $warnings );
		}
		if ( $result->action !== WriteResult::SKIPPED && ! $job->dry_run ) {
			do_action( 'flexacommerce.migration.entity_imported', $entity, $source_id, $result->target_id, $result->action, $job->id );
		}
	}

	/**
	 * Change detection (doc 05 §6, §8.1). Null means write; true means the
	 * entity did not change; a Problem means the target changed and the
	 * policy keeps it.
	 */
	private function unchanged( Job $job, Record $record ): Problem|bool|null {
		$row = $this->map()->row( $record->type, $record->source_id() );
		if ( $row === null || $job->option( 'force' ) ) {
			return null;
		}
		$last     = $this->jobs->direction_of( (int) $row->job_id );
		$modified = $record->modified_at();
		if ( $last === $job->direction && $row->checksum === $record->checksum ) {
			return true;
		}
		// Written by the other direction: the record is that write unless the source changed since.
		if ( $last !== $job->direction && $modified !== null && $modified <= $row->updated_at ) {
			return true;
		}
		$target_id      = (int) $row->id_on( $job->target() );
		$target_changed = $target_id > 0 ? $this->target()->modified_at( $record->type, $target_id ) : null;
		if ( $target_changed !== null && $target_changed > $row->updated_at && $job->option( 'target_modified' ) !== 'source_wins' ) {
			return Problem::warning(
				'target_modified',
				'The target entity changed after the last import; kept as it is.',
				[
					'target_id'   => $target_id,
					'modified_at' => $target_changed,
					'imported_at' => $row->updated_at,
				]
			);
		}
		return null;
	}

	private function dependency( Job $job, string $ref, int $depth ): void {
		$parsed = Ref::parse( $ref );
		if ( $parsed === null || $parsed->system !== $this->source()->system() || isset( $this->seen[ $ref ] ) || $depth > self::MAX_DEPTH ) {
			return;
		}
		$this->seen[ $ref ] = true;
		$stage              = Job::stage_of( $parsed->entity );
		if ( $stage === null || $this->map()->target_id( $parsed->entity, $parsed->id ) > 0 ) {
			return;
		}
		try {
			$record = $this->source()->read_ref( $ref, $job );
		} catch ( Rejected $e ) {
			$this->reject( $job, $stage, $parsed->entity, $parsed->id, $e->problem() );
			return;
		}
		if ( $record !== null ) {
			$this->process( $job, $stage, $record, $depth );
		}
	}

	private function reject( Job $job, string $stage, string $entity, string $source_id, Problem $problem ): void {
		$this->log( $job, $entity, $source_id, $problem );
		$this->bump( $stage, 'rejected' );
	}

	private function log( Job $job, string $entity, string $source_id, Problem $problem ): void {
		if ( $problem->level === Problem::INFO && ! $job->dry_run && ! $job->option( 'verbose' ) ) {
			return;
		}
		$this->jobs->log( $job->id, $entity, $source_id, $problem, $this->clock->db_now() );
	}

	private function bump( string $stage, string $counter, int $by = 1 ): void {
		$this->counters[ $stage ][ $counter ] = ( $this->counters[ $stage ][ $counter ] ?? 0 ) + $by;
	}

	private function save( Job $job, string $stage ): void {
		$this->jobs->save_progress( $job->id, $stage, $this->cursor, $this->counters, $this->clock->db_now() );
	}

	private function finish( Job $job ): void {
		$report = ( $this->verify )( $job );
		$levels = $this->jobs->log_counts( $job->id );
		$warned = ( $levels[ Problem::WARNING ] ?? 0 ) + ( $levels[ Problem::ERROR ] ?? 0 ) > 0 || ! empty( $report['mismatches'] );
		$this->jobs->transition(
			$job->id,
			[ JobStatus::VERIFYING ],
			$warned ? JobStatus::COMPLETED_WITH_WARNINGS : JobStatus::COMPLETED,
			$this->clock->db_now(),
			[
				'report'      => $report,
				'finished_at' => $this->clock->db_now(),
			]
		);
	}

	private function map(): Mapping {
		return $this->map ?? throw new \LogicException( 'The batch has not started.' );
	}

	private function source(): SourceAdapter {
		return $this->source ?? throw new \LogicException( 'The batch has not started.' );
	}

	private function target(): TargetAdapter {
		return $this->target ?? throw new \LogicException( 'The batch has not started.' );
	}

	private function over_budget( Job $job, float $started ): bool {
		if ( microtime( true ) - $started >= (int) $job->option( 'time_budget' ) ) {
			return true;
		}
		$used = memory_get_usage();
		if ( $used - $this->memory_start >= (int) $job->option( 'memory_budget' ) ) {
			return true;
		}
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit > 0 && $used >= $limit * self::LIMIT_SHARE;
	}

	private function lease_until(): string {
		return $this->clock->now()->modify( '+' . self::LEASE_SECONDS . ' seconds' )->format( Clock::DB_FORMAT );
	}
}
