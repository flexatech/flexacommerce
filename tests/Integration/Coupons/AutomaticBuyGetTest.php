<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Plugin;

/** Automatic buy X get Y offers and the nudge toward their first set (ADR-027). */
final class AutomaticBuyGetTest extends CouponTestCase {
	/** @param array<string,mixed> $input */
	private function offer( string $name, int $buy, int $get, int $amount = Coupon::FULL_PERCENT, array $input = [] ): Coupon {
		return $this->coupon(
			$name,
			Coupon::BUY_X_GET_Y,
			$amount,
			$input + [
				'automatic' => true,
				'buy_qty'   => $buy,
				'get_qty'   => $get,
			]
		);
	}

	public function test_the_cart_counts_down_to_the_free_item_and_the_order_keeps_it(): void {
		$offer   = $this->offer( 'Buy 2, get 1 free', 2, 1 );
		$browser = $this->shopper();
		$product = $this->simple( 1000 );
		$this->add( $browser, $product, 1 );

		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertSame( 'Buy 2, get 1 free', $data['next_discount']['label'] );
		self::assertSame( 2, $data['next_discount']['items'] );
		self::assertNull( $data['next_discount']['remaining'] );
		self::assertSame( 33, $data['next_discount']['percent'] );
		self::assertSame( 'Add 2 more items for this discount: Buy 2, get 1 free.', $data['next_discount']['message'] );

		$this->add( $browser, $product, 1 );
		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertSame( 'Add 1 more item for this discount: Buy 2, get 1 free.', $data['next_discount']['message'] );
		self::assertSame( 66, $data['next_discount']['percent'] );

		$this->add( $browser, $product, 1 );
		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertNull( $data['next_discount'] );
		self::assertSame( 1000, $data['totals']['discount']['amount'] );
		self::assertTrue( $data['coupons'][0]['automatic'] );

		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( 2500, $form['totals']['total']['amount'], '2000 and 500 shipping' );
		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 2500 ] ) )->order;
		self::assertSame( 1000, $order->discount_total );
		self::assertTrue( $this->lines( $order, OrderItem::DISCOUNT )[0]->meta['automatic'] );
		self::assertSame( 1, $this->uses( $offer->id ) );
	}

	public function test_with_get_rules_the_count_covers_both_sides(): void {
		$shirt = $this->simple( 2000 );
		$hat   = $this->simple( 800 );
		$this->offer(
			'Hat with two shirts',
			2,
			1,
			Coupon::FULL_PERCENT,
			[
				'product_ids'     => [ $shirt ],
				'get_product_ids' => [ $hat ],
			]
		);
		$browser = $this->shopper();
		$this->add( $browser, $hat, 1 );
		self::assertNull( $browser->presenter()->to_array( $browser->cart()->state( 0 ) )['next_discount'] ?? null, 'no shirt yet: nothing to count from' );

		$browser = $this->shopper();
		$this->add( $browser, $shirt, 1 );
		self::assertSame( 2, $browser->presenter()->to_array( $browser->cart()->state( 0 ) )['next_discount']['items'], 'one shirt and the hat' );

		$this->add( $browser, $shirt, 1 );
		self::assertSame( 1, $browser->presenter()->to_array( $browser->cart()->state( 0 ) )['next_discount']['items'], 'only the hat' );

		$this->add( $browser, $hat, 1 );
		$data = $browser->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertNull( $data['next_discount'] );
		self::assertSame( 800, $data['totals']['discount']['amount'] );
	}

	public function test_the_fewest_items_win_and_a_missing_spend_comes_first(): void {
		$this->offer( 'Buy 3, get 1 free', 3, 1 );
		$this->offer( 'Buy 1, get 1 half off', 1, 1, 5000 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		self::assertSame( 'Buy 1, get 1 half off', $browser->presenter()->to_array( $browser->cart()->state( 0 ) )['next_discount']['label'] );

		$this->coupon(
			'Big basket',
			Coupon::FIXED_CART,
			500,
			[
				'automatic'    => true,
				'min_subtotal' => 5000,
			]
		);
		$data = $this->shopper()->presenter()->to_array( $browser->cart()->state( 0 ) );
		self::assertSame( 'Big basket', $data['next_discount']['label'] );
		self::assertSame( 0, $data['next_discount']['items'] );
		self::assertSame( 4000, $data['next_discount']['remaining']['amount'] );
	}

	public function test_no_nudge_when_another_rule_keeps_it_out(): void {
		$this->offer(
			'Weekend offer',
			1,
			1,
			Coupon::FULL_PERCENT,
			[ 'min_subtotal' => 5000 ]
		);
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		self::assertNull( $browser->presenter()->to_array( $browser->cart()->state( 0 ) )['next_discount'] ?? null, 'it needs both a spend and an item' );
	}

	public function test_the_server_render_shows_the_item_nudge(): void {
		$this->offer( 'Buy 1, get 1 free', 1, 1 );
		$cart = Plugin::instance()->cart()->cart();
		$cart->add( 0, $id = $this->simple( 1000 ), $this->service()->get( $id )->base->id, 1 );

		$html = (string) preg_replace( '/>\s*</', '><', do_blocks( '<!-- wp:flexacommerce/cart /-->' ) );
		self::assertStringContainsString( 'Add 1 more item for this discount: Buy 1, get 1 free.', $html );
		self::assertStringContainsString( 'width:50%', $html );
	}
}
