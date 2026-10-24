<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A failed SQL statement. Repositories throw it so Transaction can roll back
 * and retry deadlocks; the MySQL error number is kept in `errno`.
 */
final class QueryFailed extends \RuntimeException {
	public const DEADLOCK          = 1213;
	public const LOCK_WAIT_TIMEOUT = 1205;
	public const DUPLICATE_KEY     = 1062;

	// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- promotes $errno.
	public function __construct( string $message, public readonly int $errno = 0 ) {
		parent::__construct( $message, $errno );
	}

	public static function from_wpdb( \wpdb $wpdb ): self {
		// wpdb keeps the handle protected but exposes it through __get().
		$dbh     = $wpdb->__get( 'dbh' );
		$errno   = $dbh instanceof \mysqli ? mysqli_errno( $dbh ) : 0; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- wpdb has no errno accessor.
		$message = $wpdb->last_error !== '' ? $wpdb->last_error : 'Database query failed.';
		return new self( $message, $errno !== 0 ? $errno : self::errno_from_message( $message ) );
	}

	/** For drop-ins that do not use mysqli (HyperDB forks, proxies): read the server message. */
	private static function errno_from_message( string $message ): int {
		return match ( true ) {
			str_starts_with( $message, 'Deadlock found' )    => self::DEADLOCK,
			str_starts_with( $message, 'Lock wait timeout' ) => self::LOCK_WAIT_TIMEOUT,
			str_starts_with( $message, 'Duplicate entry' )   => self::DUPLICATE_KEY,
			default                                         => 0,
		};
	}

	public function is_retryable(): bool {
		return $this->errno === self::DEADLOCK || $this->errno === self::LOCK_WAIT_TIMEOUT;
	}

	public function is_duplicate_key(): bool {
		return $this->errno === self::DUPLICATE_KEY;
	}
}
