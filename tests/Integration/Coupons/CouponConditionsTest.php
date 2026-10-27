<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;

/** Coupon conditions (ADR-041): refused, waiting on a checkout choice, and checked again at the order. */
final class CouponConditionsTest extends CouponTestCase {
	public function tear_down(): void {
		delete_option( GatewaySettings::PREFIX . 'bacs' );
		parent::tear_down();
	}

	public function test_a_cart_condition_refuses_the_code_with_its_reason(): void {
		$this->coupon( 'THREE', Coupon::PERCENT, 1000, [ 'conditions' => self::block( 'cart_quantity', 'gte', 3 ) ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );

		self::assertStringContainsString( 'at least 3 items', $this->refusal( $browser, 'three' ) );

		$this->add( $browser, $this->simple( 1000 ), 2 );
		self::assertSame( 300, $browser->cart()->apply_coupon( 0, 'three' )->discount()->amount );
	}

	public function test_a_payment_condition_waits_in_the_cart_and_applies_at_checkout(): void {
		$this->coupon( 'COD', Coupon::PERCENT, 1000, [ 'conditions' => self::block( 'payment_method', 'in', [ 'cod' ] ) ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 2 );

		$state = $browser->cart()->apply_coupon( 0, 'cod' );
		self::assertSame( 0, $state->discount()->amount, 'no method chosen yet' );
		self::assertFalse( $state->has_rejected_coupons() );
		$row = $browser->presenter()->coupons( $state )[0];
		self::assertTrue( $row['waiting'] );
		self::assertStringContainsString( 'once you choose a payment method', $row['message'] );

		$checkout = $this->checkout( $browser );
		$form     = $checkout->form_state( 0 );
		self::assertSame( 'cod', $form['payment_method'] );
		self::assertSame( 200, $form['totals']['discount']['amount'] );

		$order = $checkout->place_order( $this->request( $form['checkout_token'] ) )->order;
		self::assertSame( 200, $order->discount_total );
	}

	public function test_the_order_refuses_a_coupon_for_another_payment_method(): void {
		$gateways = Plugin::instance()->payments()->service()->gateways();
		$bacs     = $gateways->get( 'bacs' );
		self::assertNotNull( $bacs );
		$gateways->settings()->save( $bacs, [ 'enabled' => true ] );
		$this->coupon( 'COD', Coupon::PERCENT, 1000, [ 'conditions' => self::block( 'payment_method', 'in', [ 'cod' ] ) ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$browser->cart()->apply_coupon( 0, 'cod' );
		$checkout = $this->checkout( $browser );
		$token    = $checkout->form_state( 0 )['checkout_token'];

		$errors = $this->errors( $checkout, $this->request( $token, [ 'payment_method' => 'bacs' ] ) );
		self::assertStringContainsString( 'not available with this payment method', $errors['coupon'] );
	}

	public function test_a_country_condition_is_checked_against_the_address(): void {
		$this->coupon( 'ABROAD', Coupon::PERCENT, 1000, [ 'conditions' => self::block( 'country', 'not_in', [ 'US' ] ) ] );
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$browser->cart()->apply_coupon( 0, 'abroad' );
		$checkout = $this->checkout( $browser );
		$token    = $checkout->form_state( 0 )['checkout_token'];

		$errors = $this->errors( $checkout, $this->request( $token ) );
		self::assertStringContainsString( 'not available in your country', $errors['coupon'] );
	}

	public function test_a_first_order_coupon_counts_the_customer_orders(): void {
		$this->coupon( 'FIRST', Coupon::PERCENT, 1000, [ 'conditions' => self::block( 'order_count', 'lte', 0 ) ] );
		$user = self::factory()->user->create( [ 'role' => 'customer' ] );

		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1, $user );
		self::assertSame( 100, $browser->cart()->apply_coupon( $user, 'first' )->discount()->amount );
		$checkout = $this->checkout( $browser );
		$checkout->place_order( $this->request( $checkout->form_state( $user )['checkout_token'], [], $user ) );

		$again = $this->shopper();
		$this->add( $again, $this->simple( 1000 ), 1, $user );
		try {
			$again->cart()->apply_coupon( $user, 'first' );
			self::fail( 'FIRST was accepted on a second order.' );
		} catch ( InvalidCartRequest $e ) {
			self::assertStringContainsString( 'first order only', $e->getMessage() );
		}

		// A guest has no orders: a first order code works, one for returning customers asks to log in.
		$this->coupon( 'BACK', Coupon::PERCENT, 1000, [ 'conditions' => self::block( 'order_count', 'gte', 1 ) ] );
		$guest = $this->shopper();
		$this->add( $guest, $this->simple( 1000 ), 1 );
		self::assertSame( 100, $guest->cart()->apply_coupon( 0, 'first' )->discount()->amount );
		self::assertStringContainsString( 'Log in', $this->refusal( $guest, 'back' ) );
		self::assertSame( 100, $again->cart()->apply_coupon( $user, 'back' )->discount()->amount );
	}

	public function test_an_automatic_coupon_waits_for_its_conditions(): void {
		$this->coupon(
			'Bulk',
			Coupon::PERCENT,
			1000,
			[
				'automatic'  => true,
				'conditions' => self::block( 'cart_quantity', 'gte', 3 ),
			]
		);
		$browser = $this->shopper();
		$product = $this->simple( 1000 );
		$this->add( $browser, $product, 1 );
		self::assertSame( 0, $browser->cart()->state( 0 )->discount()->amount );

		$this->add( $browser, $product, 2 );
		self::assertSame( 300, $browser->cart()->state( 0 )->discount()->amount );
	}

	public function test_the_shipping_update_saves_the_payment_method(): void {
		$browser = $this->shopper();
		$this->add( $browser, $this->simple( 1000 ), 1 );
		$data = $this->checkout( $browser )->update_shipping( 0, new Destination( 'US', 'CA', '95014', 'Cupertino' ), $this->rate_id, 'cod' );

		self::assertSame( 'cod', $data['payment_method'] );
		self::assertSame( 'cod', $browser->cart()->saved( 0 )->payment_method );
		self::assertSame( $this->rate_id, $browser->cart()->saved( 0 )->shipping_rate );
	}

	/**
	 * @param int|list<string|int> $value
	 * @return array<string,mixed>
	 */
	private static function block( string $type, string $op, int|array $value ): array {
		return [
			'match' => 'all',
			'items' => [
				[
					'type'  => $type,
					'op'    => $op,
					'value' => $value,
				],
			],
		];
	}

	private function refusal( CartServices $browser, string $code ): string {
		try {
			$browser->cart()->apply_coupon( 0, $code );
		} catch ( InvalidCartRequest $e ) {
			return $e->getMessage();
		}
		self::fail( "{$code} was accepted." );
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
