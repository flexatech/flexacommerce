<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Contracts\Exceptions\InvalidCoupon;
use Flexa\Commerce\Coupons\Coupon;

/** A percent or per-product coupon limited to a number of items, the dearest first. */
final class CouponItemLimitTest extends CouponTestCase {
	public function test_the_limit_counts_units_from_the_dearest_line(): void {
		$this->coupon( 'HALF3', Coupon::PERCENT, 5000, [ 'item_limit' => 3 ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );
		$this->add( $browser, $this->simple( 3000 ), 1 );
		$this->add( $browser, $this->simple( 500 ), 4 );

		$state = $browser->cart()->apply_coupon( 0, 'half3' );
		$keys  = array_map( static fn ( $i ): string => $i->line->key, $state->items );
		self::assertSame( [ 1000, 1500, 0 ], array_map( static fn ( string $k ): int => $state->line_discount( $k ), $keys ) );
		self::assertSame( 2500, $state->discount()->amount );
	}

	public function test_a_line_counted_in_part_is_discounted_on_its_share(): void {
		$this->coupon( 'HALF2', Coupon::PERCENT, 5000, [ 'item_limit' => 2 ] );
		$this->coupon( 'UNIT', Coupon::FIXED_PRODUCT, 500, [ 'item_limit' => 1 ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 3 );
		self::assertSame( 1000, $browser->cart()->apply_coupon( 0, 'half2' )->discount()->amount, 'half of two of the three units' );

		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 300 ), 3 );
		self::assertSame( 300, $browser->cart()->apply_coupon( 0, 'unit' )->discount()->amount, 'one unit, capped at its price' );
	}

	public function test_the_limit_is_saved_for_item_coupons_only(): void {
		$coupon = $this->coupon( 'TWO', Coupon::PERCENT, 1000, [ 'item_limit' => '2' ] );
		self::assertSame( 2, $coupon->item_limit );
		self::assertSame( 2, $coupon->to_array()['item_limit'] );

		$cart = $this->coupons()->editor()->save( [ 'type' => Coupon::FIXED_CART ], $coupon->id );
		self::assertSame( 0, $cart->item_limit, 'a cart discount has no item limit' );

		$this->expectException( InvalidCoupon::class );
		$this->coupons()->editor()->save( [ 'item_limit' => -1 ], $coupon->id );
	}
}
