<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Customers;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Setup\Pages;
use Flexa\Commerce\Storefront\AccountPage;
use Flexa\Commerce\Storefront\CheckoutPage;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * The account page without JavaScript (doc 02 §6.3): sub-pages, the
 * owner check on one order, and the address and account forms. Redirects
 * end in exit, so they are turned into an exception carrying the location.
 */
final class AccountPageTest extends OrderTestCase {
	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		Pages::install();
		$this->user = self::factory()->user->create(
			[
				'user_email'   => 'ada-' . wp_generate_password( 6, false ) . '@example.com',
				'user_pass'    => 'old-secret',
				'display_name' => 'Ada',
			]
		);
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new \RuntimeException( $location );
			}
		);
		$_SERVER['HTTP_REFERER'] = home_url( '/my-account/' );
	}

	public function tear_down(): void {
		$_POST = [];
		$_GET  = [];
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ORIGIN'] );
		wp_set_current_user( 0 );
		// OrderTestCase commits, so the users outlive the rollback.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( get_users(
			[
				'fields'  => 'ids',
				'exclude' => [ 1 ],
			]
		) as $id ) {
			wp_delete_user( (int) $id );
		}
		parent::tear_down();
	}

	public function test_install_creates_the_account_page(): void {
		$page = Settings::int( 'account_page_id' );
		self::assertGreaterThan( 0, $page );
		self::assertStringContainsString( '<!-- wp:flexacommerce/account /-->', (string) get_post_field( 'post_content', $page ) );
	}

	public function test_install_keeps_an_existing_account_page(): void {
		$page = Settings::int( 'account_page_id' );
		Pages::install();
		self::assertSame( $page, Settings::int( 'account_page_id' ) );
		self::assertCount(
			1,
			get_posts(
				[
					'post_type'   => 'page',
					'name'        => 'my-account',
					'post_status' => 'any',
				]
			)
		);
	}

	public function test_the_order_confirmation_block_needs_the_order_key(): void {
		$order = $this->place( [ $this->simple( 1000, 5 ) => 1 ] );
		self::assertSame( '', do_shortcode( '[flexacommerce_order_confirmation]' ) );

		$_GET = [
			'order-received' => (string) $order->id,
			'key'            => $order->order_key,
		];
		$this->reset_checkout_page();
		$html = do_shortcode( '[flexacommerce_order_confirmation]' );
		self::assertStringContainsString( esc_html( $order->order_number ), $html );
		$block = do_blocks( '<!-- wp:flexacommerce/order-confirmation /-->' );
		self::assertStringContainsString( esc_html( $order->order_number ), $block );
		self::assertStringContainsString( 'wp-block-flexacommerce-order-confirmation', $block );

		$_GET['key'] = 'wrong';
		$this->reset_checkout_page();
		self::assertStringContainsString( 'Order not found', do_shortcode( '[flexacommerce_order_confirmation]' ) );
	}

	public function test_guests_get_the_sign_in_form(): void {
		$html = do_shortcode( '[flexacommerce_account]' );
		self::assertStringContainsString( 'id="fxc-login"', $html );
		self::assertStringContainsString( 'Lost your password?', $html );
		self::assertStringNotContainsString( 'fxc-account__nav', $html );
	}

	public function test_urls_follow_the_permalink_structure(): void {
		$this->set_permalink_structure( '/%postname%/' );
		// The test case resets the rewrite object; register the endpoints again.
		PostTypes::instance()->register_types();
		flush_rewrite_rules( false );
		self::assertSame( home_url( '/my-account/orders/2/' ), AccountPage::url( 'orders', '2' ) );
		self::assertSame( home_url( '/my-account/edit-address/' ), AccountPage::url( 'edit-address' ) );

		$rules = get_option( 'rewrite_rules' );
		self::assertIsArray( $rules );
		self::assertArrayHasKey( '(.?.+?)/view-order(/(.*))?/?$', $rules );

		$this->go_to( AccountPage::url( 'view-order', '7' ) );
		self::assertTrue( is_page( Settings::int( 'account_page_id' ) ) );
		self::assertSame( [ 'view-order', '7' ], AccountPage::current() );

		$this->set_permalink_structure( '' );
		self::assertStringContainsString( 'fxc_orders=2', AccountPage::url( 'orders', '2' ) );
	}

	public function test_orders_lists_only_the_customers_own_orders(): void {
		$product = $this->simple( 1000, 5 );
		$mine    = $this->owned( $this->place( [ $product => 1 ] ), $this->user );
		$other   = $this->owned( $this->place( [ $product => 2 ] ), self::factory()->user->create() );

		wp_set_current_user( $this->user );
		$_GET = [ 'fxc_orders' => '1' ];
		$html = AccountPage::instance()->render();

		self::assertStringContainsString( 'aria-current="page">Orders', $html );
		self::assertStringContainsString( esc_html( $mine->order_number ), $html );
		self::assertStringNotContainsString( '>' . esc_html( $other->order_number ) . '<', $html );
		self::assertStringContainsString( 'order-pay=' . $mine->id, $html, 'An unpaid pending order can be paid.' );
	}

	public function test_an_order_is_shown_to_its_owner_only(): void {
		$order = $this->owned( $this->place( [ $this->simple( 1000, 5 ) => 1 ] ), $this->user );
		$ctx   = new TransitionContext( 'admin', 1 );
		$this->orders()->add_note( $order->id, 'Packed and on its way.', true, $ctx );
		$this->orders()->add_note( $order->id, 'Customer called twice.', false, $ctx );

		wp_set_current_user( $this->user );
		$_GET = [ 'fxc_view_order' => (string) $order->id ];
		$html = AccountPage::instance()->render();
		self::assertStringContainsString( 'Order details', $html );
		self::assertStringContainsString( 'Packed and on its way.', $html );
		self::assertStringNotContainsString( 'Customer called twice.', $html );

		wp_set_current_user( self::factory()->user->create() );
		$html = AccountPage::instance()->render();
		self::assertStringContainsString( 'Order not found.', $html );
		self::assertStringNotContainsString( 'Order details', $html );
	}

	public function test_saving_an_address(): void {
		wp_set_current_user( $this->user );
		$_GET = [ 'fxc_edit_address' => 'billing' ];
		$html = AccountPage::instance()->render();
		self::assertStringContainsString( 'name="billing[first_name]"', $html );

		$fields   = [
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'address_1'  => '12 St James Square',
			'city'       => 'Sacramento',
			'state'      => 'CA',
			'postcode'   => '95814',
			'country'    => 'US',
			'email'      => 'ada@example.com',
		];
		$location = $this->post( 'address', [ 'billing' => $fields ] );
		self::assertNotNull( $location );
		self::assertStringContainsString( AccountPage::SAVED . '=address', $location );
		$saved = ( new CustomerProfile() )->address( $this->user, 'billing' );
		self::assertNotNull( $saved );
		self::assertSame( 'Sacramento', $saved->city );

		$_GET = [ 'fxc_edit_address' => '' ];
		self::assertStringContainsString( 'Sacramento', AccountPage::instance()->render() );
	}

	public function test_an_invalid_address_renders_errors_and_keeps_the_values(): void {
		wp_set_current_user( $this->user );
		$location = $this->post(
			'address',
			[
				'billing' => [
					'first_name' => 'Ada',
					'country'    => 'US',
				],
			],
			[ 'fxc_edit_address' => 'billing' ]
		);
		self::assertNull( $location );
		$html = AccountPage::instance()->render();
		self::assertStringContainsString( 'href="#fxc-billing-address_1"', $html );
		self::assertMatchesRegularExpression( '/id="fxc-billing-address_1"[^>]*aria-invalid="true"/', $html );
		self::assertStringContainsString( 'value="Ada"', $html );
		self::assertNull( ( new CustomerProfile() )->address( $this->user, 'billing' ) );
	}

	public function test_a_post_from_another_site_or_with_a_bad_nonce_changes_nothing(): void {
		wp_set_current_user( $this->user );
		$fields                 = [
			'account_first_name'   => 'Eve',
			'account_display_name' => 'Eve',
			'account_email'        => get_userdata( $this->user )->user_email,
		];
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
		self::assertNull( $this->post( 'details', $fields ) );
		unset( $_SERVER['HTTP_ORIGIN'] );
		self::assertNull( $this->post( 'details', [ AccountPage::NONCE => 'stale' ] + $fields ) );
		self::assertStringContainsString( 'Your session has expired', AccountPage::instance()->render() );
		self::assertSame( '', get_userdata( $this->user )->first_name );
	}

	public function test_changing_the_email_needs_the_current_password(): void {
		wp_set_current_user( $this->user );
		$fields = [
			'account_first_name'   => 'Ada',
			'account_last_name'    => 'Lovelace',
			'account_display_name' => 'Ada L.',
			'account_email'        => 'countess@example.com',
		];
		self::assertNull( $this->post( 'details', $fields ) );
		self::assertArrayHasKey( 'password_current', AccountPage::instance()->errors() );
		self::assertStringStartsWith( 'ada-', get_userdata( $this->user )->user_email );

		$location = $this->post( 'details', $fields + [ 'password_current' => 'old-secret' ] );
		self::assertNotNull( $location );
		self::assertStringContainsString( AccountPage::SAVED . '=account', $location );
		$user = get_userdata( $this->user );
		self::assertSame( 'countess@example.com', $user->user_email );
		self::assertSame( 'Ada L.', $user->display_name );
	}

	public function test_new_passwords_must_match(): void {
		wp_set_current_user( $this->user );
		$fields = [
			'account_display_name' => 'Ada',
			'account_email'        => get_userdata( $this->user )->user_email,
			'password_current'     => 'old-secret',
			'password_1'           => 'new-secret',
			'password_2'           => 'typo',
		];
		self::assertNull( $this->post( 'details', $fields ) );
		self::assertArrayHasKey( 'password_2', AccountPage::instance()->errors() );
		self::assertTrue( wp_check_password( 'old-secret', get_userdata( $this->user )->user_pass, $this->user ) );
	}

	private function reset_checkout_page(): void {
		( new \ReflectionProperty( CheckoutPage::class, 'instance' ) )->setValue( null, null );
	}

	private function owned( Order $order, int $user_id ): Order {
		Plugin::instance()->orders()->orders()->update( $order->id, [ 'customer_id' => $user_id ] );
		return $this->orders()->get( $order->id );
	}

	/**
	 * Posts an account form with a valid nonce unless one is given.
	 *
	 * @param array<string,mixed>  $fields
	 * @param array<string,string> $get
	 */
	private function post( string $action, array $fields, array $get = [] ): ?string {
		$_GET  = $get !== [] ? $get : [ $action === 'address' ? 'fxc_edit_address' : 'fxc_edit_account' => $action === 'address' ? 'billing' : '1' ];
		$_POST = $fields + [
			AccountPage::ACTION => $action,
			AccountPage::NONCE  => wp_create_nonce( AccountPage::ACTION . '_' . $action ),
		];
		( new \ReflectionProperty( AccountPage::class, 'instance' ) )->setValue( null, null );
		try {
			AccountPage::instance()->handle();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return null;
	}
}
