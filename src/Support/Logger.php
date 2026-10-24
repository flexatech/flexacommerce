<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\Logger as LoggerContract;
use Flexa\Commerce\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Writes redacted entries to fxc_logs for one channel. The write waits until
 * no transaction is open, so a rollback cannot erase the entry that explains
 * it. When the table write fails the entry goes to the PHP error log.
 */
final class Logger implements LoggerContract {
	public function __construct(
		private readonly string $channel,
		private readonly LogRepository $repository,
		private readonly Redactor $redactor,
		private readonly Transaction $transaction,
		private readonly Clock $clock,
	) {}

	public function log( string $level, string $message, array $context = [] ): void {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}
		if ( ! self::passes( $level, Settings::string( 'log_level' ) ) ) {
			return;
		}

		$clean   = $this->redactor->context( $context );
		$text    = $this->redactor->text( self::interpolate( $message, $clean ) );
		$encoded = $clean === [] ? null : wp_json_encode( $clean );
		$when    = $this->clock->db_now();
		$channel = $this->channel;

		$this->transaction->when_idle(
			function () use ( $level, $channel, $text, $encoded, $when ): void {
				$stored = false;
				try {
					$stored = $this->repository->insert( $level, $channel, $text, $encoded === false ? null : $encoded, $when );
				} catch ( \Throwable ) {
					$stored = false;
				}
				if ( ! $stored ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort fallback.
					error_log( sprintf( '[flexacommerce] %s.%s: %s', $channel, $level, $text ) );
				}
			}
		);
	}

	public function debug( string $message, array $context = [] ): void {
		$this->log( 'debug', $message, $context );
	}

	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	public function notice( string $message, array $context = [] ): void {
		$this->log( 'notice', $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	public function critical( string $message, array $context = [] ): void {
		$this->log( 'critical', $message, $context );
	}

	public static function passes( string $level, string $minimum ): bool {
		$rank = array_flip( self::LEVELS );
		return ( $rank[ $level ] ?? 0 ) >= ( $rank[ $minimum ] ?? 1 );
	}

	/**
	 * PSR-3 placeholders: "{order_id}" is replaced by a scalar context value.
	 *
	 * @param array<string,mixed> $context
	 */
	public static function interpolate( string $message, array $context ): string {
		if ( ! str_contains( $message, '{' ) ) {
			return $message;
		}
		$replace = [];
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) || $value === null || $value instanceof \Stringable ) {
				$replace[ '{' . $key . '}' ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			}
		}
		return strtr( $message, $replace );
	}
}
