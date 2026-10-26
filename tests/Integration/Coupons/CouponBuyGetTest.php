<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidCoupon;
use Flexa\Commerce\Coupons\Coupon;

/** Buy X get Y coupons: the sets they form and the units they discount. */
final class CouponBuyGetTest extends CouponTestCase {
	public function test_the_cheapest_unit_of_each_set_is_free(): void {
		$this->coupon(
			'B2G1',
			Coupon::BUY_X_GET_Y,
			Coupon::FULL_PERCENT,
			[
				'buy_qty' => 2,
				'get_qty' => 1,
			]
		);
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );
		$this->add( $browser, $this->simple( 500 ), 1 );
		self::assertSame( 500, $browser->cart()->apply_coupon( 0, 'b2g1' )->discount()->amount );

		$this->add( $browser, $this->simple( 300 ), 3 );
		$state = $browser->cart()->state( 0 );
		$keys  = array_map( static fn ( $i ): string => $i->line->key, $state->items );
		self::assertSame( [ 0, 0, 600 ], array_map( static fn ( string $k ): int => $state->line_discount( $k ), $keys ), 'six units make two sets, both free units from the cheapest line' );
		self::assertSame( 600, $state->discount()->amount );
	}

	public function test_the_repeat_limit_caps_the_sets(): void {
		$this->coupon(
			'HALFONE',
			Coupon::BUY_X_GET_Y,
			5000,
			[
				'buy_qty'      => 1,
				'get_qty'      => 1,
				'repeat_limit' => 1,
			]
		);
		$this->coupon(
			'HALFALL',
			Coupon::BUY_X_GET_Y,
			5000,
			[
				'buy_qty' => 1,
				'get_qty' => 1,
			]
		);
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 6 );
		self::assertSame( 500, $browser->cart()->apply_coupon( 0, 'halfone' )->discount()->amount );

		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 6 );
		self::assertSame( 1500, $browser->cart()->apply_coupon( 0, 'halfall' )->discount()->amount );
	}

	public function test_the_get_lists_name_what_is_discounted(): void {
		$shirt = $this->simple( 2000 );
		$mug   = $this->simple( 800 );
		$mugs  = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Mugs',
			]
		);
		wp_set_object_terms( $mug, [ $mugs ], PostTypes::CATEGORY );
		$this->coupon(
			'SHIRTMUG',
			Coupon::BUY_X_GET_Y,
			Coupon::FULL_PERCENT,
			[
				'buy_qty'          => 1,
				'get_qty'          => 1,
				'product_ids'      => [ $shirt ],
				'get_category_ids' => [ $mugs ],
			]
		);

		$browser = $this->shopper();
		$this->add( $browser, $shirt, 1 );
		try {
			$browser->cart()->apply_coupon( 0, 'shirtmug' );
			self::fail( 'no mug, no set' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'coupon_invalid', $e->reason );
		}

		$this->add( $browser, $mug, 2 );
		$state = $browser->cart()->apply_coupon( 0, 'shirtmug' );
		self::assertSame( 800, $state->discount()->amount, 'one shirt bought, one mug free' );
	}

	public function test_the_editor_asks_for_both_quantities(): void {
		try {
			$this->coupon( 'NOSET', Coupon::BUY_X_GET_Y, Coupon::FULL_PERCENT, [ 'buy_qty' => 2 ] );
			self::fail( 'a set needs the units it gives' );
		} catch ( InvalidCoupon $e ) {
			self::assertSame( 'get_qty', $e->field );
		}

		$coupon = $this->coupon(
			'SET',
			Coupon::BUY_X_GET_Y,
			5000,
			[
				'buy_qty'          => '3',
				'get_qty'          => 1,
				'repeat_limit'     => 2,
				'item_limit'       => 4,
				'get_product_ids'  => [ 7, '7', 8 ],
				'get_variant_ids'  => [ 9 ],
				'get_category_ids' => [ 10 ],
			]
		);
		self::assertSame( [ 3, 1, 2, 0 ], [ $coupon->buy_qty, $coupon->get_qty, $coupon->repeat_limit, $coupon->item_limit ] );
		$row = $coupon->to_array();
		self::assertSame( [ 7, 8 ], $row['get_product_ids'] );
		self::assertSame( [ 9 ], $row['get_variant_ids'] );
		self::assertSame( [ 10 ], $row['get_category_ids'] );

		$percent = $this->coupons()->editor()->save( [ 'type' => Coupon::PERCENT ], $coupon->id );
		self::assertSame( [ 0, 0, 0, [] ], [ $percent->buy_qty, $percent->get_qty, $percent->repeat_limit, $percent->get_product_ids ], 'other types keep no set' );

		$this->expectException( InvalidCoupon::class );
		$this->coupons()->editor()->save(
			[
				'type'    => Coupon::BUY_X_GET_Y,
				'buy_qty' => 1,
				'get_qty' => 1,
				'amount'  => 10001,
			],
			$coupon->id
		);
	}
}
