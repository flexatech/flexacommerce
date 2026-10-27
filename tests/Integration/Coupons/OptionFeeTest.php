<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSetRepository;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Plugin;

/** An option charged once per cart line, whatever the quantity (ADR-029). */
final class OptionFeeTest extends CouponTestCase {
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		update_option( ProductOptions::OPTION, [], true );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
	}

	public function test_the_fee_is_on_the_line_and_the_order_keeps_it(): void {
		$this->sets()->save(
			[
				'name'   => 'Setup',
				'fields' => [
					[
						'type'       => OptionField::TEXT,
						'label'      => 'Logo text',
						'price_mode' => OptionField::ONCE,
						'price'      => 500,
					],
					[
						'type'    => OptionField::RADIO,
						'label'   => 'Wrap',
						'choices' => [
							[
								'label'      => 'Box',
								'price_mode' => OptionField::FIXED,
								'price'      => 100,
							],
							[
								'label'      => 'Proof',
								'price_mode' => OptionField::ONCE,
								'price'      => 300,
							],
						],
					],
				],
			]
		);
		$browser = $this->shopper();
		$id      = $this->simple( 2000 );
		$state   = $browser->cart()->add(
			0,
			$id,
			$this->service()->get( $id )->base->id,
			3,
			[],
			[
				'1-1' => 'ACME',
				'1-2' => '2',
			]
		);
		$item    = $state->items[0];
		self::assertSame( 2000, $item->unit?->active->amount, 'the unit price leaves the fee out' );
		self::assertSame( 800, $item->fee );
		self::assertSame( 6800, $item->total?->amount, '3 x 20.00 and 8.00 once' );

		$browser->cart()->set_quantity( 0, $item->line->key, 5 );
		self::assertSame( 10800, $browser->cart()->state( 0 )->items[0]->total?->amount, 'the fee stays the same at 5' );

		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$browser->cart()->apply_coupon( 0, 'TEN' );
		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( 1080, $form['totals']['discount']['amount'], 'a percent coupon takes its share of the fee too' );
		self::assertSame( 10220, $form['totals']['total']['amount'], '108.00 less 10.80, and 5.00 shipping' );

		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 10220 ] ) )->order;
		$line  = $this->lines( $order, OrderItem::PRODUCT )[0];
		self::assertSame( [ 2000, 5, 10800, 800 ], [ $line->unit_price, $line->quantity, $line->subtotal, $line->meta['fee'] ] );
		self::assertSame( 9720, $line->total );
	}

	public function test_the_form_says_once(): void {
		$this->sets()->save(
			[
				'name'   => 'Setup',
				'fields' => [
					[
						'type'       => OptionField::TEXT,
						'label'      => 'Logo text',
						'price_mode' => OptionField::ONCE,
						'price'      => 500,
					],
				],
			]
		);
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $this->simple( 2000 ) . '"]' );
		self::assertStringContainsString( 'Logo text <span class="fxc-option__price">(+$5.00 once)</span>', $html );
	}

	private function sets(): OptionSetRepository {
		return Plugin::instance()->option_sets();
	}

	protected function shopper( ?MemoryTokenStore $tokens = null ): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			$tokens ?? new MemoryTokenStore(),
			coupons: fn (): CouponService => $this->coupons()->service(),
			product_options: fn (): ProductOptions => new ProductOptions( $plugin->option_sets() ),
		);
	}
}
