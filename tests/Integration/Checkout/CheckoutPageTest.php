<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Checkout;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Orders\OrderFields;
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
		CheckoutLayout::preview( null );
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

	public function test_without_a_layout_the_form_is_the_one_of_before(): void {
		$this->add( $this->simple( 1000, 5 ), 1 );
		$html = do_shortcode( '[flexacommerce_checkout]' );
		self::assertStringNotContainsString( 'name="billing[company]"', $html );
		self::assertStringContainsString( '<p class="fxc-field fxc-field--first_name fxc-field--half"', $html );
		self::assertStringContainsString( 'id="fxc-customer-note"', $html );
		self::assertStringNotContainsString( 'fxc-checkout__additional', $html, 'the notes alone need no fieldset' );
		self::assertLessThan( strpos( $html, 'name="billing[country]"' ), strpos( $html, 'name="billing[last_name]"' ) );
		// The page of before: billing, shipping and notes on the left; shipping, summary and payment on the right.
		self::assertStringContainsString( '<div class="fxc-checkout__columns"><div class="fxc-checkout__details"><fieldset class="fxc-checkout__address" data-fxc-address="billing"><legend>Billing details</legend>', $html );
		self::assertMatchesRegularExpression( '#</fieldset><p class="fxc-checkout__toggle">.*</fieldset></div><p class="fxc-field fxc-field--note">.*</p></div><div class="fxc-checkout__review"><fieldset class="fxc-checkout__rates" id="fxc-shipping-rate" data-fxc-rates><legend>Shipping</legend>#s', $html );
		self::assertMatchesRegularExpression( '#</fieldset><table class="fxc-checkout__summary"><caption>Your order</caption>.*</table><div data-fxc-methods><fieldset class="fxc-checkout__methods" id="fxc-payment-method"><legend>Payment</legend>#s', $html );
		self::assertStringContainsString( '<p class="fxc-checkout__actions"><button type="submit" class="wp-element-button fxc-checkout__place" data-fxc-place>Place order</button></p><p class="fxc-checkout__message" role="status" aria-live="polite" data-fxc-message></p></div></div></form></div>', $html );
		self::assertStringNotContainsString( 'data-fxc-fold', $html );
	}

	public function test_the_page_puts_sections_in_one_column_with_headings_and_paragraphs(): void {
		CheckoutLayout::save(
			[
				'page' => [
					'columns' => 'one',
					'summary' => 'top',
					'main'    => [
						[
							'id'    => 'billing',
							'title' => 'Your details',
						],
						[
							'id'   => 'text_a1',
							'type' => 'heading',
							'text' => 'Delivery',
						],
						[ 'id' => 'shipping_method' ],
						[ 'id' => 'shipping' ],
						[ 'id' => 'additional' ],
						[
							'id'   => 'text_b2',
							'type' => 'paragraph',
							'text' => 'Questions? <a href="https://example.com/help">Ask us</a><script>alert(1)</script>',
						],
						[ 'id' => 'summary' ],
					],
					'side'    => [ [ 'id' => 'payment' ] ],
				],
			]
		);
		$this->add( $this->simple( 1000, 5 ), 1 );
		$html = do_shortcode( '[flexacommerce_checkout]' );

		self::assertStringContainsString( '<div class="fxc-checkout__columns fxc-checkout__columns--one fxc-checkout__columns--fold"><div class="fxc-checkout__details">', $html );
		self::assertStringNotContainsString( 'fxc-checkout__review', $html, 'one column' );
		self::assertStringContainsString( '<legend>Your details</legend>', $html );
		$heading = (int) strpos( $html, '<h2 class="fxc-checkout__heading">Delivery</h2>' );
		self::assertGreaterThan( 0, $heading );
		self::assertLessThan( strpos( $html, 'data-fxc-rates' ), $heading );
		self::assertLessThan( strpos( $html, 'data-fxc-ship-toggle' ), strpos( $html, 'data-fxc-rates' ), 'shipping method before the shipping address' );
		self::assertStringContainsString( '<div class="fxc-checkout__text"><p>Questions? <a href="https://example.com/help">Ask us</a>alert(1)</p>', $html );
		self::assertStringNotContainsString( '<script>alert', $html );
		self::assertMatchesRegularExpression( '#<details class="fxc-checkout__fold" open data-fxc-fold><summary>Order summary <strong data-fxc-fold-total>[^<]+</strong></summary><table class="fxc-checkout__summary">#', $html );
		self::assertStringContainsString( 'data-fxc-place>Place order</button></p><p class="fxc-checkout__message" role="status" aria-live="polite" data-fxc-message></p></div></div></form></div>', $html, 'payment ends the page' );

		$token = $this->token( $html );
		self::assertNotNull( $this->post( $this->form( $token, [ 'expected_total' => '1500' ] ) ), 'the order goes through as before' );
	}

	public function test_preview_shows_the_draft_to_its_manager_only(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		CheckoutLayout::store_draft(
			$admin,
			[
				'page' => [
					'columns' => 'one',
					'main'    => [
						[
							'id'   => 'text_a1',
							'type' => 'heading',
							'text' => 'Almost there',
						],
						[ 'id' => 'billing' ],
						[ 'id' => 'shipping' ],
						[ 'id' => 'additional' ],
						[ 'id' => 'shipping_method' ],
						[ 'id' => 'summary' ],
						[ 'id' => 'payment' ],
					],
				],
			]
		);
		$url = add_query_arg( CheckoutLayout::PREVIEW_ARG, wp_create_nonce( CheckoutLayout::PREVIEW_NONCE ), home_url( '/checkout/' ) );

		$html = $this->visit( $url );
		self::assertStringContainsString( 'Almost there', $html );
		self::assertStringContainsString( 'Sample product', $html, 'an empty cart shows a sample' );
		self::assertStringContainsString( 'fxc-checkout__preview', $html );
		self::assertStringContainsString( 'data-fxc-place disabled', $html );
		self::assertFalse( get_option( CheckoutLayout::OPTION ), 'the stored layout is untouched' );
		CheckoutLayout::preview( null );

		self::assertStringContainsString( 'Your cart is empty.', $this->visit( home_url( '/checkout/' ) ), 'without the link' );
		self::assertStringContainsString( 'Your cart is empty.', $this->visit( add_query_arg( CheckoutLayout::PREVIEW_ARG, 'nope', home_url( '/checkout/' ) ) ), 'a wrong nonce' );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		CheckoutLayout::store_draft( $editor, [] );
		$html = $this->visit( add_query_arg( CheckoutLayout::PREVIEW_ARG, wp_create_nonce( CheckoutLayout::PREVIEW_NONCE ), home_url( '/checkout/' ) ) );
		self::assertStringContainsString( 'Your cart is empty.', $html, 'only managers preview' );
		delete_transient( 'flexacommerce_checkout_preview_' . $admin );
		delete_transient( 'flexacommerce_checkout_preview_' . $editor );
	}

	public function test_the_layout_orders_renames_and_adds_fields(): void {
		CheckoutLayout::save(
			[
				'fields' => [
					'billing'    => [
						[
							'id'    => 'phone',
							'label' => 'Mobile number',
							'help'  => 'The courier calls this number.',
						],
						[
							'id'     => 'company',
							'answer' => 'optional',
						],
					],
					'additional' => [
						[
							'id'     => 'gift',
							'type'   => 'textarea',
							'label'  => 'Gift message',
							'answer' => 'required',
						],
						[
							'id'      => 'slot',
							'type'    => 'radio',
							'label'   => 'Delivery time',
							'options' => [
								[
									'value' => 'am',
									'label' => 'Morning',
								],
								[
									'value' => 'pm',
									'label' => 'Afternoon',
								],
							],
						],
						[ 'id' => 'customer_note' ],
					],
				],
			]
		);
		$product = $this->simple( 1000, 5 );
		$this->add( $product, 1 );
		$html = do_shortcode( '[flexacommerce_checkout]' );
		self::assertLessThan( strpos( $html, 'name="billing[email]"' ), strpos( $html, 'name="billing[phone]"' ), 'phone comes first' );
		self::assertStringContainsString( '<label for="fxc-billing-phone">Mobile number</label>', $html );
		self::assertMatchesRegularExpression( '#id="fxc-billing-phone"[^>]*aria-describedby="fxc-billing-phone-help"#', $html );
		self::assertStringContainsString( '<span class="fxc-field__help" id="fxc-billing-phone-help">The courier calls this number.</span>', $html );
		self::assertStringContainsString( 'name="billing[company]"', $html );
		self::assertStringContainsString( '<fieldset class="fxc-checkout__additional"><legend>Additional information</legend>', $html );
		self::assertMatchesRegularExpression( '#<textarea[^>]*name="additional\[gift\]"[^>]*required#', $html );
		self::assertStringContainsString( 'id="fxc-additional-slot-1"', $html );

		$token = $this->token( $html );
		self::assertNull( $this->post( $this->form( $token, [ 'expected_total' => '1500' ] ) ), 'the gift message is required' );
		$again = CheckoutPage::instance()->render();
		self::assertStringContainsString( 'href="#fxc-additional-gift"', $again );
		self::assertMatchesRegularExpression( '#<textarea[^>]*aria-invalid="true"[^>]*aria-describedby="fxc-additional-gift-error"#', $again );

		$location = $this->post(
			$this->form(
				$token,
				[
					'expected_total' => '1500',
					'additional'     => [
						'gift' => 'Happy birthday',
						'slot' => 'pm',
					],
				]
			)
		);
		self::assertNotNull( $location );
		$order = $this->orders()->find_by_checkout_token( $token );
		self::assertNotNull( $order );
		$repo = Plugin::instance()->orders()->orders();
		self::assertSame( 'pm', $repo->get_meta( $order->id, '_additional_slot' ) );
		self::assertSame( [ 'Gift message', 'Delivery time' ], array_column( OrderFields::for_order( $repo, $order->id ), 'label' ) );

		CheckoutLayout::reset();
		$html = $this->visit( $location );
		self::assertStringContainsString( '<dt>Delivery time</dt><dd>Afternoon</dd>', $html, 'the answer keeps its label after the field is gone' );
	}

	public function test_fields_follow_the_country_and_the_cart(): void {
		$parent  = (int) wp_insert_term( 'Jewellery', PostTypes::CATEGORY )['term_id'];
		$child   = (int) wp_insert_term( 'Rings', PostTypes::CATEGORY, [ 'parent' => $parent ] )['term_id'];
		$product = $this->simple( 1000, 5 );
		wp_set_object_terms( $product, [ $child ], PostTypes::CATEGORY );
		CheckoutLayout::save(
			[
				'fields' => [
					'billing'    => [
						[
							'id'     => 'vat',
							'type'   => 'text',
							'label'  => 'VAT number',
							'answer' => 'required',
							'when'   => [ 'countries' => [ 'DE', 'FR' ] ],
						],
					],
					'additional' => [
						[
							'id'    => 'engraving',
							'type'  => 'text',
							'label' => 'Engraving',
							'when'  => [ 'categories' => [ $parent ] ],
						],
						[
							'id'    => 'pickup',
							'type'  => 'text',
							'label' => 'Pickup time',
							'when'  => [ 'shipping' => 'no_shipping' ],
						],
					],
				],
			]
		);
		$this->add( $product, 1 );
		$html = do_shortcode( '[flexacommerce_checkout]' );
		self::assertMatchesRegularExpression( '#<p class="[^"]*fxc-field--vat[^"]*" data-fxc-countries="DE FR" hidden>#', $html, 'kept for the page to show when the country changes' );
		self::assertMatchesRegularExpression( '#<input[^>]*name="billing\[vat\]"[^>]*disabled#', $html, 'a hidden field is not posted' );
		self::assertStringContainsString( 'name="additional[engraving]"', $html, 'the ring is in a subcategory' );
		self::assertStringNotContainsString( 'additional[pickup]', $html, 'the order ships' );

		$token    = $this->token( $html );
		$location = $this->post( $this->form( $token, [ 'expected_total' => '1500' ] + [ 'additional' => [ 'engraving' => 'A+G' ] ] ) );
		self::assertNotNull( $location, 'the VAT number is for Germany and France' );
		$order = $this->orders()->find_by_checkout_token( $token );
		self::assertNotNull( $order );
		self::assertSame( [ 'Engraving' ], array_column( OrderFields::for_order( Plugin::instance()->orders()->orders(), $order->id ), 'label' ) );
		wp_delete_term( $child, PostTypes::CATEGORY );
		wp_delete_term( $parent, PostTypes::CATEGORY );
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
