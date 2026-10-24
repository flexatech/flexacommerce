<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Internal product type registry (doc 04 §6). Not public yet: it becomes a
 * Contracts interface once a second real type exists.
 */
final class ProductTypes {
	public const SIMPLE   = 'simple';
	public const VARIABLE = 'variable';

	/** @return list<string> */
	public static function all(): array {
		return [ self::SIMPLE, self::VARIABLE ];
	}

	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}

	/** Whether the type sells its variation rows instead of its base row. */
	public static function has_variations( string $type ): bool {
		return $type === self::VARIABLE;
	}
}
