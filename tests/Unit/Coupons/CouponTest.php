<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Coupons;

use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Tests\Unit\TestCase;

final class CouponTest extends TestCase {
	public function test_codes_compare_trimmed_and_case_insensitive(): void {
		self::assertSame( 'été10', Coupon::normalize( '  ÉTÉ10 ' ) );
		$coupon = new Coupon( 1, 'Spring', Coupon::PERCENT, 1000 );
		self::assertTrue( $coupon->matches( ' SPRING' ) );
		self::assertFalse( $coupon->matches( 'spring2' ) );
	}

	public function test_email_rules_allow_wildcards(): void {
		$open = new Coupon( 1, 'A', Coupon::PERCENT, 1000 );
		self::assertTrue( $open->allows_email( '' ), 'no rule allows everyone' );

		$staff = new Coupon( 1, 'A', Coupon::PERCENT, 1000, emails: [ '*@staff.test', 'ada@example.com' ] );
		self::assertTrue( $staff->allows_email( 'Grace@Staff.test' ) );
		self::assertTrue( $staff->allows_email( ' ada@example.com ' ) );
		self::assertFalse( $staff->allows_email( 'ada@example.com.evil' ) );
		self::assertFalse( $staff->allows_email( 'grace@staff.test.evil' ) );
		self::assertFalse( $staff->allows_email( '' ) );

		$dots = new Coupon( 1, 'A', Coupon::PERCENT, 1000, emails: [ 'a.b@x.test' ] );
		self::assertFalse( $dots->allows_email( 'aXb@x.test' ), 'only * is a wildcard' );
	}

	public function test_row_round_trip_keeps_the_rules(): void {
		$coupon = Coupon::from_row(
			[
				'id'                 => '7',
				'code'               => 'TEN',
				'type'               => Coupon::FIXED_CART,
				'amount'             => '500',
				'min_subtotal'       => '1000',
				'max_subtotal'       => null,
				'individual_use'     => '1',
				'rules'              => '{"product_ids":[3,"4"],"category_ids":[9],"emails":["*@x.test"]}',
				'per_customer_limit' => '2',
			]
		);
		self::assertSame( 7, $coupon->id );
		self::assertSame( 1000, $coupon->min_subtotal );
		self::assertNull( $coupon->max_subtotal );
		self::assertTrue( $coupon->individual_use );
		self::assertSame( [ 3, 4 ], $coupon->product_ids );
		self::assertTrue( $coupon->has_category_rules() );
		self::assertSame( [ '*@x.test' ], $coupon->rules()['emails'] );

		$broken = Coupon::from_row(
			[
				'id'     => 1,
				'code'   => 'X',
				'type'   => Coupon::PERCENT,
				'amount' => 1,
				'rules'  => 'not json',
			]
		);
		self::assertSame( [], $broken->product_ids );
		self::assertFalse( $broken->has_category_rules() );
	}
}
