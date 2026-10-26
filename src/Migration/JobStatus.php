<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

defined( 'ABSPATH' ) || exit;

/** Job state machine (doc 05 §4.3). */
final class JobStatus {
	public const DRAFT                   = 'draft';
	public const READY                   = 'ready';
	public const RUNNING                 = 'running';
	public const PAUSED                  = 'paused';
	public const FAILED                  = 'failed';
	public const CANCELLED               = 'cancelled';
	public const VERIFYING               = 'verifying';
	public const COMPLETED               = 'completed';
	public const COMPLETED_WITH_WARNINGS = 'completed_with_warnings';

	public const ALL = [ self::DRAFT, self::READY, self::RUNNING, self::PAUSED, self::FAILED, self::CANCELLED, self::VERIFYING, self::COMPLETED, self::COMPLETED_WITH_WARNINGS ];

	/** Statuses a re-run (incremental run) can start from. */
	public const FINISHED = [ self::COMPLETED, self::COMPLETED_WITH_WARNINGS ];

	private const MOVES = [
		self::DRAFT                   => [ self::READY, self::CANCELLED ],
		self::READY                   => [ self::DRAFT, self::RUNNING, self::CANCELLED ],
		self::RUNNING                 => [ self::PAUSED, self::FAILED, self::CANCELLED, self::VERIFYING ],
		self::PAUSED                  => [ self::RUNNING, self::CANCELLED ],
		self::FAILED                  => [ self::RUNNING, self::CANCELLED ],
		self::VERIFYING               => [ self::COMPLETED, self::COMPLETED_WITH_WARNINGS, self::FAILED ],
		self::COMPLETED               => [ self::RUNNING, self::VERIFYING ],
		self::COMPLETED_WITH_WARNINGS => [ self::RUNNING, self::VERIFYING ],
		self::CANCELLED               => [],
	];

	public static function can_move( string $from, string $to ): bool {
		return in_array( $to, self::MOVES[ $from ] ?? [], true );
	}
}
