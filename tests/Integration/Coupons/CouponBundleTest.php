<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidCoupon;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Orders\OrderItem;

/** Bundle coupons: a discount on every complete set of some products (ADR-045). */
final class CouponBundleTest extends CouponTestCase {
	/**
	 * @param list<int>           $products
	 * @param array<string,mixed> $input
	 */
	private function bundle( string $code, string $mode, int $amount, array $products, array $input = [] ): Coupon {
		return $this->coupon(
			$code,
			Coupon::BUNDLE,
			$amount,
			$input + [
				'bundle_mode' => $mode,
				'product_ids' => $products,
			]
		);
	}

	public function test_a_percentage_comes_off_each_complete_set(): void {
		$shirt = $this->simple( 2000 );
		$hat   = $this->simple( 800 );
		$this->bundle( 'kit', Coupon::BUNDLE_PERCENT, 1000, [ $shirt, $hat ] );

		$browser = $this->shopper();
		$this->add( $browser, $shirt, 2 );
		try {
			$browser->cart()->apply_coupon( 0, 'kit' );
			self::fail( 'no hat: no set' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'Coupon "kit" is a bundle offer. Add every product in the bundle to your cart to use it.', $e->getMessage() );
		}

		$this->add( $browser, $hat, 1 );
		$state = $browser->cart()->apply_coupon( 0, 'kit' );
		self::assertSame( 280, $state->discount()->amount, '10 % of one shirt and one hat' );
		$lines = $state->discounts?->applied[0]->lines ?? [];
		self::assertSame( [ 200, 80 ], array_values( $lines ), 'split by the share of the set: the second shirt is not in it' );

		$this->add( $browser, $hat, 1 );
		self::assertSame( 560, $browser->cart()->state( 0 )->discount()->amount, 'two sets' );
	}

	public function test_a_set_price_an_amount_off_and_the_set_limit(): void {
		$shirt = $this->simple( 2000 );
		$hat   = $this->simple( 800 );
		$mug   = $this->simple( 500 );
		$this->bundle( 'trio', Coupon::BUNDLE_PRICE, 2500, [ $shirt, $hat, $mug ], [ 'repeat_limit' => 1 ] );
		$this->bundle( 'pair', Coupon::BUNDLE_AMOUNT, 1000, [ $hat, $mug ] );
		$this->bundle( 'cheap', Coupon::BUNDLE_PRICE, 5000, [ $hat, $mug ] );

		$browser = $this->shopper();
		foreach ( [ $shirt, $hat, $mug ] as $product ) {
			$this->add( $browser, $product, 2 );
		}
		self::assertSame( 800, $browser->cart()->apply_coupon( 0, 'trio' )->discount()->amount, '3300 for 2500, once' );

		$browser = $this->shopper();
		$this->add( $browser, $hat, 1 );
		$this->add( $browser, $mug, 1 );
		self::assertSame( 1000, $browser->cart()->apply_coupon( 0, 'pair' )->discount()->amount );
		$browser->cart()->remove_coupon( 0, 'pair' );
		self::assertSame( 0, $browser->cart()->apply_coupon( 0, 'cheap' )->discount()->amount, 'a set already cheaper keeps its price' );

		$browser = $this->shopper();
		$cheap   = $this->simple( 300 );
		$this->bundle( 'small', Coupon::BUNDLE_AMOUNT, 1000, [ $cheap, $mug ] );
		$this->add( $browser, $cheap, 1 );
		$this->add( $browser, $mug, 1 );
		self::assertSame( 800, $browser->cart()->apply_coupon( 0, 'small' )->discount()->amount, 'never more than the set' );
	}

	public function test_a_variation_and_its_product_each_take_a_unit(): void {
		[ $shirt, $sizes ] = $this->sized();
		$hat               = $this->simple( 800 );
		$this->bundle( 'medium', Coupon::BUNDLE_PERCENT, 5000, [ $hat ], [ 'variant_ids' => [ $sizes['m'] ] ] );
		$this->bundle( 'twoshirts', Coupon::BUNDLE_PERCENT, 5000, [ $shirt ], [ 'variant_ids' => [ $sizes['m'] ] ] );

		$browser = $this->shopper();
		$browser->cart()->add( 0, $shirt, $sizes['s'], 1 );
		$this->add( $browser, $hat, 1 );
		try {
			$browser->cart()->apply_coupon( 0, 'medium' );
			self::fail( 'a small shirt is not the medium one' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'coupon_invalid', $e->reason );
		}
		$browser->cart()->add( 0, $shirt, $sizes['m'], 1 );
		self::assertSame( 1400, $browser->cart()->apply_coupon( 0, 'medium' )->discount()->amount );

		$browser = $this->shopper();
		$browser->cart()->add( 0, $shirt, $sizes['s'], 1 );
		$browser->cart()->add( 0, $shirt, $sizes['m'], 1 );
		self::assertSame( 2000, $browser->cart()->apply_coupon( 0, 'twoshirts' )->discount()->amount, 'the medium fills the variation, the small one the product' );
	}

	public function test_an_automatic_bundle_counts_down_and_the_order_keeps_it(): void {
		$shirt = $this->simple( 2000 );
		$hat   = $this->simple( 800 );
		$mug   = $this->simple( 500 );
		$offer = $this->bundle( 'Starter kit', Coupon::BUNDLE_PRICE, 2500, [ $shirt, $hat, $mug ], [ 'automatic' => true ] );

		$browser = $this->shopper();
		$this->add( $browser, $shirt, 1 );
		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertSame( 2, $data['next_discount']['items'] );
		self::assertSame( 33, $data['next_discount']['percent'] );
		self::assertSame( 'Add 2 more items for this discount: Starter kit.', $data['next_discount']['message'] );

		$this->add( $browser, $hat, 1 );
		$this->add( $browser, $mug, 1 );
		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertNull( $data['next_discount'] );
		self::assertSame( 800, $data['totals']['discount']['amount'] );

		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( 3000, $form['totals']['total']['amount'], '2500 and 500 shipping' );
		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 3000 ] ) )->order;
		self::assertSame( 800, $order->discount_total );
		self::assertSame( 'Starter kit', $this->lines( $order, OrderItem::DISCOUNT )[0]->name );
		self::assertSame( 1, $this->uses( $offer->id ) );
	}

	public function test_the_editor_checks_a_bundle(): void {
		$coupon = $this->bundle(
			'free',
			Coupon::BUNDLE_PRICE,
			0,
			[ 11, 12 ],
			[
				'category_ids' => [ 5 ],
				'item_limit'   => 3,
				'repeat_limit' => 2,
			]
		);
		self::assertSame( [ 0, [], 0, 2, Coupon::BUNDLE_PRICE ], [ $coupon->amount, $coupon->category_ids, $coupon->item_limit, $coupon->repeat_limit, $coupon->bundle_mode ] );
		self::assertSame( Coupon::BUNDLE_PRICE, $coupon->to_array()['bundle_mode'] );
		self::assertFalse( $coupon->is_percentage() );

		$plain = $this->coupon( 'plain', Coupon::BUNDLE, 1500, [ 'variant_ids' => [ 3, 4 ] ] );
		self::assertSame( Coupon::BUNDLE_PERCENT, $plain->bundle_mode, 'a percentage unless told otherwise' );
		self::assertSame( 2, $plain->set_size() );
		$other = $this->coupon( 'other', Coupon::PERCENT, 1000 );
		self::assertSame( '', $other->bundle_mode );

		foreach (
			[
				'one product'  => [ 'product_ids', [ 'product_ids' => [ 11 ] ] ],
				'no pricing'   => [ 'bundle_mode', [ 'bundle_mode' => 'half' ] ],
				'over 100 %'   => [ 'amount', [ 'amount' => 10001 ] ],
				'nothing off'  => [ 'amount', [ 'amount' => 0 ] ],
				'too many'     => [ 'product_ids', [ 'product_ids' => range( 1, Coupon::MAX_BUNDLE + 1 ) ] ],
				'zero off set' => [
					'amount',
					[
						'amount'      => 0,
						'bundle_mode' => Coupon::BUNDLE_AMOUNT,
					],
				],
			] as $case => [ $field, $input ]
		) {
			try {
				$this->coupons()->editor()->save(
					$input + [
						'code'        => 'bad',
						'type'        => Coupon::BUNDLE,
						'amount'      => 1000,
						'product_ids' => [ 11, 12 ],
					]
				);
				self::fail( $case . ' is refused' );
			} catch ( InvalidCoupon $e ) {
				self::assertSame( $field, $e->field, $case );
			}
		}
	}
}
