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

	/** @var list<array{0:string,1:string,2:?string,3:string}>|null rows held by batch() */
	private ?array $held = null;

	public function log( string $level, string $message, array $context = [] ): void {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}
		if ( ! self::passes( $level, Settings::string( 'log_level' ) ) ) {
			return;
		}

		$clean   = $this->redactor->context( $context );
		$encoded = $clean === [] ? null : wp_json_encode( $clean );
		$row     = [ $level, $this->redactor->text( self::interpolate( $message, $clean ) ), $encoded === false ? null : $encoded, $this->clock->db_now() ];
		if ( $this->held !== null ) {
			$this->held[] = $row;
			return;
		}
		$this->write( [ $row ] );
	}

	/**
	 * Run $work and store every entry it logs on this channel with one
	 * insert, for callers that log once per order line (P6q).
	 *
	 * @param callable():void $work
	 */
	public function batch( callable $work ): void {
		if ( $this->held !== null ) {
			$work();
			return;
		}
		$this->held = [];
		try {
			$work();
		} finally {
			$this->flush();
		}
	}

	private function flush(): void {
		$rows       = $this->held ?? [];
		$this->held = null;
		if ( $rows !== [] ) {
			$this->write( $rows );
		}
	}

	/** @param non-empty-list<array{0:string,1:string,2:?string,3:string}> $rows */
	private function write( array $rows ): void {
		$channel = $this->channel;
		$this->transaction->when_idle(
			function () use ( $channel, $rows ): void {
				$stored = false;
				try {
					$stored = $this->repository->insert_many( $channel, $rows );
				} catch ( \Throwable ) {
					$stored = false;
				}
				if ( ! $stored ) {
					foreach ( $rows as [ $level, $text ] ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort fallback.
						error_log( sprintf( '[flexacommerce] %s.%s: %s', $channel, $level, $text ) );
					}
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
