<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\TransitionContext;

/** Orders placed with coupons: totals, lines, taxes and the usage limits. */
final class CouponCheckoutTest extends CouponTestCase {
	public function test_an_order_with_coupons_records_the_discount_and_a_use(): void {
		$ten     = $this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$five    = $this->coupon( 'FIVE', Coupon::FIXED_CART, 500 );
		$browser = $this->shopper();
		$product = $this->simple( 1000 );
		$this->add( $browser, $product, 2 );
		$browser->cart()->apply_coupon( 0, 'ten' );
		$browser->cart()->apply_coupon( 0, 'five' );
		$checkout = $this->checkout( $browser );

		$form = $checkout->form_state( 0 );
		self::assertSame( 2000, $form['totals']['subtotal']['amount'] );
		self::assertSame( 700, $form['totals']['discount']['amount'] );
		self::assertSame( 1800, $form['totals']['total']['amount'] );
		self::assertSame( [ 'TEN', 'FIVE' ], array_column( $form['coupons'], 'label' ) );

		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 1800 ] ) )->order;
		self::assertSame( 1800, $order->total );
		self::assertSame( 2000, $order->subtotal );
		self::assertSame( 700, $order->discount_total );

		$product_line = $this->lines( $order, OrderItem::PRODUCT )[0];
		self::assertSame( 2000, $product_line->subtotal );
		self::assertSame( 1300, $product_line->total );
		self::assertSame( 700, $product_line->discount_total );

		$discounts = $this->lines( $order, OrderItem::DISCOUNT );
		self::assertSame( [ 'TEN', 'FIVE' ], array_map( static fn ( OrderItem $i ): string => $i->name, $discounts ) );
		self::assertSame( [ 200, 500 ], array_map( static fn ( OrderItem $i ): int => $i->total, $discounts ) );
		self::assertSame( $ten->id, (int) $discounts[0]->meta['coupon_id'] );

		self::assertSame( 1, $this->uses( $ten->id ) );
		self::assertSame( 1, $this->uses( $five->id ) );
		self::assertSame( [], $browser->cart()->state( 0 )->cart->coupons, 'the codes leave with the cart' );

		// Cancelling gives the uses back, once.
		$this->orders()->cancel( $order->id, TransitionContext::admin() );
		self::assertSame( 0, $this->uses( $ten->id ) );
		self::assertSame( 0, $this->uses( $five->id ) );
		global $wpdb;
		self::assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'coupon_uses' ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_coupon_that_ran_out_is_refused_at_checkout(): void {
		$coupon = $this->coupon( 'ONCE', Coupon::PERCENT, 1000, [ 'usage_limit' => 1 ] );
		$first  = $this->shopper();
		$second = $this->shopper();
		foreach ( [ $first, $second ] as $browser ) {
			$this->add( $browser, $this->simple( 1000 ), 1 );
			$browser->cart()->apply_coupon( 0, 'once' );
		}
		$late  = $this->checkout( $second );
		$token = $late->form_state( 0 )['checkout_token'];

		$checkout = $this->checkout( $first );
		$checkout->place_order( $this->request( $checkout->form_state( 0 )['checkout_token'] ) );
		self::assertSame( 1, $this->uses( $coupon->id ) );

		$errors = $this->errors( $this->checkout( $second ), $this->request( $token ) );
		self::assertStringContainsString( 'usage limit', $errors['coupon'] );
		self::assertSame( 1, $this->uses( $coupon->id ) );
	}

	public function test_a_use_taken_inside_the_order_transaction_rolls_back_with_it(): void {
		$coupon  = $this->coupon( 'ONCE', Coupon::PERCENT, 1000, [ 'usage_limit' => 1 ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$browser->cart()->apply_coupon( 0, 'once' );
		$checkout = $this->checkout( $browser );
		$token    = $checkout->form_state( 0 )['checkout_token'];

		// Another checkout takes the last use after this cart was read.
		$sneak = static function ( string $query ) use ( $coupon ): string {
			if ( str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'usage_count = usage_count + 1' ) ) {
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET usage_count = 1 WHERE id = %d', Schema::table( 'coupons' ), $coupon->id ) ); // phpcs:ignore WordPress.DB
			}
			return $query;
		};
		add_filter( 'query', $sneak );
		try {
			$errors = $this->errors( $checkout, $this->request( $token ) );
		} finally {
			remove_filter( 'query', $sneak );
		}
		self::assertStringContainsString( 'usage limit', $errors['coupon'] );
		global $wpdb;
		self::assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'orders' ) ), 'the order rolled back' ); // phpcs:ignore WordPress.DB
		self::assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'coupon_uses' ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_per_customer_limit_counts_the_billing_email(): void {
		$this->coupon( 'WELCOME', Coupon::FIXED_CART, 100, [ 'per_customer_limit' => 1 ] );
		$product = $this->simple( 1000 );
		foreach ( [ 'ada@example.com', 'ADA@example.com', 'bob@example.com' ] as $index => $email ) {
			$browser = $this->shopper();
			$this->add( $browser, $product, 1 );
			$browser->cart()->apply_coupon( 0, 'welcome' );
			$checkout = $this->checkout( $browser );
			$request  = $this->request( $checkout->form_state( 0 )['checkout_token'], [ 'billing' => [ 'email' => $email ] + self::BILLING ] );
			if ( $index === 1 ) {
				self::assertStringContainsString( 'already used', $this->errors( $checkout, $request )['coupon'] );
				continue;
			}
			self::assertSame( 1400, $checkout->place_order( $request )->order->total );
		}
	}

	public function test_allowed_emails_are_checked_against_the_billing_email(): void {
		$this->coupon( 'STAFF', Coupon::PERCENT, 5000, [ 'emails' => [ '*@example.org' ] ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$browser->cart()->apply_coupon( 0, 'staff' );
		$checkout = $this->checkout( $browser );
		$token    = $checkout->form_state( 0 )['checkout_token'];

		$errors = $this->errors( $checkout, $this->request( $token ) );
		self::assertStringContainsString( 'not valid for your email', $errors['coupon'] );

		$order = $checkout->place_order( $this->request( $token, [ 'billing' => [ 'email' => 'Grace@Example.org' ] + self::BILLING ] ) )->order;
		self::assertSame( 1000, $order->total );
	}

	public function test_a_rejected_code_blocks_checkout_until_removed(): void {
		$coupon  = $this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$browser->cart()->apply_coupon( 0, 'ten' );
		$this->coupons()->editor()->save( [ 'status' => Coupon::DISABLED ], $coupon->id );

		$checkout = $this->checkout( $browser );
		$token    = $checkout->form_state( 0 )['checkout_token'];
		self::assertStringContainsString( 'does not exist', $this->errors( $checkout, $this->request( $token ) )['coupon'] );

		$browser->cart()->remove_coupon( 0, 'ten' );
		self::assertSame( 1500, $this->checkout( $browser )->place_order( $this->request( $token ) )->order->total );
	}

	public function test_exclusive_tax_is_charged_on_the_discounted_price(): void {
		$this->enable();
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '10',
				'name'  => 'CA Tax',
			]
		);
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );
		$browser->cart()->apply_coupon( 0, 'ten' );
		$checkout = $this->checkout( $browser );

		$form = $checkout->form_state( 0 );
		self::assertSame( 2000, $form['totals']['subtotal']['amount'] );
		self::assertSame( 200, $form['totals']['discount']['amount'] );
		// 1800 + 180 tax, shipping 500 + 50 tax.
		self::assertSame( 230, $form['totals']['tax']['amount'] );
		self::assertSame( 2530, $form['totals']['total']['amount'] );

		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 2530 ] ) )->order;
		self::assertSame( 2530, $order->total );
		self::assertSame( 230, $order->tax_total );
		self::assertSame( 200, $order->discount_total );
		$line = $this->lines( $order, OrderItem::PRODUCT )[0];
		self::assertSame( 1800, $line->total );
		self::assertSame( 180, $line->tax_total );
		$discount = $this->lines( $order, OrderItem::DISCOUNT )[0];
		self::assertSame( 200, $discount->total );
		self::assertSame( 20, (int) $discount->meta['discount_tax'] );
	}

	public function test_inclusive_prices_take_the_discount_off_the_gross_price(): void {
		$this->enable(
			[
				'prices_include_tax' => true,
				'tax_display_cart'   => 'incl',
			]
		);
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '10',
				'name'  => 'CA Tax',
			]
		);
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1100 ), 2 );
		$browser->cart()->apply_coupon( 0, 'ten' );
		$checkout = $this->checkout( $browser );

		$form = $checkout->form_state( 0 );
		// 2200 gross, 10 % off is 220 gross: 1980 holds 180 tax. Shipping 500 adds 50.
		self::assertSame( 2200, $form['totals']['subtotal']['amount'] );
		self::assertSame( 220, $form['totals']['discount']['amount'] );
		self::assertSame( 230, $form['totals']['tax']['amount'] );
		self::assertSame( 2530, $form['totals']['total']['amount'] );

		$order = $checkout->place_order( $this->request( $form['checkout_token'], [ 'expected_total' => 2530 ] ) )->order;
		self::assertSame( 2530, $order->total );
		self::assertSame( 1800, $this->lines( $order, OrderItem::PRODUCT )[0]->total );
		$discount = $this->lines( $order, OrderItem::DISCOUNT )[0];
		self::assertSame( 200, $discount->total, 'stored without tax' );
		self::assertSame( 20, (int) $discount->meta['discount_tax'] );
	}

	/** @return array<string,string> */
	private function errors( CheckoutService $checkout, CheckoutRequest $request ): array {
		try {
			$checkout->place_order( $request );
		} catch ( InvalidCheckout $e ) {
			return $e->errors;
		}
		self::fail( 'The checkout was accepted.' );
	}
}
