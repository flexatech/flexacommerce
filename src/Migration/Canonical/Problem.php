<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Canonical;

defined( 'ABSPATH' ) || exit;

/** One warning, error or info row for fxc_migration_log. */
final class Problem {
	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';

	/** @param array<string,mixed> $context */
	public function __construct(
		public readonly string $level,
		public readonly string $code,
		public readonly string $message,
		public readonly array $context = [],
	) {}

	/** @param array<string,mixed> $context */
	public static function warning( string $code, string $message, array $context = [] ): self {
		return new self( self::WARNING, $code, $message, $context );
	}

	/** @param array<string,mixed> $context */
	public static function error( string $code, string $message, array $context = [] ): self {
		return new self( self::ERROR, $code, $message, $context );
	}

	/** @param array<string,mixed> $context */
	public static function info( string $code, string $message, array $context = [] ): self {
		return new self( self::INFO, $code, $message, $context );
	}
}
