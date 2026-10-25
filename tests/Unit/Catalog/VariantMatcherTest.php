<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog;

use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Catalog\VariantMatcher;
use Flexa\Commerce\Tests\Unit\TestCase;

final class VariantMatcherTest extends TestCase {
	private const SELECTION = [
		'g:1'    => '10',
		'l:size' => 'xl',
	];

	/** @param array<string,string> $pairs */
	private static function variant( int $id, array $pairs, int $menu_order = 0, bool $enabled = true ): Variant {
		return new Variant(
			id: $id,
			product_id: 1,
			is_base: false,
			combination: Combination::from_array( $pairs ),
			enabled: $enabled,
			menu_order: $menu_order,
		);
	}

	public function test_exact_match_beats_wildcards(): void {
		$variants = [
			self::variant(
				1,
				[
					'g:1'    => '*',
					'l:size' => '*',
				]
			),
			self::variant(
				2,
				[
					'g:1'    => '10',
					'l:size' => 'xl',
				],
				5
			),
			self::variant(
				3,
				[
					'g:1'    => '10',
					'l:size' => '*',
				]
			),
		];
		self::assertSame( 2, ( new VariantMatcher() )->match( $variants, self::SELECTION )?->id );
	}

	public function test_fewer_wildcards_win(): void {
		$variants = [
			self::variant(
				1,
				[
					'g:1'    => '*',
					'l:size' => '*',
				]
			),
			self::variant(
				2,
				[
					'g:1'    => '*',
					'l:size' => 'xl',
				],
				9
			),
		];
		self::assertSame( 2, ( new VariantMatcher() )->match( $variants, self::SELECTION )?->id );
	}

	public function test_menu_order_then_id_break_ties(): void {
		$matcher  = new VariantMatcher();
		$variants = [
			self::variant(
				7,
				[
					'g:1'    => '*',
					'l:size' => 'xl',
				],
				2
			),
			self::variant(
				5,
				[
					'g:1'    => '10',
					'l:size' => '*',
				],
				1
			),
		];
		self::assertSame( 5, $matcher->match( $variants, self::SELECTION )?->id );

		$variants = [
			self::variant(
				9,
				[
					'g:1'    => '*',
					'l:size' => 'xl',
				]
			),
			self::variant(
				4,
				[
					'g:1'    => '10',
					'l:size' => '*',
				]
			),
		];
		self::assertSame( 4, $matcher->match( $variants, self::SELECTION )?->id );
	}

	public function test_disabled_and_base_rows_are_skipped(): void {
		$variants = [
			self::variant(
				1,
				[
					'g:1'    => '10',
					'l:size' => 'xl',
				],
				0,
				false
			),
			Variant::base_for( 1 ),
			self::variant(
				2,
				[
					'g:1'    => '*',
					'l:size' => '*',
				]
			),
		];
		$matcher  = new VariantMatcher();
		self::assertSame( 2, $matcher->match( $variants, self::SELECTION )?->id );
		self::assertSame( 1, $matcher->match( $variants, self::SELECTION, false )?->id );
	}

	public function test_no_match(): void {
		$variants = [
			self::variant(
				1,
				[
					'g:1'    => '11',
					'l:size' => '*',
				]
			),
		];
		$matcher  = new VariantMatcher();
		self::assertNull( $matcher->match( $variants, self::SELECTION ) );
		self::assertNull( $matcher->match( $variants, [ 'g:1' => '11' ] ) );
	}

	public function test_wildcard_in_selection_never_matches(): void {
		$variants = [
			self::variant(
				1,
				[
					'g:1'    => '*',
					'l:size' => '*',
				]
			),
		];
		self::assertNull(
			( new VariantMatcher() )->match(
				$variants,
				[
					'g:1'    => '*',
					'l:size' => 'xl',
				]
			)
		);
	}

	public function test_overlaps_lists_pairs(): void {
		$variants = [
			self::variant(
				1,
				[
					'g:1'    => '*',
					'l:size' => 'xl',
				]
			),
			self::variant(
				2,
				[
					'g:1'    => '10',
					'l:size' => '*',
				]
			),
			self::variant(
				3,
				[
					'g:1'    => '11',
					'l:size' => 's',
				]
			),
		];
		$pairs    = ( new VariantMatcher() )->overlaps( $variants );
		self::assertCount( 1, $pairs );
		self::assertSame( [ 1, 2 ], [ $pairs[0][0]->id, $pairs[0][1]->id ] );
	}
}
