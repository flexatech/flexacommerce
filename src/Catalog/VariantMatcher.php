<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the variation for a concrete attribute selection (doc 03 §3.2,
 * doc 02 §7.2): exact match first, then fewer wildcards, then the lower
 * menu_order, then the lower id. The storefront script mirrors this order.
 */
final class VariantMatcher {
	/**
	 * @param list<Variant>        $variants
	 * @param array<string,string> $selection
	 */
	public function match( array $variants, array $selection, bool $enabled_only = true ): ?Variant {
		if ( in_array( Combination::WILDCARD, $selection, true ) ) {
			return null;
		}
		$candidates = array_values(
			array_filter(
				$variants,
				static fn ( Variant $v ): bool => ! $v->is_base
					&& ( ! $enabled_only || $v->enabled )
					&& $v->combination->matches( $selection )
			)
		);
		if ( $candidates === [] ) {
			return null;
		}
		usort( $candidates, [ self::class, 'compare' ] );
		return $candidates[0];
	}

	/**
	 * Pairs of variations that a single selection could match, for the
	 * "overlapping variations" warning in the editor.
	 *
	 * @param list<Variant> $variants
	 * @return list<array{0:Variant,1:Variant}>
	 */
	public function overlaps( array $variants ): array {
		$rows = array_values( array_filter( $variants, static fn ( Variant $v ): bool => ! $v->is_base && $v->enabled ) );
		$out  = [];
		$n    = count( $rows );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( $rows[ $i ]->combination->overlaps( $rows[ $j ]->combination ) ) {
					$out[] = [ $rows[ $i ], $rows[ $j ] ];
				}
			}
		}
		return $out;
	}

	private static function compare( Variant $a, Variant $b ): int {
		return [ $a->combination->wildcard_count(), $a->menu_order, $a->id ]
			<=> [ $b->combination->wildcard_count(), $b->menu_order, $b->id ];
	}
}
