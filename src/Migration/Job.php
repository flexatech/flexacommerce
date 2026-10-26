<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

defined( 'ABSPATH' ) || exit;

/** One fxc_migration_jobs row. */
final class Job {
	/** Stage order (doc 05 §4.4, plus settings and taxes from ADR-020 and shipping zones). */
	public const STAGES = [ 'settings', 'taxes', 'shipping', 'attributes', 'terms', 'products', 'customers', 'orders' ];

	/** Id map entity written by each stage. */
	public const ENTITIES = [
		'settings'   => 'settings',
		'taxes'      => 'tax_rate',
		'shipping'   => 'shipping',
		'attributes' => 'attribute',
		'terms'      => 'term',
		'products'   => 'product',
		'customers'  => 'customer',
		'orders'     => 'order',
	];

	public const DEFAULT_OPTIONS = [
		'stages'                => self::STAGES,
		'target_modified'       => 'skip',
		'duplicate_sku'         => 'null',
		'order_number_conflict' => 'prefix',
		'status_map'            => [],
		'orders_from'           => null,
		'orders_to'             => null,
		'batch_products'        => 50,
		'batch_orders'          => 100,
		'batch_default'         => 200,
		'time_budget'           => 20,
		// Bytes a batch may grow by from its start, so a heavy site still gets full batches.
		'memory_budget'         => 67108864,
		'verbose'               => false,
		'force'                 => false,
		'backup_confirmed'      => false,
	];

	/**
	 * @param array<string,mixed>                                       $options
	 * @param array<string,array{last_id:int,upper_id:int,done?:bool}> $cursor
	 * @param array<string,array<string,int>>                           $counters stage => counter => n
	 * @param array<string,mixed>                                       $report
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $direction,
		public readonly string $status,
		public readonly bool $dry_run,
		public readonly array $options,
		public readonly string $stage,
		public readonly array $cursor,
		public readonly array $counters,
		public readonly ?string $lock_token,
		public readonly ?string $locked_until,
		public readonly int $created_by,
		public readonly array $report,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly ?string $started_at,
		public readonly ?string $finished_at,
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			direction: (string) $row['direction'],
			status: (string) $row['status'],
			dry_run: (bool) (int) $row['dry_run'],
			options: self::options( self::json( $row['options'] ?? null ) ),
			stage: (string) $row['stage'],
			cursor: self::json( $row['cursor_state'] ?? null ),
			counters: self::json( $row['counters'] ?? null ),
			lock_token: isset( $row['lock_token'] ) ? (string) $row['lock_token'] : null,
			locked_until: isset( $row['locked_until'] ) ? (string) $row['locked_until'] : null,
			created_by: (int) $row['created_by'],
			report: self::json( $row['report'] ?? null ),
			created_at: (string) $row['created_at'],
			updated_at: (string) $row['updated_at'],
			started_at: isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
			finished_at: isset( $row['finished_at'] ) ? (string) $row['finished_at'] : null,
		);
	}

	/**
	 * Options merged over the defaults; unknown keys dropped.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function options( array $input ): array {
		$out = self::DEFAULT_OPTIONS;
		foreach ( $input as $key => $value ) {
			if ( array_key_exists( $key, self::DEFAULT_OPTIONS ) ) {
				$out[ $key ] = $value;
			}
		}
		$stages        = is_array( $out['stages'] ) ? array_map( 'strval', $out['stages'] ) : self::STAGES;
		$out['stages'] = array_values( array_intersect( self::STAGES, $stages ) );
		foreach ( [ 'batch_products', 'batch_orders', 'batch_default', 'time_budget', 'memory_budget' ] as $key ) {
			$out[ $key ] = max( 1, (int) $out[ $key ] );
		}
		$out['target_modified']       = $out['target_modified'] === 'source_wins' ? 'source_wins' : 'skip';
		$out['duplicate_sku']         = in_array( $out['duplicate_sku'], [ 'null', 'skip', 'suffix' ], true ) ? $out['duplicate_sku'] : 'null';
		$out['order_number_conflict'] = $out['order_number_conflict'] === 'skip' ? 'skip' : 'prefix';
		$out['status_map']            = is_array( $out['status_map'] ) ? array_map( 'strval', $out['status_map'] ) : [];
		$out['verbose']               = (bool) $out['verbose'];
		$out['force']                 = (bool) $out['force'];
		$out['backup_confirmed']      = (bool) $out['backup_confirmed'];
		return $out;
	}

	public function option( string $key ): mixed {
		return $this->options[ $key ] ?? self::DEFAULT_OPTIONS[ $key ] ?? null;
	}

	/** @return list<string> */
	public function stages(): array {
		/** @var list<string> */
		return $this->options['stages'];
	}

	public static function entity( string $stage ): string {
		return self::ENTITIES[ $stage ] ?? $stage;
	}

	public static function stage_of( string $entity ): ?string {
		$stage = array_search( $entity, self::ENTITIES, true );
		return $stage === false ? null : $stage;
	}

	public function batch_size( string $stage ): int {
		return match ( $stage ) {
			'products' => (int) $this->option( 'batch_products' ),
			'orders'   => (int) $this->option( 'batch_orders' ),
			default    => (int) $this->option( 'batch_default' ),
		};
	}

	public function source(): string {
		return Direction::source( $this->direction );
	}

	public function target(): string {
		return Direction::target( $this->direction );
	}

	public function counter( string $stage, string $name ): int {
		return (int) ( $this->counters[ $stage ][ $name ] ?? 0 );
	}

	/** @return array<string,int> counter => n over every stage */
	public function totals(): array {
		$out = [];
		foreach ( $this->counters as $counters ) {
			foreach ( $counters as $name => $n ) {
				$out[ $name ] = ( $out[ $name ] ?? 0 ) + (int) $n;
			}
		}
		return $out;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'          => $this->id,
			'direction'   => $this->direction,
			'status'      => $this->status,
			'dry_run'     => $this->dry_run,
			'options'     => $this->options,
			'stage'       => $this->stage,
			'cursor'      => $this->cursor,
			'counters'    => $this->counters,
			'totals'      => $this->totals(),
			'report'      => $this->report,
			'created_by'  => $this->created_by,
			'created_at'  => $this->created_at,
			'updated_at'  => $this->updated_at,
			'started_at'  => $this->started_at,
			'finished_at' => $this->finished_at,
		];
	}

	/** @return array<string,mixed> */
	private static function json( mixed $value ): array {
		if ( ! is_string( $value ) || $value === '' ) {
			return [];
		}
		$data = json_decode( $value, true );
		return is_array( $data ) ? $data : [];
	}
}
