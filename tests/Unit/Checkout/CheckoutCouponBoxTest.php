<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Checkout;

use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckoutLayout;
use Flexa\Commerce\Tests\Unit\TestCase;

/** The coupon code box of the order summary (doc 11 §13). */
final class CheckoutCouponBoxTest extends TestCase {
	public function test_the_box_shows_by_default_and_a_damaged_value_keeps_it(): void {
		self::assertTrue( CheckoutLayout::default()->takes_coupons() );
		self::assertSame( 'show', CheckoutLayout::default()->to_array()['page']['coupons'] );
		self::assertFalse( CheckoutLayout::from_array( [ 'page' => [ 'coupons' => 'hide' ] ] )->takes_coupons() );
		self::assertTrue( CheckoutLayout::from_array( [ 'page' => [ 'coupons' => 'maybe' ] ] )->takes_coupons() );
	}

	public function test_the_builder_gets_a_value_it_does_not_know(): void {
		try {
			CheckoutLayout::save( [ 'page' => [ 'coupons' => 'maybe' ] ] );
			self::fail( 'saved' );
		} catch ( InvalidCheckoutLayout $e ) {
			self::assertSame( 'page.coupons', $e->field );
		}
	}
}
