<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Contracts\Orders\CustomerHistory;
use Flexa\Commerce\Support\ConditionFacts;
use Flexa\Commerce\Support\Conditions;
use Flexa\Commerce\Tests\Unit\TestCase;

/** Conditions of rules and coupons (ADR-041): cleaning and the three answers. */
final class ConditionsTest extends TestCase {
	/** @param list<array<string,mixed>> $items */
	private static function block( array $items, string $match = Conditions::ALL ): array {
		return Conditions::clean(
			[
				'match' => $match,
				'items' => $items,
			]
		);
	}

	private static function history( int $orders, int $spent, array $bought = [] ): \Closure {
		$history = new class( $orders, $spent, $bought ) implements CustomerHistory {
			/** @param list<int> $bought */
			public function __construct( private int $orders, private int $spent, private array $bought ) {}

			public function order_count( int $user_id ): int {
				return $this->orders;
			}

			public function spent( int $user_id ): int {
				return $this->spent;
			}

			public function bought( int $user_id, array $product_ids ): bool {
				return array_intersect( $product_ids, $this->bought ) !== [];
			}
		};
		return static fn (): CustomerHistory => $history;
	}

	public function test_empty_input_is_no_conditions(): void {
		self::assertSame( Conditions::none(), Conditions::clean( null ) );
		self::assertSame( Conditions::none(), Conditions::clean( [] ) );
		self::assertTrue( Conditions::is_empty( Conditions::clean( '' ) ) );
		self::assertTrue( Conditions::passes( Conditions::none(), new ConditionFacts() ) );
	}

	public function test_clean_normalizes_values(): void {
		$block = self::block(
			[
				[
					'type'  => 'country',
					'op'    => 'in',
					'value' => 'us, de us',
				],
				[
					'type'  => 'coupon',
					'op'    => 'not_in',
					'value' => [ 'SUMMER', 'summer' ],
				],
				[
					'type'  => 'cart_weight',
					'op'    => 'gte',
					'value' => '1500',
				],
				[
					'type'  => 'purchased',
					'op'    => 'in',
					'value' => [ '12', 7 ],
				],
			]
		);
		self::assertSame( [ 'US', 'DE' ], $block['items'][0]['value'] );
		self::assertSame( [ 'summer' ], $block['items'][1]['value'] );
		self::assertSame( 1500, $block['items'][2]['value'] );
		self::assertSame( [ 12, 7 ], $block['items'][3]['value'] );
	}

	/** @return iterable<string,array{mixed}> */
	public static function invalid(): iterable {
		yield 'not an array' => [ 'yes' ];
		yield 'bad match' => [
			[
				'match' => 'some',
				'items' => [],
			],
		];
		yield 'unknown type' => [
			[
				'items' => [
					[
						'type'  => 'moon',
						'op'    => 'in',
						'value' => [ 'x' ],
					],
				],
			],
		];
		yield 'list op on a number' => [
			[
				'items' => [
					[
						'type'  => 'cart_quantity',
						'op'    => 'in',
						'value' => 2,
					],
				],
			],
		];
		yield 'negative number' => [
			[
				'items' => [
					[
						'type'  => 'order_count',
						'op'    => 'gte',
						'value' => -1,
					],
				],
			],
		];
		yield 'unknown country' => [
			[
				'items' => [
					[
						'type'  => 'country',
						'op'    => 'in',
						'value' => [ 'XX' ],
					],
				],
			],
		];
		yield 'bad gateway id' => [
			[
				'items' => [
					[
						'type'  => 'payment_method',
						'op'    => 'in',
						'value' => [ 'Bank Transfer' ],
					],
				],
			],
		];
		yield 'empty list' => [
			[
				'items' => [
					[
						'type'  => 'shipping_method',
						'op'    => 'in',
						'value' => [],
					],
				],
			],
		];
		yield 'too many' => [
			[
				'items' => array_fill(
					0,
					11,
					[
						'type'  => 'cart_quantity',
						'op'    => 'gte',
						'value' => 1,
					]
				),
			],
		];
	}

	/** @dataProvider invalid */
	public function test_clean_refuses( mixed $value ): void {
		$this->expectException( \InvalidArgumentException::class );
		Conditions::clean( $value );
	}

	public function test_numbers(): void {
		$block = self::block(
			[
				[
					'type'  => 'cart_quantity',
					'op'    => 'gte',
					'value' => 3,
				],
				[
					'type'  => 'cart_weight',
					'op'    => 'lte',
					'value' => 2000,
				],
			]
		);
		self::assertTrue( Conditions::passes( $block, new ConditionFacts( quantity: 3, weight_g: 2000 ) ) );
		self::assertFalse( Conditions::passes( $block, new ConditionFacts( quantity: 2, weight_g: 100 ) ) );
		self::assertFalse( Conditions::passes( $block, new ConditionFacts( quantity: 5, weight_g: 2001 ) ) );
		$outcome = Conditions::outcome( $block, new ConditionFacts( quantity: 5, weight_g: 2001 ) );
		self::assertSame( 'cart_weight', $outcome['item']['type'] ?? null );
	}

	public function test_unknown_facts_wait_until_final(): void {
		$block = self::block(
			[
				[
					'type'  => 'payment_method',
					'op'    => 'in',
					'value' => [ 'bacs' ],
				],
			]
		);
		self::assertNull( Conditions::passes( $block, new ConditionFacts() ) );
		self::assertFalse( Conditions::passes( $block, new ConditionFacts( final: true ) ) );
		self::assertTrue( Conditions::passes( $block, new ConditionFacts( payment_method: 'bacs' ) ) );
		self::assertFalse( Conditions::passes( $block, new ConditionFacts( payment_method: 'cod' ) ) );
	}

	public function test_all_and_any_with_unknowns(): void {
		$items = [
			[
				'type'  => 'country',
				'op'    => 'in',
				'value' => [ 'US' ],
			],
			[
				'type'  => 'cart_quantity',
				'op'    => 'gte',
				'value' => 2,
			],
		];
		$all   = self::block( $items );
		$any   = self::block( $items, Conditions::ANY );
		// A failed one decides "all" even with an unknown before it.
		self::assertFalse( Conditions::passes( $all, new ConditionFacts( quantity: 1 ) ) );
		self::assertNull( Conditions::passes( $all, new ConditionFacts( quantity: 2 ) ) );
		// A met one decides "any".
		self::assertTrue( Conditions::passes( $any, new ConditionFacts( quantity: 2 ) ) );
		self::assertNull( Conditions::passes( $any, new ConditionFacts( quantity: 1 ) ) );
		self::assertFalse( Conditions::passes( $any, new ConditionFacts( quantity: 1, country: 'DE' ) ) );
	}

	public function test_shipping_by_type_or_one_method(): void {
		$type = self::block(
			[
				[
					'type'  => 'shipping_method',
					'op'    => 'in',
					'value' => [ 'flat_rate' ],
				],
			]
		);
		$one  = self::block(
			[
				[
					'type'  => 'shipping_method',
					'op'    => 'not_in',
					'value' => [ 'flat_rate:3' ],
				],
			]
		);
		self::assertTrue( Conditions::passes( $type, new ConditionFacts( shipping_rate: 'flat_rate:3' ) ) );
		self::assertTrue( Conditions::passes( $type, new ConditionFacts( shipping_rate: 'flat_rate:3:express' ) ) );
		self::assertFalse( Conditions::passes( $type, new ConditionFacts( shipping_rate: 'flat_rate_x:1' ) ) );
		self::assertFalse( Conditions::passes( $one, new ConditionFacts( shipping_rate: 'flat_rate:3' ) ) );
		self::assertTrue( Conditions::passes( $one, new ConditionFacts( shipping_rate: 'flat_rate:30' ) ) );
	}

	public function test_coupons_in_the_cart(): void {
		$block = self::block(
			[
				[
					'type'  => 'coupon',
					'op'    => 'not_in',
					'value' => [ 'vip' ],
				],
			]
		);
		self::assertTrue( Conditions::passes( $block, new ConditionFacts( coupons: [ 'summer' ] ) ) );
		self::assertFalse( Conditions::passes( $block, new ConditionFacts( coupons: [ 'summer', 'vip' ] ) ) );
	}

	public function test_history_and_guests(): void {
		$block   = self::block(
			[
				[
					'type'  => 'order_count',
					'op'    => 'lte',
					'value' => 0,
				],
				[
					'type'  => 'lifetime_spend',
					'op'    => 'lte',
					'value' => 5000,
				],
			]
		);
		$bought  = self::block(
			[
				[
					'type'  => 'purchased',
					'op'    => 'in',
					'value' => [ 4 ],
				],
			]
		);
		$history = self::history( 0, 1000, [ 4 ] );
		$facts   = ConditionFacts::shopper( 7, $history );
		self::assertTrue( Conditions::passes( $block, $facts ) );
		self::assertTrue( Conditions::passes( $bought, $facts ) );
		self::assertFalse( Conditions::passes( $bought, ConditionFacts::shopper( 0, $history ) ) );
		self::assertFalse( Conditions::passes( $block, ConditionFacts::shopper( 7, self::history( 2, 0 ) ) ) );
		self::assertTrue( Conditions::passes( $block, ConditionFacts::shopper( 0, null ) ) );
	}
}
