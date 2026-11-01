<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Internal product type registry (doc 04 §6). A grouped product shows other
 * products on its page and sells nothing itself; an external product links
 * to a page on another site (ADR-062). A bundle sells rows of other
 * products together and takes their stock (ADR-071).
 */
final class ProductTypes {
	public const SIMPLE   = 'simple';
	public const VARIABLE = 'variable';
	public const GROUPED  = 'grouped';
	public const EXTERNAL = 'external';
	public const BUNDLE   = 'bundle';

	/** @return list<string> */
	public static function all(): array {
		return [ self::SIMPLE, self::VARIABLE, self::GROUPED, self::EXTERNAL, self::BUNDLE ];
	}

	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}

	/** Whether the type sells its variation rows instead of its base row. */
	public static function has_variations( string $type ): bool {
		return $type === self::VARIABLE;
	}

	/** Whether a row of the type can go in the cart. */
	public static function is_sold( string $type ): bool {
		return $type === self::SIMPLE || $type === self::VARIABLE || $type === self::BUNDLE;
	}

	/** Whether the type keeps stock, shipping data and files on its rows; a bundle uses its parts'. */
	public static function has_stock( string $type ): bool {
		return $type === self::SIMPLE || $type === self::VARIABLE;
	}
}
