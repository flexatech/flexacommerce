<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-3 shaped logger. Entries land in Status > Logs after redaction, so
 * context may hold raw request data: secrets, emails and addresses are masked.
 *
 * @api
 */
interface Logger {
	public const LEVELS = [ 'debug', 'info', 'notice', 'warning', 'error', 'critical' ];

	/** @param array<string,mixed> $context */
	public function log( string $level, string $message, array $context = [] ): void;

	/** @param array<string,mixed> $context */
	public function debug( string $message, array $context = [] ): void;

	/** @param array<string,mixed> $context */
	public function info( string $message, array $context = [] ): void;

	/** @param array<string,mixed> $context */
	public function notice( string $message, array $context = [] ): void;

	/** @param array<string,mixed> $context */
	public function warning( string $message, array $context = [] ): void;

	/** @param array<string,mixed> $context */
	public function error( string $message, array $context = [] ): void;

	/** @param array<string,mixed> $context */
	public function critical( string $message, array $context = [] ): void;
}
