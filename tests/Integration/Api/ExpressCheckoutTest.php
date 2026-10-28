<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Cart\SessionNonce;
use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeGateway;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\ExpressButtons;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;
use Flexa\Commerce\Tests\Integration\Payments\FakeStripe;

/**
 * Apple Pay and Google Pay (ADR-053): `store/express` quotes what the
 * wallet sheet shows, `store/express/order` places the order, for the
 * cart or for one product bought on its own page.
 */
final class ExpressCheckoutTest extends OrderTestCase {
	private \WP_REST_Server $server;

	private MemoryTokenStore $cookie;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		$this->cookie = new MemoryTokenStore();
		FakeStripe::install();
		FakeStripe::connectable();
		FakeStripe::$routes['POST payment_intents'] = [
			200,
			[
				'id'              => 'pi_wallet',
				'object'          => 'payment_intent',
				'status'          => 'succeeded',
				'amount'          => 0,
				'amount_received' => 0,
				'currency'        => 'usd',
				'livemode'        => false,
			],
		];
		$gateways                                   = Plugin::instance()->payments()->gateways();
		$stripe                                     = $gateways->get( StripeGateway::ID );
		self::assertNotNull( $stripe );
		$gateways->settings()->save(
			$stripe,
			[
				'enabled'              => true,
				'mode'                 => 'test',
				'test_publishable_key' => 'pk_test_abc123',
				'test_secret_key'      => 'sk_test_abc123',
			]
		);
		$this->next_request();
	}

	public function tear_down(): void {
		global $wpdb;
		$this->swap_cart( null );
		FakeStripe::uninstall();
		parent::tear_down();
		// The orders committed the keys saved in set_up, so they go after the rollback.
		delete_option( GatewaySettings::PREFIX . StripeGateway::ID );
		delete_option( StripeGateway::STATUS_OPTION );
		\Flexa\Commerce\Support\WalletPlaces::put( StripeGateway::ID, [] );
		FakeStripe::forget( StripeGateway::ID );
		$wpdb->query( 'COMMIT' );
	}

	public function test_the_quote_prices_the_cart_or_one_product(): void {
		$this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $this->simple( 1500 ) ] );

		$this->next_request();
		$cart = $this->dispatch( 'POST', '/store/express', [ 'payment_method' => 'stripe' ] );
		self::assertSame( 200, $cart->get_status(), (string) wp_json_encode( $cart->get_data() ) );
		$data = $cart->get_data();
		self::assertSame( 1500, $data['total'] );
		self::assertTrue( $data['payable'] );
		self::assertSame( 'USD', $data['currency'] );
		self::assertNotSame( '', $data['checkout_token'] );
		self::assertSame( 1500, $data['lines'][0]['amount'] );

		$this->next_request();
		$single = $this->dispatch(
			'POST',
			'/store/express',
			[
				'payment_method' => 'stripe',
				'source'         => 'product',
				'product'        => [
					'product_id' => $this->simple( 2000 ),
					'quantity'   => 2,
				],
			]
		);
		self::assertSame( 200, $single->get_status(), (string) wp_json_encode( $single->get_data() ) );
		self::assertSame( 4000, $single->get_data()['total'] );
		self::assertSame( '', $single->get_data()['checkout_token'] );

		$this->next_request();
		$other = $this->dispatch( 'POST', '/store/express', [ 'payment_method' => 'missing' ] );
		self::assertFalse( $other->get_data()['payable'], 'a gateway the store does not have' );
	}

	public function test_an_empty_cart_has_no_quote(): void {
		$response = $this->dispatch( 'POST', '/store/express', [ 'payment_method' => 'stripe' ] );
		self::assertSame( 400, $response->get_status() );
	}

	public function test_buy_now_places_one_product_and_leaves_the_cart_alone(): void {
		$in_cart = $this->simple( 1500 );
		$this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $in_cart ] );
		$bought = $this->simple( 2000, 5 );
		$token  = wp_generate_uuid4();
		$body   = [
			'payment_method' => 'stripe',
			'source'         => 'product',
			'product'        => [
				'product_id' => $bought,
				'quantity'   => 2,
			],
			'checkout_token' => $token,
			'expected_total' => 4000,
			'billing'        => $this->wallet_address(),
			'gateway_input'  => [
				'payment_method'      => 'pm_wallet',
				'payment_method_type' => 'card',
				'express'             => 'apple_pay',
			],
		];

		$this->next_request();
		$placed = $this->dispatch( 'POST', '/store/express/order', $body );
		self::assertSame( 201, $placed->get_status(), (string) wp_json_encode( $placed->get_data() ) );
		$order = $this->orders()->get( (int) $placed->get_data()['order_id'] );
		self::assertSame( 4000, $order->total );
		self::assertSame( 'processing', $order->status );
		self::assertStringContainsString( '(Apple Pay)', $order->payment_method_title );
		self::assertSame( 'apple_pay', Plugin::instance()->orders()->orders()->get_meta( $order->id, '_fxc_express' ) );
		self::assertSame( 'CA', $order->billing?->state, 'the state name the wallet sent became its code' );
		self::assertSame( 3, $this->stock_of( $bought ) );
		self::assertSame( 'pm_wallet', FakeStripe::calls_to( 'POST', 'payment_intents' )[0]['body']['payment_method'] );

		$this->next_request();
		$cart = $this->dispatch( 'GET', '/store/cart' );
		self::assertSame( 1, $cart->get_data()['item_count'], 'the cart keeps its product' );
		self::assertSame( $in_cart, $cart->get_data()['items'][0]['product_id'] );

		// The wallet sending the order twice makes one order.
		$this->next_request();
		$again = $this->dispatch( 'POST', '/store/express/order', $body );
		self::assertSame( $order->id, (int) $again->get_data()['order_id'] );
		self::assertCount( 1, FakeStripe::calls_to( 'POST', 'payment_intents' ) );
	}

	public function test_paying_the_cart_with_google_pay_empties_it(): void {
		$this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $this->simple( 1500 ) ] );
		$this->next_request();
		$quote = $this->dispatch( 'POST', '/store/express', [ 'payment_method' => 'stripe' ] )->get_data();

		$this->next_request();
		$placed = $this->dispatch(
			'POST',
			'/store/express/order',
			[
				'payment_method' => 'stripe',
				'checkout_token' => $quote['checkout_token'],
				'expected_total' => $quote['total'],
				'billing'        => $this->wallet_address(),
				'gateway_input'  => [
					'payment_method'      => 'pm_wallet',
					'payment_method_type' => 'card',
					'express'             => 'google_pay',
				],
			]
		);
		self::assertSame( 201, $placed->get_status(), (string) wp_json_encode( $placed->get_data() ) );
		self::assertStringContainsString( '(Google Pay)', $this->orders()->get( (int) $placed->get_data()['order_id'] )->payment_method_title );

		$this->next_request();
		self::assertSame( 0, $this->dispatch( 'GET', '/store/cart' )->get_data()['item_count'] );
	}

	public function test_a_changed_price_stops_the_wallet_order(): void {
		$body     = [
			'payment_method' => 'stripe',
			'source'         => 'product',
			'product'        => [ 'product_id' => $this->simple( 2000 ) ],
			'checkout_token' => wp_generate_uuid4(),
			'expected_total' => 1999,
			'billing'        => $this->wallet_address(),
			'gateway_input'  => [
				'payment_method' => 'pm_wallet',
				'express'        => 'apple_pay',
			],
		];
		$response = $this->dispatch( 'POST', '/store/express/order', $body );
		self::assertSame( 409, $response->get_status() );
		self::assertSame( [], FakeStripe::calls_to( 'POST', 'payment_intents' ) );
	}

	public function test_the_buttons_render_only_with_keys_and_their_place_on(): void {
		$product = ExpressButtons::html( 'product', [ 'product_id' => 7 ] );
		self::assertStringContainsString( 'data-fxc-express=', $product );
		self::assertStringContainsString( ' hidden>', $product );
		self::assertStringContainsString( '&quot;product_id&quot;:7', $product );
		self::assertStringContainsString( '&quot;payment_method&quot;:&quot;stripe&quot;', $product );
		self::assertStringNotContainsString( 'sk_test', $product );
		self::assertStringContainsString( 'fxc-express__or', ExpressButtons::html( 'checkout' ) );

		$gateways = Plugin::instance()->payments()->gateways();
		$stripe   = $gateways->get( StripeGateway::ID );
		self::assertNotNull( $stripe );
		$gateways->settings()->save( $stripe, [ 'express_cart' => false ] );
		self::assertSame( '', ExpressButtons::html( 'cart' ) );
		$gateways->settings()->save( $stripe, [ 'enabled' => false ] );
		self::assertSame( '', ExpressButtons::html( 'product' ) );
	}

	public function test_a_required_field_wallets_cannot_fill_keeps_the_buttons_on_the_checkout(): void {
		$data = CheckoutLayout::default()->to_array();
		foreach ( $data['fields']['billing'] as $i => $entry ) {
			if ( $entry['id'] === 'company' ) {
				$data['fields']['billing'][ $i ]['answer'] = 'required';
			}
		}
		CheckoutLayout::save( $data );

		self::assertSame( '', ExpressButtons::html( 'product', [ 'product_id' => 7 ] ) );
		self::assertSame( '', ExpressButtons::html( 'cart' ) );
		$checkout = ExpressButtons::html( 'checkout' );
		self::assertStringContainsString( 'billing[company]', $checkout, 'the shopper fills it in the form first' );
	}

	public function test_state_names_become_codes(): void {
		self::assertSame( 'CA', Countries::state_code( 'US', 'California' ) );
		self::assertSame( 'CA', Countries::state_code( 'US', 'ca' ) );
		self::assertSame( 'Somewhere', Countries::state_code( 'US', 'Somewhere' ) );
		self::assertSame( '', Countries::state_code( 'US', '' ) );
	}

	/** @return array<string,string> */
	private function wallet_address(): array {
		return [
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'address_1'  => '1 Market St',
			'city'       => 'San Francisco',
			'state'      => 'California',
			'postcode'   => '94105',
			'country'    => 'us',
			'email'      => 'ada@example.com',
			'phone'      => '+14155550100',
		];
	}

	/** A new request: fresh services, the browser's cookie carried over. */
	private function next_request(): void {
		$this->swap_cart( new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), $this->cookie ) );
	}

	private function swap_cart( ?CartServices $services ): void {
		$property = new \ReflectionProperty( Plugin::class, 'cart' );
		$property->setValue( Plugin::instance(), $services );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
	}

	/** @param array<string,mixed>|null $body */
	private function dispatch( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_header( 'origin', home_url() );
		$request->set_header( SessionNonce::HEADER, Plugin::instance()->cart()->nonce()->create( get_current_user_id(), wp_get_session_token(), $this->cookie->get() ) );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return $this->server->dispatch( $request );
	}
}
