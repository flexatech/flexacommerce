<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Coupons\Coupon;

/** Codes in the cart: the discount rules and the reasons a code is refused. */
final class CouponCartTest extends CouponTestCase {
	public function test_coupons_apply_in_order_on_what_the_earlier_ones_left(): void {
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$this->coupon( 'Five', Coupon::FIXED_CART, 500 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );

		$state = $browser->cart()->apply_coupon( 0, ' ten ' );
		self::assertSame( [ 'ten' ], $state->cart->coupons );
		self::assertSame( 200, $state->discount()->amount );

		$state = $browser->cart()->apply_coupon( 0, 'FIVE' );
		self::assertSame( 700, $state->discount()->amount );
		self::assertSame( 1300, $state->discounted_subtotal()->amount );

		$rows = $browser->presenter()->coupons( $state );
		self::assertSame( [ 'TEN', 'Five' ], array_column( $rows, 'label' ) );
		self::assertSame( [ 200, 500 ], array_map( static fn ( array $r ): int => $r['discount']['amount'], $rows ) );

		// Percent first, fixed second: in the other order the percent applies to 1500.
		$browser->cart()->remove_coupon( 0, 'ten' );
		$state = $browser->cart()->apply_coupon( 0, 'ten' );
		self::assertSame( 650, $state->discount()->amount );

		self::assertSame( 'coupon_applied', $this->refusal( $browser, 'TEN' ) );
	}

	public function test_fixed_cart_splits_over_lines_and_never_exceeds_them(): void {
		$this->coupon( 'BIG', Coupon::FIXED_CART, 99900 );
		$this->coupon( 'SPLIT', Coupon::FIXED_CART, 300 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$this->add( $browser, $this->simple( 2000 ), 1 );

		$state = $browser->cart()->apply_coupon( 0, 'split' );
		$keys  = array_map( static fn ( $i ): string => $i->line->key, $state->items );
		self::assertSame( 100, $state->line_discount( $keys[0] ) );
		self::assertSame( 200, $state->line_discount( $keys[1] ) );

		$state = $browser->cart()->apply_coupon( 0, 'big' );
		self::assertSame( 3000, $state->discount()->amount, 'capped at the cart' );
		self::assertSame( 0, $state->discounted_subtotal()->amount );
	}

	public function test_fixed_product_is_per_unit_and_capped_at_the_line(): void {
		$this->coupon( 'UNIT', Coupon::FIXED_PRODUCT, 300 );
		$this->coupon( 'HUGE', Coupon::FIXED_PRODUCT, 5000 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 3 );

		self::assertSame( 900, $browser->cart()->apply_coupon( 0, 'unit' )->discount()->amount );
		self::assertSame( 3000, $browser->cart()->apply_coupon( 0, 'huge' )->discount()->amount );
	}

	public function test_product_category_and_sale_rules_pick_the_lines(): void {
		$plain = $this->simple( 1000 );
		$sale  = $this->simple( 1000 );
		$this->save( $sale, [ 'base' => [ 'sale_price' => 800 ] ] );
		$parent = self::factory()->term->create( [ 'taxonomy' => 'fxc_product_cat' ] );
		$child  = self::factory()->term->create(
			[
				'taxonomy' => 'fxc_product_cat',
				'parent'   => $parent,
			]
		);
		$this->track_term( $parent );
		$this->track_term( $child );
		wp_set_object_terms( $plain, [ $child ], 'fxc_product_cat' );

		$this->coupon( 'ONLY', Coupon::PERCENT, 5000, [ 'product_ids' => [ $plain ] ] );
		$this->coupon( 'NOSALE', Coupon::PERCENT, 5000, [ 'exclude_sale_items' => true ] );
		$this->coupon( 'CAT', Coupon::PERCENT, 5000, [ 'category_ids' => [ $parent ] ] );
		$this->coupon( 'NOTCAT', Coupon::PERCENT, 5000, [ 'exclude_category_ids' => [ $parent ] ] );

		foreach ( [ 'only', 'nosale', 'cat' ] as $code ) {
			$browser = $this->shopper();
			$this->add( $browser, $plain, 1 );
			$this->add( $browser, $sale, 1 );
			self::assertSame( 500, $browser->cart()->apply_coupon( 0, $code )->discount()->amount, $code );
		}

		$browser = $this->shopper();
		$this->add( $browser, $plain, 1 );
		$this->add( $browser, $sale, 1 );
		self::assertSame( 400, $browser->cart()->apply_coupon( 0, 'notcat' )->discount()->amount );

		$browser = $this->shopper();
		$this->add( $browser, $sale, 1 );
		self::assertSame( 'coupon_invalid', $this->refusal( $browser, 'only' ) );
	}

	public function test_codes_that_do_not_apply_are_refused_with_the_reason(): void {
		$this->coupon( 'OFF', Coupon::PERCENT, 1000, [ 'status' => Coupon::DISABLED ] );
		$this->coupon( 'OLD', Coupon::PERCENT, 1000, [ 'expires_at' => '2020-01-01 00:00:00' ] );
		$this->coupon( 'SOON', Coupon::PERCENT, 1000, [ 'starts_at' => '2099-01-01 00:00:00' ] );
		$this->coupon( 'MIN', Coupon::PERCENT, 1000, [ 'min_subtotal' => 5000 ] );
		$this->coupon( 'MAX', Coupon::PERCENT, 1000, [ 'max_subtotal' => 500 ] );
		$browser = $this->shopper();

		self::assertSame( 'coupon_invalid', $this->refusal( $browser, 'nope' ), 'an empty cart' );
		$this->add( $browser, $this->simple( 1000 ), 1 );

		$messages = [];
		foreach ( [ 'nope', 'off', 'old', 'soon', 'min', 'max' ] as $code ) {
			try {
				$browser->cart()->apply_coupon( 0, $code );
				self::fail( "{$code} was accepted." );
			} catch ( InvalidCartRequest $e ) {
				$messages[ $code ] = $e->getMessage();
			}
		}
		self::assertStringContainsString( 'does not exist', $messages['nope'] );
		self::assertStringContainsString( 'does not exist', $messages['off'], 'a disabled code reads as unknown' );
		self::assertStringContainsString( 'expired', $messages['old'] );
		self::assertStringContainsString( 'cannot be used yet', $messages['soon'] );
		self::assertStringContainsString( 'minimum spend', $messages['min'] );
		self::assertStringContainsString( 'maximum spend', $messages['max'] );
		self::assertSame( 'coupon_empty', $this->refusal( $browser, '  ' ) );
		self::assertSame( [], $browser->cart()->state( 0 )->cart->coupons );
	}

	public function test_a_code_that_stops_applying_stays_in_the_cart_with_its_message(): void {
		$coupon  = $this->coupon( 'MIN', Coupon::PERCENT, 1000, [ 'min_subtotal' => 1500 ] );
		$browser = $this->shopper();
		$product = $this->simple( 1000 );
		$this->add( $browser, $product, 2 );
		$state = $browser->cart()->apply_coupon( 0, 'min' );
		self::assertSame( 200, $state->discount()->amount );

		$state = $browser->cart()->set_quantity( 0, $state->items[0]->line->key, 1 );
		self::assertSame( 0, $state->discount()->amount );
		self::assertTrue( $state->has_rejected_coupons() );
		$row = $browser->presenter()->coupons( $state )[0];
		self::assertFalse( $row['valid'] );
		self::assertStringContainsString( 'minimum spend', $row['message'] );

		$this->coupons()->editor()->save( [ 'min_subtotal' => null ], $coupon->id );
		self::assertSame( 100, $browser->cart()->state( 0 )->discount()->amount );
	}

	public function test_individual_use_replaces_the_other_codes(): void {
		$this->coupon( 'A', Coupon::PERCENT, 1000 );
		$this->coupon( 'B', Coupon::PERCENT, 1000 );
		$this->coupon( 'SOLO', Coupon::PERCENT, 2000, [ 'individual_use' => true ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );

		$browser->cart()->apply_coupon( 0, 'a' );
		$browser->cart()->apply_coupon( 0, 'b' );
		$state = $browser->cart()->apply_coupon( 0, 'solo' );
		self::assertSame( [ 'solo' ], $state->cart->coupons );
		self::assertSame( 200, $state->discount()->amount );
		self::assertSame( 'coupon_invalid', $this->refusal( $browser, 'a' ) );
	}

	public function test_remove_and_merge_on_login(): void {
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$browser = $this->shopper();
		$product = $this->simple( 1000 );
		$this->add( $browser, $product, 1 );
		$browser->cart()->apply_coupon( 0, 'ten' );

		try {
			$browser->cart()->remove_coupon( 0, 'other' );
			self::fail( 'An unknown code was removed.' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'unknown_coupon', $e->reason );
		}

		// The account already has a cart from another device; logging in here merges.
		$user = self::factory()->user->create();
		$this->add( $this->shopper(), $product, 2, $user );
		$state = $browser->cart()->state( $user );
		self::assertSame( 3, $state->item_count() );
		self::assertSame( [ 'ten' ], $state->cart->coupons, 'the guest code moves to the account cart' );
		self::assertSame( 300, $state->discount()->amount );
		self::assertSame( [], $browser->cart()->remove_coupon( $user, 'TEN' )->cart->coupons );
	}

	public function test_a_cart_without_codes_never_builds_the_coupon_service(): void {
		$built   = false;
		$browser = new CartServices(
			\Flexa\Commerce\Plugin::instance()->services(),
			\Flexa\Commerce\Plugin::instance()->catalog(),
			new \Flexa\Commerce\Cart\MemoryTokenStore(),
			coupons: function () use ( &$built ): \Flexa\Commerce\Coupons\CouponService {
				$built = true;
				return $this->coupons()->service();
			},
		);
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$browser->cart()->state( 0 );
		self::assertFalse( $built );
	}

	private function refusal( CartServices $browser, string $code ): string {
		try {
			$browser->cart()->apply_coupon( 0, $code );
		} catch ( InvalidCartRequest $e ) {
			return $e->reason;
		}
		self::fail( "{$code} was accepted." );
	}
}
