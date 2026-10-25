<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog;

use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Tests\Unit\TestCase;

final class CombinationTest extends TestCase {
	public function test_keys_are_sorted_by_byte_order(): void {
		$a = Combination::from_array(
			[
				'l:fabric' => 'linen',
				'g:12'     => '31',
				'g:2'      => '7',
			]
		);
		$b = Combination::from_array(
			[
				'g:2'      => '7',
				'l:fabric' => 'linen',
				'g:12'     => '31',
			]
		);

		self::assertSame( 'g:12=31&g:2=7&l:fabric=linen', $a->canonical() );
		self::assertSame( $a->hash(), $b->hash() );
		self::assertTrue( $a->equals( $b ) );
	}

	public function test_empty_combination_is_the_base_row(): void {
		$base = Combination::empty();
		self::assertSame( '', $base->canonical() );
		self::assertSame( sha1( '' ), $base->hash() );
		self::assertSame( '{}', $base->to_json() );
		self::assertTrue( Combination::from_json( '{}' )->is_empty() );
	}

	public function test_integer_values_become_strings(): void {
		self::assertSame( [ 'g:12' => '31' ], Combination::from_array( [ 'g:12' => 31 ] )->to_array() );
	}

	public function test_json_round_trip(): void {
		$c = Combination::from_array(
			[
				'g:12'   => '*',
				'l:size' => 'xl',
			]
		);
		self::assertTrue( $c->equals( Combination::from_json( $c->to_json() ) ) );
		self::assertSame( 1, $c->wildcard_count() );
	}

	public function test_unreadable_json_is_empty(): void {
		self::assertTrue( Combination::from_json( 'not json' )->is_empty() );
		self::assertTrue( Combination::from_json( '{"bad key":"x"}' )->is_empty() );
		self::assertTrue( Combination::from_json( null )->is_empty() );
	}

	public function test_local_keys_and_values_use_sanitize_title(): void {
		self::assertSame( 'l:fabric-type', Combination::local_key( 'Fabric Type' ) );
		self::assertSame( 'dark-blue', Combination::local_value( 'Dark Blue' ) );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public static function invalid_pairs(): array {
		return [
			'unknown prefix'     => [ [ 'x:1' => '2' ] ],
			'global key zero'    => [ [ 'g:0' => '2' ] ],
			'global value text'  => [ [ 'g:12' => 'red' ] ],
			'local value spaces' => [ [ 'l:size' => 'X L' ] ],
			'local value empty'  => [ [ 'l:size' => '' ] ],
			'value not a string' => [ [ 'l:size' => [ 'xl' ] ] ],
			'separator in value' => [ [ 'l:size' => 'a&b=c' ] ],
		];
	}

	/**
	 * @dataProvider invalid_pairs
	 * @param array<string,mixed> $pairs
	 */
	public function test_rejects_malformed_pairs( array $pairs ): void {
		$this->expectException( \InvalidArgumentException::class );
		Combination::from_array( $pairs );
	}

	public function test_matches_requires_every_key(): void {
		$c = Combination::from_array(
			[
				'g:1' => '10',
				'g:2' => '*',
			]
		);
		self::assertTrue(
			$c->matches(
				[
					'g:1' => '10',
					'g:2' => '20',
				]
			)
		);
		self::assertFalse(
			$c->matches(
				[
					'g:1' => '11',
					'g:2' => '20',
				]
			)
		);
		self::assertFalse( $c->matches( [ 'g:1' => '10' ] ) );
	}

	public function test_overlaps(): void {
		$any  = Combination::from_array(
			[
				'g:1' => '*',
				'g:2' => '20',
			]
		);
		$red  = Combination::from_array(
			[
				'g:1' => '10',
				'g:2' => '*',
			]
		);
		$blue = Combination::from_array(
			[
				'g:1' => '11',
				'g:2' => '21',
			]
		);

		self::assertTrue( $any->overlaps( $red ) );
		self::assertTrue( $red->overlaps( $any ) );
		self::assertFalse( $any->overlaps( $blue ) );
		self::assertFalse( $red->overlaps( $blue ) );
	}
}
