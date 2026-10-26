<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponRepository;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Plugin;

/** Discounts that apply without a code, and the nudge toward the next one (ADR-026). */
final class AutomaticCouponTest extends CouponTestCase {
	public function test_it_applies_without_a_code_and_reaches_the_order(): void {
		$sale    = $this->coupon( 'Spring sale', Coupon::PERCENT, 1000, [ 'automatic' => true ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );

		$state = $browser->cart()->state( 0 );
		self::assertSame( [], $state->cart->coupons );
		self::assertSame( 200, $state->discount()->amount );

		$data = $browser->presenter()->to_array( $state );
		self::assertSame( 200, $data['totals']['discount']['amount'] );
		self::assertCount( 1, $data['coupons'] );
		self::assertSame( 'Spring sale', $data['coupons'][0]['label'] );
		self::assertTrue( $data['coupons'][0]['automatic'] );
		self::assertNull( $data['next_discount'] );

		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( 2300, $form['totals']['total']['amount'], '1800 and 500 shipping' );
		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 2300 ] ) )->order;
		self::assertSame( 200, $order->discount_total );
		$lines = $this->lines( $order, OrderItem::DISCOUNT );
		self::assertSame( [ 'Spring sale' ], array_map( static fn ( OrderItem $i ): string => $i->name, $lines ) );
		self::assertTrue( $lines[0]->meta['automatic'] );
		self::assertSame( 1, $this->uses( $sale->id ) );
	}

	public function test_an_empty_cart_or_a_cart_without_one_keeps_the_old_shape(): void {
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertArrayNotHasKey( 'coupons', $data );
		self::assertArrayNotHasKey( 'discount', $data['totals'] );
	}

	public function test_a_minimum_spend_shows_how_much_is_missing(): void {
		$this->coupon(
			'Big basket',
			Coupon::FIXED_CART,
			500,
			[
				'automatic'    => true,
				'min_subtotal' => 5000,
			]
		);
		$this->coupon(
			'Huge basket',
			Coupon::FIXED_CART,
			1500,
			[
				'automatic'    => true,
				'min_subtotal' => 10000,
			]
		);
		$browser = $this->shopper();
		$product = $this->simple( 1000 );
		$this->add( $browser, $product, 3 );

		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertSame( 0, $data['totals']['discount']['amount'] );
		self::assertSame( [], $data['coupons'] );
		self::assertSame( 'Big basket', $data['next_discount']['label'], 'the closest one' );
		self::assertSame( 2000, $data['next_discount']['remaining']['amount'] );
		self::assertSame( 60, $data['next_discount']['percent'] );

		$this->add( $browser, $product, 2 );
		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertSame( 500, $data['totals']['discount']['amount'] );
		self::assertSame( 'Huge basket', $data['next_discount']['label'] );
		self::assertSame( 5000, $data['next_discount']['remaining']['amount'] );
		self::assertSame( 50, $data['next_discount']['percent'] );
	}

	public function test_no_nudge_when_another_rule_keeps_it_out(): void {
		$other = $this->simple( 1000 );
		$this->coupon(
			'Shoes',
			Coupon::PERCENT,
			1000,
			[
				'automatic'    => true,
				'min_subtotal' => 5000,
				'product_ids'  => [ $other ],
			]
		);
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		self::assertArrayNotHasKey( 'next_discount', $browser->presenter()->to_array( $browser->cart()->state( 0 ) ) );
	}

	public function test_it_stacks_after_codes_and_an_individual_use_code_keeps_it_out(): void {
		$this->coupon( 'Auto five', Coupon::FIXED_CART, 500, [ 'automatic' => true ] );
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$this->coupon( 'SOLO', Coupon::PERCENT, 2000, [ 'individual_use' => true ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );

		$state = $browser->cart()->apply_coupon( 0, 'ten' );
		self::assertSame( 700, $state->discount()->amount, '10 % of 2000, then 500 off what is left' );
		self::assertSame( [ 'TEN', 'Auto five' ], array_column( $browser->presenter()->coupons( $state ), 'label' ) );

		$state = $browser->cart()->apply_coupon( 0, 'solo' );
		self::assertSame( [ 'solo' ], $state->cart->coupons );
		self::assertSame( 400, $state->discount()->amount );
		self::assertSame( [ 'SOLO' ], array_column( $browser->presenter()->coupons( $state ), 'label' ) );
	}

	public function test_an_individual_use_automatic_one_applies_only_alone(): void {
		$this->coupon(
			'Alone',
			Coupon::PERCENT,
			5000,
			[
				'automatic'      => true,
				'individual_use' => true,
			]
		);
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );
		self::assertSame( 1000, $browser->cart()->state( 0 )->discount()->amount );

		$state = $browser->cart()->apply_coupon( 0, 'ten' );
		self::assertSame( 200, $state->discount()->amount );
		self::assertSame( [ 'TEN' ], array_column( $browser->presenter()->coupons( $state ), 'label' ) );
	}

	public function test_its_name_cannot_be_typed_as_a_code(): void {
		$this->coupon( 'Spring sale', Coupon::PERCENT, 1000, [ 'automatic' => true ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		try {
			$browser->cart()->apply_coupon( 0, 'spring sale' );
			self::fail( 'The name was accepted as a code.' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'coupon_invalid', $e->reason );
		}
	}

	public function test_a_code_that_turned_automatic_leaves_the_list_quietly(): void {
		$coupon  = $this->coupon( 'WELCOME', Coupon::FIXED_CART, 300 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$browser->cart()->apply_coupon( 0, 'welcome' );
		$this->coupons()->editor()->save( [ 'automatic' => true ], $coupon->id );

		$state = $browser->cart()->state( 0 );
		self::assertSame( 300, $state->discount()->amount, 'applied once, as automatic' );
		self::assertFalse( $state->has_rejected_coupons() );
		$rows = $browser->presenter()->coupons( $state );
		self::assertCount( 1, $rows );
		self::assertTrue( $rows[0]['automatic'] );
	}

	public function test_the_editor_drops_the_email_and_per_customer_rules(): void {
		$coupon = $this->coupon(
			'Members',
			Coupon::PERCENT,
			1000,
			[
				'automatic'          => true,
				'emails'             => [ 'ada@example.com' ],
				'per_customer_limit' => 1,
			]
		);
		self::assertTrue( $coupon->automatic );
		self::assertSame( [], $coupon->emails );
		self::assertSame( 0, $coupon->per_customer_limit );
	}

	public function test_the_flag_follows_the_active_automatic_coupons(): void {
		$this->coupon( 'CODE', Coupon::PERCENT, 1000 );
		self::assertFalse( CouponRepository::has_automatic() );
		$sale = $this->coupon( 'Sale', Coupon::PERCENT, 1000, [ 'automatic' => true ] );
		self::assertTrue( CouponRepository::has_automatic() );
		$this->coupons()->editor()->save( [ 'status' => Coupon::DISABLED ], $sale->id );
		self::assertFalse( CouponRepository::has_automatic() );
		$this->coupons()->editor()->save( [ 'status' => Coupon::ACTIVE ], $sale->id );
		$this->coupons()->editor()->delete( $sale->id );
		self::assertFalse( CouponRepository::has_automatic() );
	}

	public function test_the_server_render_shows_the_nudge_and_no_remove_button(): void {
		$this->coupon(
			'Big basket',
			Coupon::FIXED_CART,
			500,
			[
				'automatic'    => true,
				'min_subtotal' => 5000,
			]
		);
		$this->coupon( 'Welcome', Coupon::PERCENT, 1000, [ 'automatic' => true ] );
		$cart = Plugin::instance()->cart()->cart();
		$cart->add( 0, $id = $this->simple( 1000 ), $this->service()->get( $id )->base->id, 2 );

		$html = (string) preg_replace( '/>\s*</', '><', do_blocks( '<!-- wp:flexacommerce/cart /-->' ) );
		self::assertMatchesRegularExpression( '/<div class="fxc-cart__next"(?![^>]*\shidden[\s>])[^>]*>/', $html, 'the nudge is shown' );
		self::assertStringContainsString( 'Spend $30.00 more for this discount: Big basket.', $html );
		self::assertStringContainsString( 'width:40%', $html );
		self::assertStringContainsString( 'Discount: Welcome', $html );
		self::assertMatchesRegularExpression( '/<button type="submit" class="fxc-cart__remove-coupon"[^>]*\shidden>/', $html, 'an automatic discount cannot be removed' );
	}
}
