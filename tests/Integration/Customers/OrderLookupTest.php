<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Customers;

use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Setup\Pages;
use Flexa\Commerce\Storefront\AccountPage;
use Flexa\Commerce\Storefront\OrderLookup;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * Guest order lookup (ADR-057): number and email lead to the order page
 * with its key, failures share one message and are rate limited, and the
 * key opens the order without an account. Redirects become an exception
 * carrying the location.
 */
final class OrderLookupTest extends OrderTestCase {
	private Order $order;

	public function set_up(): void {
		parent::set_up();
		Pages::install();
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new \RuntimeException( $location );
			}
		);
		$_SERVER['REMOTE_ADDR']  = '203.0.113.9';
		$_SERVER['HTTP_REFERER'] = home_url( '/my-account/' );
		$this->order             = $this->place( [ $this->simple( 1000, 5 ) => 1 ] );
	}

	public function tear_down(): void {
		$_POST = [];
		$_GET  = [];
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ORIGIN'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_guests_get_the_lookup_form_under_the_sign_in_form(): void {
		$html = AccountPage::instance()->render();
		self::assertStringContainsString( 'id="fxc-login"', $html );
		self::assertStringContainsString( 'name="' . OrderLookup::ACTION . '"', $html );
		self::assertStringContainsString( 'name="order_email"', $html );

		self::assertStringContainsString( 'name="order_number"', do_shortcode( '[flexacommerce_order_tracking]' ) );
		$block = do_blocks( '<!-- wp:flexacommerce/order-lookup /-->' );
		self::assertStringContainsString( 'wp-block-flexacommerce-order-lookup', $block );
		self::assertStringContainsString( 'Find order', $block );
	}

	public function test_number_and_email_lead_to_the_order_with_its_key(): void {
		$location = $this->lookup( '#' . $this->order->order_number, strtoupper( $this->order->customer_email ) );
		self::assertNotNull( $location );
		self::assertSame( OrderLookup::url( $this->order ), $location );
		self::assertStringContainsString( 'key=' . $this->order->order_key, $location );
		self::assertStringContainsString( 'fxc_view_order=' . $this->order->id, $location );
	}

	public function test_a_wrong_email_or_number_gets_one_message_and_counts(): void {
		self::assertNull( $this->lookup( $this->order->order_number, 'someone@example.com' ) );
		$wrong_email = OrderLookup::instance()->render();
		self::assertNull( $this->lookup( 'NOPE-404', $this->order->customer_email ) );
		$wrong_number = OrderLookup::instance()->render();

		self::assertStringContainsString( 'No order matches that order number and email address.', $wrong_email );
		self::assertStringContainsString( 'No order matches that order number and email address.', $wrong_number );
		self::assertStringContainsString( 'value="NOPE-404"', $wrong_number, 'The form keeps what was typed.' );
		self::assertSame( 2, ( new RateLimiter() )->attempts( OrderLookup::LIMIT, '203.0.113.9' ) );
	}

	public function test_empty_fields_are_reported_without_counting(): void {
		self::assertNull( $this->lookup( '', 'not-an-email' ) );
		$errors = OrderLookup::instance()->errors();
		self::assertArrayHasKey( OrderLookup::NUMBER, $errors );
		self::assertArrayHasKey( OrderLookup::EMAIL, $errors );
		self::assertStringContainsString( 'href="#fxc-order-number"', OrderLookup::instance()->render() );
		self::assertSame( 0, ( new RateLimiter() )->attempts( OrderLookup::LIMIT, '203.0.113.9' ) );
	}

	public function test_too_many_failures_block_even_the_right_answer(): void {
		add_filter(
			'flexacommerce.rate_limit.' . OrderLookup::LIMIT,
			static fn (): array => [
				'limit'  => 2,
				'window' => 3600,
			]
		);
		$this->lookup( $this->order->order_number, 'a@example.com' );
		$this->lookup( $this->order->order_number, 'b@example.com' );
		self::assertNull( $this->lookup( $this->order->order_number, $this->order->customer_email ) );
		self::assertStringContainsString( 'Too many lookups', OrderLookup::instance()->errors()['form'] ?? '' );

		// Another address is not blocked.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
		self::assertNotNull( $this->lookup( $this->order->order_number, $this->order->customer_email ) );
	}

	public function test_a_post_from_another_site_is_refused(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
		self::assertNull( $this->lookup( $this->order->order_number, $this->order->customer_email ) );
		self::assertStringContainsString( 'Your session has expired', OrderLookup::instance()->errors()['form'] ?? '' );
	}

	public function test_the_key_opens_the_order_for_a_guest(): void {
		$this->orders()->add_note( $this->order->id, 'Packed and on its way.', true, new TransitionContext( 'admin', 1 ) );
		$_GET = [
			'fxc_view_order' => (string) $this->order->id,
			'key'            => $this->order->order_key,
		];
		$html = AccountPage::instance()->render();
		self::assertStringContainsString( esc_html( $this->order->order_number ), $html );
		self::assertStringContainsString( 'Packed and on its way.', $html );
		self::assertStringContainsString( 'fxc-account--guest', $html );
		self::assertStringNotContainsString( 'fxc-account__nav', $html );
		self::assertStringNotContainsString( 'id="fxc-login"', $html );

		// A customer who is not the owner sees the same guest view.
		wp_set_current_user( self::factory()->user->create() );
		self::assertStringContainsString( 'Packed and on its way.', AccountPage::instance()->render() );
		self::assertStringNotContainsString( 'fxc-account__nav', AccountPage::instance()->render() );

		// The owner gets the account page with the navigation.
		Plugin::instance()->orders()->orders()->update( $this->order->id, [ 'customer_id' => get_current_user_id() ] );
		self::assertStringContainsString( 'fxc-account__nav', AccountPage::instance()->render() );
	}

	public function test_a_wrong_key_shows_not_found_and_counts(): void {
		$_GET = [
			'fxc_view_order' => (string) $this->order->id,
			'key'            => 'wrong',
		];
		$html = AccountPage::instance()->render();
		self::assertStringContainsString( 'Order not found.', $html );
		self::assertStringNotContainsString( esc_html( $this->order->order_number ) . '</strong>', $html );
		self::assertSame( 1, ( new RateLimiter() )->attempts( 'order_key_ip', '203.0.113.9' ) );

		// Without a key a guest gets the sign-in form and the lookup.
		$_GET = [ 'fxc_view_order' => (string) $this->order->id ];
		self::assertStringContainsString( 'id="fxc-login"', AccountPage::instance()->render() );
	}

	private function lookup( string $number, string $email ): ?string {
		$_POST = [
			OrderLookup::ACTION => '1',
			OrderLookup::NUMBER => $number,
			OrderLookup::EMAIL  => $email,
		];
		( new \ReflectionProperty( OrderLookup::class, 'instance' ) )->setValue( null, null );
		try {
			OrderLookup::instance()->handle();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return null;
	}
}
