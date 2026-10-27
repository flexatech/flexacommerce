<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Contracts\Orders\CustomerHistory;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;
use Flexa\Commerce\Pricing\Rules\PriceRules;

/** A graduated line (ADR-042) keeps its exact total through coupons and the order. */
final class GraduatedOrderTest extends CouponTestCase {
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		update_option( PriceRuleRepository::OPTION, [], true );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		Plugin::instance()->cart()->pricing()->reset();
	}

	public function test_the_order_keeps_the_exact_line_total(): void {
		Plugin::instance()->price_rule_repository()->save(
			[
				'name'  => 'Graduated',
				'type'  => PriceRule::GRADUATED,
				'tiers' => [
					[
						'min'    => 2,
						'mode'   => PriceRule::PERCENT,
						'amount' => 1000,
					],
				],
			]
		);
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 3 );
		$item = $browser->cart()->state( 0 )->items[0];
		self::assertSame( [ 933, 2800 ], [ $item->unit?->active->amount, $item->total?->amount ], '1000 + 900 + 900' );

		$browser->cart()->apply_coupon( 0, 'TEN' );
		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( 280, $form['totals']['discount']['amount'] );
		self::assertSame( 3020, $form['totals']['total']['amount'], '28.00 less 2.80, and 5.00 shipping' );

		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 3020 ] ) )->order;
		$line  = $this->lines( $order, OrderItem::PRODUCT )[0];
		self::assertSame( [ 933, 3, 2800, 2520 ], [ $line->unit_price, $line->quantity, $line->subtotal, $line->total ] );
		self::assertSame( 2800, $order->subtotal );
	}

	protected function shopper( ?MemoryTokenStore $tokens = null ): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			$tokens ?? new MemoryTokenStore(),
			coupons: fn (): CouponService => $this->coupons()->service(),
			rules: fn (): PriceRules => $plugin->price_rules(),
			history: fn (): CustomerHistory => $plugin->customer_history(),
		);
	}
}
