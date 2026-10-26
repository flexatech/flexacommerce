<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Record ids and references: `{system}:{entity}:{id}`, with an optional
 * qualifier before the id (`wc:term:product_cat:12`). The id is always the
 * last segment (doc 05 §3.1).
 */
final class Ref {
	public function __construct(
		public readonly string $system,
		public readonly string $entity,
		public readonly string $id,
		public readonly string $qualifier = '',
	) {}

	public static function make( string $system, string $entity, int|string $id, string $qualifier = '' ): string {
		return $system . ':' . $entity . ':' . ( $qualifier !== '' ? $qualifier . ':' : '' ) . $id;
	}

	public static function parse( ?string $ref ): ?self {
		if ( $ref === null ) {
			return null;
		}
		$parts = explode( ':', $ref );
		if ( count( $parts ) < 3 || in_array( '', $parts, true ) ) {
			return null;
		}
		$system = array_shift( $parts );
		$entity = array_shift( $parts );
		$id     = (string) array_pop( $parts );
		return new self( $system, $entity, $id, implode( ':', $parts ) );
	}

	/** Numeric id of a reference, 0 when it is not one. */
	public static function int_id( ?string $ref ): int {
		$parsed = self::parse( $ref );
		return $parsed !== null && ctype_digit( $parsed->id ) ? (int) $parsed->id : 0;
	}

	public function int(): int {
		return ctype_digit( $this->id ) ? (int) $this->id : 0;
	}
}
