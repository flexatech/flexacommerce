<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Contracts\Exceptions\InvalidPriceRule;
use Flexa\Commerce\Contracts\Orders\CustomerHistory;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;
use Flexa\Commerce\Pricing\Rules\PriceRules;
use Flexa\Commerce\Storefront\Render\OrderConfirmation;

/** Checkout fees (ADR-043): a pricing rule that adds a line to the total. */
final class CheckoutFeeTest extends CouponTestCase {
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		update_option( PriceRuleRepository::OPTION, [], true );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		Plugin::instance()->cart()->pricing()->reset();
	}

	public function test_a_small_order_fee_reaches_the_order(): void {
		$rule    = $this->rules()->save(
			[
				'name'       => 'Small order fee',
				'type'       => PriceRule::FEE,
				'mode'       => PriceRule::FIXED,
				'amount'     => 300,
				'conditions' => self::subtotal_at_most( 2000 ),
			]
		);
		$browser = $this->shopper();
		$id      = $this->simple( 1000 );
		$this->add( $browser, $id, 1 );
		$state = $browser->cart()->state( 0 );
		self::assertSame( [ 300 ], array_map( static fn ( $f ): int => $f->amount->amount, $state->fees ) );
		self::assertSame( 1000, $state->subtotal->amount, 'a fee is not part of the subtotal' );
		$cart = $browser->presenter()->to_array( $state );
		self::assertSame( 'Small order fee', $cart['fees'][0]['name'] );
		self::assertSame( 1300, $cart['totals']['total']['amount'] );

		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( 300, $form['totals']['fees']['amount'] );
		self::assertSame( 1800, $form['totals']['total']['amount'], '10.00, 5.00 shipping and the 3.00 fee' );
		self::assertSame( [ 'fees', 'tax', 'total' ], array_slice( array_keys( $form['totals'] ), -3 ), 'the fee row sits above the tax' );

		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 1800 ] ) )->order;
		$fee   = $this->lines( $order, OrderItem::FEE );
		self::assertCount( 1, $fee );
		self::assertSame( [ 'Small order fee', 300, $rule->id ], [ $fee[0]->name, $fee[0]->total, (int) $fee[0]->meta['rule_id'] ] );
		self::assertSame( [ 300, 1800 ], [ $order->fee_total, $order->total ] );
		$html = ( new OrderConfirmation( $checkout ) )->items( $order );
		self::assertStringContainsString( 'Small order fee', $html );

		$browser->cart()->add( 0, $id, $this->service()->get( $id )->base->id, 3 );
		self::assertSame( [], $browser->cart()->state( 0 )->fees, 'a cart above the subtotal pays no fee' );
	}

	public function test_a_payment_fee_waits_for_the_method_and_respects_its_cap(): void {
		$taxed = $this->simple( 5000 );
		$other = $this->simple( 1000 );
		$this->rules()->save(
			[
				'name'        => 'COD fee',
				'type'        => PriceRule::FEE,
				'mode'        => PriceRule::PERCENT,
				'amount'      => 200,
				'cap'         => 80,
				'applies_to'  => PriceRule::PRODUCTS,
				'product_ids' => [ $taxed ],
				'conditions'  => [
					'items' => [
						[
							'type'  => 'payment_method',
							'op'    => 'in',
							'value' => [ 'cod' ],
						],
					],
				],
			]
		);
		$browser = $this->shopper();
		$this->add( $browser, $other, 1 );
		$this->add( $browser, $taxed, 1 );
		self::assertSame( [], $browser->cart()->state( 0 )->fees, 'no payment method yet: no fee' );

		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( 80, $form['totals']['fees']['amount'], '2% of 50.00 is 1.00, capped at 0.80' );
		self::assertSame( 6000 + 500 + 80, $form['totals']['total']['amount'] );

		$this->coupon( 'HALF', Coupon::PERCENT, 5000 );
		$browser->cart()->apply_coupon( 0, 'HALF' );
		$form = $checkout->form_state( 0 );
		self::assertSame( 50, $form['totals']['fees']['amount'], 'the percent is taken after coupons: 2% of 25.00' );
		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => $form['totals']['total']['amount'] ] ) )->order;
		self::assertSame( 50, $order->fee_total );
	}

	public function test_fees_add_up_in_list_order(): void {
		foreach ( [
			'Handling' => 200,
			'Packing'  => 100,
		] as $name => $amount ) {
			$this->rules()->save(
				[
					'name'   => $name,
					'type'   => PriceRule::FEE,
					'mode'   => PriceRule::FIXED,
					'amount' => $amount,
				]
			);
		}
		// A price rule still changes the price; it never takes a fee away.
		$this->rules()->save( [ 'name' => '10% off' ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );
		$state = $browser->cart()->state( 0 );
		self::assertSame( [ 'Handling', 'Packing' ], array_map( static fn ( $f ): string => $f->name, $state->fees ) );
		self::assertSame( [ 1800, 300 ], [ $state->subtotal->amount, $state->fee_total()->amount ] );
	}

	public function test_the_editor_checks_a_fee(): void {
		foreach (
			[
				'mode' => [ 'mode' => PriceRule::PRICE ],
				'cap'  => [
					'mode' => PriceRule::PERCENT,
					'cap'  => -1,
				],
			] as $field => $input
		) {
			try {
				$this->rules()->save(
					$input + [
						'name' => 'x',
						'type' => PriceRule::FEE,
					]
				);
				self::fail( $field . ' is refused' );
			} catch ( InvalidPriceRule $e ) {
				self::assertSame( $field, $e->field );
			}
		}
		try {
			$this->rules()->save(
				[
					'name'       => 'Price rule on the subtotal',
					'conditions' => self::subtotal_at_most( 2000 ),
				]
			);
			self::fail( 'only a fee may check the subtotal' );
		} catch ( InvalidPriceRule $e ) {
			self::assertSame( 'conditions', $e->field );
		}
		$fee = $this->rules()->save(
			[
				'name'       => 'Fee',
				'type'       => PriceRule::FEE,
				'mode'       => PriceRule::FIXED,
				'amount'     => 100,
				'cap'        => 500,
				'sale_items' => PriceRule::SALE_SKIP,
			]
		);
		self::assertSame( [ PriceRule::SALE_APPLY, 0 ], [ $fee->sale_items, $fee->cap ], 'a fixed fee has no cap and ignores sale items' );
		self::assertSame( 1000, $this->service()->get( $this->simple( 1000 ) )->base->regular_price, 'a fee never changes a price' );
	}

	/** @return array{match:string,items:list<array<string,mixed>>} */
	private static function subtotal_at_most( int $amount ): array {
		return [
			'match' => 'all',
			'items' => [
				[
					'type'  => 'cart_subtotal',
					'op'    => 'lte',
					'value' => $amount,
				],
			],
		];
	}

	private function rules(): PriceRuleRepository {
		return Plugin::instance()->price_rule_repository();
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
