<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Contracts\Exceptions\InvalidCoupon;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\Methods\FreeShipping;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Storefront\Render\CheckoutForm;

/** Coupons that give free shipping, and the free shipping method that asks for one. */
final class CouponFreeShippingTest extends CouponTestCase {
	private string $free_id = '';

	public function set_up(): void {
		parent::set_up();
		$zones         = ( new ZoneRepository() )->save(
			[
				new Zone(
					0,
					'US',
					[ 'country:US' ],
					[],
					[
						new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ),
						new MethodInstance( 0, 'free_shipping', true, [ 'requires' => 'coupon' ] ),
					]
				),
			]
		);
		$this->rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
		$this->free_id = 'free_shipping:' . $zones[0]->methods[1]->instance_id;
	}

	public function test_the_amount_may_be_zero_only_with_free_shipping(): void {
		try {
			$this->coupon( 'NOTHING', Coupon::FIXED_CART, 0 );
			self::fail( 'a coupon that gives nothing is refused' );
		} catch ( InvalidCoupon $e ) {
			self::assertSame( 'amount', $e->field );
		}

		$coupon = $this->coupon( 'SHIPFREE', Coupon::FIXED_CART, 0, [ 'free_shipping' => true ] );
		self::assertTrue( $coupon->free_shipping );
		self::assertSame( 0, $coupon->amount );
		self::assertTrue( $coupon->to_array()['free_shipping'] );

		$this->expectException( InvalidCoupon::class );
		$this->coupons()->editor()->save( [ 'free_shipping' => false ], $coupon->id );
	}

	/** @return array<string,array{0:string,1:bool,2:int,3:bool}> */
	public static function requirements(): array {
		return [
			'none, no minimum'          => [ '', false, 0, true ],
			'none, minimum not met'     => [ '', true, 5000, false ],
			'coupon, without one'       => [ 'coupon', false, 0, false ],
			'coupon, with one'          => [ 'coupon', true, 0, true ],
			'either, coupon only'       => [ 'either', true, 5000, true ],
			'either, minimum met'       => [ 'either', false, 1000, true ],
			'either, neither'           => [ 'either', false, 5000, false ],
			'both, coupon below min'    => [ 'both', true, 5000, false ],
			'both, coupon and minimum'  => [ 'both', true, 1000, true ],
			'both, minimum, no coupon'  => [ 'both', false, 1000, false ],
		];
	}

	/** @dataProvider requirements */
	public function test_the_method_follows_what_it_requires( string $requires, bool $coupon, int $min, bool $free ): void {
		$package = new ShippingPackage( new Destination( 'US' ), [], new Money( 2000, 'USD' ), $coupon );
		$rates   = ( new FreeShipping() )->rates(
			$package,
			[
				'requires'   => $requires,
				'min_amount' => $min,
			],
			7
		);
		self::assertSame( $free, $rates !== [] );
	}

	public function test_the_coupon_is_part_of_the_package_hash(): void {
		$lines = new ShippingPackage( new Destination( 'US' ), [], new Money( 2000, 'USD' ) );
		$free  = new ShippingPackage( new Destination( 'US' ), [], new Money( 2000, 'USD' ), true );
		self::assertNotSame( $lines->hash(), $free->hash() );
	}

	public function test_an_order_with_a_free_shipping_coupon(): void {
		$coupon  = $this->coupon( 'SHIPFREE', Coupon::FIXED_CART, 0, [ 'free_shipping' => true ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );

		$form = $this->checkout( $browser )->form_state( 0 );
		self::assertSame( [ $this->rate_id ], array_column( $form['shipping_rates'], 'id' ), 'no coupon, no free shipping' );

		$browser->cart()->apply_coupon( 0, 'shipfree' );
		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( [ $this->rate_id, $this->free_id ], array_column( $form['shipping_rates'], 'id' ) );
		$row = $form['coupons'][0];
		self::assertTrue( $row['valid'] );
		self::assertTrue( $row['free_shipping'] );
		self::assertSame( 0, $row['discount']['amount'] );
		self::assertSame( 'Free shipping', CheckoutForm::coupon_amount( $row ) );

		$order = $checkout->place_order(
			$this->request(
				$form['checkout_token'],
				[
					'shipping_rate'  => $this->free_id,
					'expected_total' => 2000,
				]
			)
		)->order;
		self::assertSame( 0, $order->shipping_total );
		self::assertSame( 0, $order->discount_total );
		self::assertSame( 2000, $order->total );
		$discounts = $this->lines( $order, OrderItem::DISCOUNT );
		self::assertSame( [ 'SHIPFREE' ], array_map( static fn ( OrderItem $i ): string => $i->name, $discounts ) );
		self::assertSame( 0, $discounts[0]->total );
		self::assertTrue( $discounts[0]->meta['free_shipping'] );
		self::assertSame( 1, $this->uses( $coupon->id ), 'a free-shipping coupon counts as used' );
	}

	public function test_a_discount_coupon_with_free_shipping_shows_the_discount(): void {
		$this->coupon( 'TENFREE', Coupon::PERCENT, 1000, [ 'free_shipping' => true ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$browser->cart()->apply_coupon( 0, 'tenfree' );

		$form = $this->checkout( $browser )->form_state( 0 );
		self::assertContains( $this->free_id, array_column( $form['shipping_rates'], 'id' ) );
		self::assertSame( '-$1.00', CheckoutForm::coupon_amount( $form['coupons'][0] ) );
	}

	public function test_the_cart_page_shows_free_shipping_for_the_code(): void {
		$this->coupon( 'SHIPFREE', Coupon::FIXED_CART, 0, [ 'free_shipping' => true ] );
		$cart = \Flexa\Commerce\Plugin::instance()->cart()->cart();
		$cart->add( 0, $id = $this->simple( 1000 ), $this->service()->get( $id )->base->id, 1 );
		$cart->apply_coupon( 0, 'shipfree' );

		$html = do_blocks( '<!-- wp:flexacommerce/cart /-->' );
		self::assertStringContainsString( 'data-wp-text="state.couponAmount">Free shipping</td>', $html );
	}
}
