<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Checkout;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Setup\Pages;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Storefront\CheckoutPage;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;
use Flexa\Commerce\Tests\Integration\Payments\FakeGateway;

/**
 * The checkout without JavaScript: the form posts to the page, the page
 * redirects on success and renders again with errors otherwise. Redirects
 * end in exit, so they are turned into an exception carrying the location.
 */
final class CheckoutPageTest extends OrderTestCase {
	private const BILLING = [
		'email'      => 'grace@example.com',
		'first_name' => 'Grace',
		'last_name'  => 'Hopper',
		'address_1'  => '1 Navy Way',
		'city'       => 'Arlington',
		'state'      => 'VA',
		'postcode'   => '22202',
		'country'    => 'US',
	];

	private MemoryTokenStore $cookie;

	private string $rate_id = '';

	public function set_up(): void {
		parent::set_up();
		$gateways = Plugin::instance()->payments()->gateways();
		if ( ! $gateways->has( FakeGateway::ID ) ) {
			$gateways->register( FakeGateway::ID, static fn (): PaymentGateway => new FakeGateway() );
		}
		foreach ( [ 'cod', 'bacs', FakeGateway::ID ] as $id ) {
			$gateway = $gateways->get( $id );
			self::assertNotNull( $gateway );
			$gateways->settings()->save(
				$gateway,
				[
					'enabled'        => true,
					'account_number' => '12345678',
				]
			);
		}
		FakeGateway::$payment_mode = 'redirect';

		$zones         = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		$this->rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;

		$this->cookie = new MemoryTokenStore();
		$this->swap( $this->browser( $this->cookie ) );
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new \RuntimeException( $location );
			}
		);
		$_SERVER['HTTP_REFERER'] = home_url( '/checkout/' );
	}

	public function tear_down(): void {
		$_POST = [];
		$_GET  = [];
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ORIGIN'] );
		foreach ( [ 'cod', 'bacs', FakeGateway::ID ] as $id ) {
			delete_option( GatewaySettings::PREFIX . $id );
		}
		delete_option( ZoneRepository::OPTION );
		$this->swap( null );
		parent::tear_down();
	}

	public function test_the_form_places_an_order_without_javascript(): void {
		$product = $this->simple( 1000, 5 );
		$this->add( $product, 1 );

		$html = do_shortcode( '[flexacommerce_checkout]' );
		self::assertStringContainsString( 'name="fxc_checkout"', $html );
		self::assertStringContainsString( 'name="billing[email]"', $html );
		self::assertStringContainsString( 'value="' . $this->rate_id . '"', $html );
		self::assertStringContainsString( 'name="expected_total" value="1500"', $html );
		self::assertStringContainsString( 'name="payment_method" value="cod"', $html );

		$location = $this->post( $this->form( $this->token( $html ), [ 'expected_total' => '1500' ] ) );
		self::assertNotNull( $location );
		self::assertStringContainsString( 'order-received=', $location );
		self::assertSame( 4, $this->stock_of( $product ) );

		$html = $this->visit( $location );
		self::assertStringContainsString( 'Thank you. Your order has been received.', $html );
		self::assertStringContainsString( 'grace@example.com', $html );
		self::assertStringContainsString( 'Arlington', $html );
	}

	public function test_errors_render_next_to_their_fields_with_the_posted_values(): void {
		$this->add( $this->simple( 1000, 5 ), 1 );
		$token = $this->token( do_shortcode( '[flexacommerce_checkout]' ) );

		$billing = array_merge( self::BILLING, [ 'email' => '' ] );
		self::assertNull( $this->post( $this->form( $token, [ 'billing' => $billing ] ) ) );

		$html = CheckoutPage::instance()->render();
		self::assertStringContainsString( 'href="#fxc-billing-email"', $html );
		self::assertMatchesRegularExpression( '/id="fxc-billing-email"[^>]*aria-invalid="true"/', $html );
		self::assertStringContainsString( 'value="Grace"', $html, 'posted values are kept' );
		self::assertNull( $this->orders()->find_by_checkout_token( $token ) );
	}

	public function test_group_errors_describe_their_fieldset_and_the_summary_links_them(): void {
		$this->add( $this->simple( 1000, 5 ), 1 );
		$token = $this->token( do_shortcode( '[flexacommerce_checkout]' ) );
		self::assertNull(
			$this->post(
				$this->form(
					$token,
					[
						'shipping_rate'  => 'no-such-rate',
						'payment_method' => 'no-such-method',
					]
				)
			)
		);

		$html = CheckoutPage::instance()->render();
		self::assertMatchesRegularExpression( '#<div class="fxc-checkout__errors[^"]*" role="alert" data-fxc-errors tabindex="-1">#', $html );
		self::assertStringContainsString( 'href="#fxc-payment-method"', $html );
		self::assertStringContainsString( '<fieldset class="fxc-checkout__methods" id="fxc-payment-method" aria-describedby="fxc-payment-method-error">', $html );
		self::assertStringContainsString( 'id="fxc-payment-method-error"', $html );
		self::assertStringContainsString( 'aria-controls="fxc-shipping-address"', $html );

		Settings::update( [ 'terms_page_id' => self::factory()->post->create( [ 'post_type' => 'page' ] ) ] );
		$html = do_shortcode( '[flexacommerce_checkout]' );
		Settings::update( [ 'terms_page_id' => 0 ] );
		self::assertStringContainsString( 'terms and conditions<span class="screen-reader-text"> (opens in a new tab)</span></a>', $html );
	}

	public function test_a_post_from_another_site_or_with_a_stale_nonce_changes_nothing(): void {
		$this->add( $this->simple( 1000, 5 ), 1 );
		$token = $this->token( do_shortcode( '[flexacommerce_checkout]' ) );

		$_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
		self::assertNull( $this->post( $this->form( $token ) ) );
		unset( $_SERVER['HTTP_ORIGIN'] );
		self::assertNull( $this->post( $this->form( $token, [ 'fxc_nonce' => 'stale' ] ) ) );

		self::assertStringContainsString( 'Your session has expired', CheckoutPage::instance()->render() );
		self::assertNull( $this->orders()->find_by_checkout_token( $token ) );
	}

	public function test_update_button_requotes_without_placing_the_order(): void {
		$this->add( $this->simple( 1000, 5 ), 1 );
		$token = $this->token( do_shortcode( '[flexacommerce_checkout]' ) );

		self::assertNull( $this->post( $this->form( $token, [ 'fxc_refresh' => '1' ] ) ) );
		self::assertNull( $this->orders()->find_by_checkout_token( $token ) );
		self::assertSame( 'VA', Plugin::instance()->cart()->cart()->state( 0 )->cart->destination['state'] );
	}

	public function test_order_views_need_the_key_and_show_bank_details(): void {
		$this->add( $this->simple( 1000, 5 ), 1 );
		$token    = $this->token( do_shortcode( '[flexacommerce_checkout]' ) );
		$location = (string) $this->post( $this->form( $token, [ 'payment_method' => 'bacs' ] ) );
		$order    = $this->orders()->find_by_checkout_token( $token );
		self::assertNotNull( $order );
		self::assertSame( OrderStatus::ON_HOLD, $order->status );

		$html = $this->visit( $location );
		self::assertStringContainsString( 'How to pay', $html );
		self::assertStringContainsString( '12345678', $html );

		$html = $this->visit( add_query_arg( 'key', 'wrong', $location ) );
		self::assertStringContainsString( 'Order not found', $html );
		self::assertStringNotContainsString( 'grace@example.com', $html );
	}

	public function test_return_from_a_gateway_redirects_to_the_clean_confirmation(): void {
		$this->add( $this->simple( 1000, 5 ), 1 );
		$token    = $this->token( do_shortcode( '[flexacommerce_checkout]' ) );
		$location = (string) $this->post( $this->form( $token, [ 'payment_method' => FakeGateway::ID ] ) );
		self::assertStringStartsWith( 'https://pay.example.test/', $location, 'a gateway URL leaves the site' );

		$order = $this->orders()->find_by_checkout_token( $token );
		self::assertNotNull( $order );
		$txn  = Plugin::instance()->payments()->transactions()->for_order( $order->id )[0];
		$back = add_query_arg( CheckoutService::RETURN_ARG, $txn->id, Plugin::instance()->checkout()->pages()->confirmation_url( $order ) );

		$clean = $this->visit( $back, true );
		self::assertSame( Plugin::instance()->checkout()->pages()->confirmation_url( $order ), $clean );
		self::assertSame( OrderStatus::PENDING, $this->orders()->get( $order->id )->status, 'an unsigned return does not mark the order paid' );
	}

	public function test_a_failed_payment_is_retried_on_the_order_pay_page(): void {
		FakeGateway::$payment_mode = 'failed';
		$this->add( $this->simple( 1000, 5 ), 1 );
		$token    = $this->token( do_shortcode( '[flexacommerce_checkout]' ) );
		$location = (string) $this->post( $this->form( $token, [ 'payment_method' => FakeGateway::ID ] ) );
		self::assertStringContainsString( 'order-pay=', $location );

		$html = $this->visit( $location );
		self::assertStringContainsString( 'name="fxc_pay"', $html );
		self::assertStringContainsString( 'value="cod"', $html );

		$this->get_args( $location );
		$_POST = [
			'fxc_pay'        => '1',
			'fxc_nonce'      => $this->nonce(),
			'payment_method' => 'cod',
		];
		$done  = $this->handle();
		self::assertNotNull( $done );
		self::assertStringContainsString( 'order-received=', $done );
		$order = $this->orders()->find_by_checkout_token( $token );
		self::assertSame( OrderStatus::PROCESSING, $order?->status );
		self::assertSame( 'cod', $order?->payment_method );
	}

	public function test_activation_creates_missing_pages_once(): void {
		Settings::update(
			[
				'cart_page_id'     => 0,
				'checkout_page_id' => 0,
			]
		);
		Pages::install();
		$cart     = Settings::int( 'cart_page_id' );
		$checkout = Settings::int( 'checkout_page_id' );
		self::assertGreaterThan( 0, $cart );
		self::assertStringContainsString( 'wp:flexacommerce/checkout', (string) get_post_field( 'post_content', $checkout ) );

		Pages::install();
		self::assertSame( $cart, Settings::int( 'cart_page_id' ) );
		self::assertSame( $checkout, Settings::int( 'checkout_page_id' ) );
	}

	private function add( int $product_id, int $qty ): void {
		Plugin::instance()->cart()->cart()->add( 0, $product_id, $this->service()->get( $product_id )->base->id, $qty );
	}

	private function token( string $html ): string {
		self::assertSame( 1, preg_match( '/name="checkout_token" value="([^"]+)"/', $html, $m ) );
		return $m[1];
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function form( string $token, array $overrides = [] ): array {
		return $overrides + [
			'fxc_checkout'   => '1',
			'fxc_nonce'      => $this->nonce(),
			'checkout_token' => $token,
			'billing'        => self::BILLING,
			'shipping_rate'  => $this->rate_id,
			'payment_method' => 'cod',
		];
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return string|null the redirect, or null when the page renders again
	 */
	private function post( array $fields ): ?string {
		$_GET  = [];
		$_POST = $fields;
		return $this->handle();
	}

	/** GET a checkout URL and render it; with `$expect_redirect` returns the redirect instead. */
	private function visit( string $url, bool $expect_redirect = false ): string {
		$_POST = [];
		$this->get_args( $url );
		$location = $this->handle();
		if ( $expect_redirect ) {
			self::assertNotNull( $location );
			return $location;
		}
		self::assertNull( $location );
		return CheckoutPage::instance()->render();
	}

	private function get_args( string $url ): void {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$_GET = $args;
	}

	private function handle(): ?string {
		( new \ReflectionProperty( CheckoutPage::class, 'instance' ) )->setValue( null, null );
		try {
			CheckoutPage::instance()->handle();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return null;
	}

	private function nonce(): string {
		return Plugin::instance()->cart()->nonce()->create( 0, wp_get_session_token(), $this->cookie->get() );
	}

	private function swap( ?CartServices $cart ): void {
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), $cart );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'shipping' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( CheckoutPage::class, 'instance' ) )->setValue( null, null );
	}
}
