<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * UTC time source (doc 03 §2.3). Pass a fixed instant in tests.
 */
final class Clock {
	public const DB_FORMAT = 'Y-m-d H:i:s';

	public function __construct( private readonly ?\DateTimeImmutable $fixed = null ) {}

	public function now(): \DateTimeImmutable {
		$utc = new \DateTimeZone( 'UTC' );
		return $this->fixed?->setTimezone( $utc ) ?? new \DateTimeImmutable( 'now', $utc );
	}

	/** Current time as a `datetime` column value. */
	public function db_now(): string {
		return $this->now()->format( self::DB_FORMAT );
	}

	public function db_ago( int $seconds ): string {
		return $this->now()->modify( sprintf( '-%d seconds', $seconds ) )->format( self::DB_FORMAT );
	}
}
