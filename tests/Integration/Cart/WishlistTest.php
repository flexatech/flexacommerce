<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Privacy\Privacy;
use Flexa\Commerce\Storefront\AccountPage;
use Flexa\Commerce\Storefront\FormHandler;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Wishlist\WishlistCookies;
use Flexa\Commerce\Wishlist\WishlistRefused;
use Flexa\Commerce\Wishlist\WishlistRepository;
use Flexa\Commerce\Wishlist\WishlistService;

/**
 * Wishlists (ADR-066): saving and removing, the guest cookie and the move
 * into the account on sign-in, the share link, the daily purge, the store
 * routes, the no-JS forms, the account page and privacy. Each fresh()
 * call is a new request: new services, the cookies the browser kept.
 */
final class WishlistTest extends CartTestCase {
	private const IP = '203.0.113.21';

	private WishlistCookies $cookies;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$_SERVER['REMOTE_ADDR'] = self::IP;
		unset( $_COOKIE[ WishlistCookies::TOKEN ], $_COOKIE[ WishlistCookies::IDS ] );
		$this->fresh();
	}

	public function tear_down(): void {
		global $wpdb;
		( new RateLimiter() )->reset( WishlistService::LIMIT, self::IP );
		unset( $_COOKIE[ WishlistCookies::TOKEN ], $_COOKIE[ WishlistCookies::IDS ], $_GET['fxcm_wishlist'] );
		$_POST = [];
		unset( $_SERVER['HTTP_REFERER'] );
		( new \ReflectionProperty( Plugin::class, 'wishlist' ) )->setValue( Plugin::instance(), null );
		parent::tear_down();
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'wishlist_items' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'wishlists' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_a_customer_saves_and_removes_products(): void {
		$user  = self::factory()->user->create();
		$mug   = $this->simple();
		$cup   = $this->simple();
		$heard = [];
		add_action(
			'flexacommerce.wishlist.added',
			static function ( int $product, int $by ) use ( &$heard ): void {
				$heard[] = [ $product, $by ];
			},
			10,
			2
		);

		self::assertSame( [], $this->wishlist()->ids( $user ) );
		self::assertNull( $this->wishlist()->repository()->for_user( $user ), 'Reading makes no list.' );
		self::assertSame( [ $mug ], $this->wishlist()->add( $user, $mug ) );
		self::assertSame( [ $cup, $mug ], $this->wishlist()->add( $user, $cup ), 'The last saved comes first.' );
		self::assertSame( [ $cup, $mug ], $this->wishlist()->add( $user, $mug ), 'Saving twice keeps one row.' );
		self::assertSame( [ [ $mug, $user ], [ $cup, $user ] ], $heard );
		self::assertSame( $user . ':' . $cup . '.' . $mug, $_COOKIE[ WishlistCookies::IDS ] );
		self::assertNull( $this->cookies->token(), 'A customer needs no token.' );

		self::assertSame( [ $cup ], $this->wishlist()->remove( $user, $mug ) );
		self::assertSame( [ $cup ], $this->wishlist()->remove( $user, $mug ) );
		self::assertSame( $user . ':' . $cup, $_COOKIE[ WishlistCookies::IDS ] );
	}

	public function test_saving_follows_the_setting_the_product_and_the_cap(): void {
		$user  = self::factory()->user->create();
		$draft = $this->simple();
		wp_update_post(
			[
				'ID'          => $draft,
				'post_status' => 'draft',
			]
		);
		$this->assert_refused( 'product', fn () => $this->wishlist()->add( $user, $draft ) );
		$this->assert_refused( 'product', fn () => $this->wishlist()->add( $user, 999999 ) );

		$mug  = $this->simple();
		$list = $this->wishlist()->repository()->create( $user, null, gmdate( 'Y-m-d H:i:s' ) );
		for ( $i = 1; $i <= WishlistService::MAX_ITEMS; $i++ ) {
			$this->wishlist()->repository()->add( $list->id, 100000 + $i, gmdate( 'Y-m-d H:i:s' ) );
		}
		$this->assert_refused( 'full', fn () => $this->wishlist()->add( $user, $mug ) );

		Settings::update( [ 'wishlist' => 'off' ] );
		self::assertFalse( WishlistService::enabled() );
		$this->assert_refused( 'off', fn () => $this->wishlist()->add( $user, $mug ) );
	}

	public function test_a_guest_list_lives_in_the_cookie_and_moves_into_the_account(): void {
		$mug = $this->simple();
		$cup = $this->simple();
		$hat = $this->simple();
		self::assertSame( [ $mug ], $this->wishlist()->add( 0, $mug ) );
		$token = $this->cookies->token();
		self::assertIsString( $token );
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $token );
		self::assertSame( '0:' . $mug, $_COOKIE[ WishlistCookies::IDS ] );
		self::assertNotNull( $this->wishlist()->repository()->for_token( hash( 'sha256', $token ) ), 'Only the hash is stored.' );
		self::assertNull( $this->wishlist()->repository()->for_token( $token ) );

		// The next request of the same browser finds the list.
		$_COOKIE[ WishlistCookies::TOKEN ] = $token;
		$this->fresh();
		self::assertSame( [ $cup, $mug ], $this->wishlist()->add( 0, $cup ) );

		// Another browser starts its own.
		unset( $_COOKIE[ WishlistCookies::TOKEN ] );
		$this->fresh();
		self::assertSame( [], $this->wishlist()->ids( 0 ) );

		// Signing in moves the guest list into the account, joined with what the account had.
		$user = self::factory()->user->create();
		$this->wishlist()->add( $user, $hat );
		$this->wishlist()->add( $user, $mug );
		$_COOKIE[ WishlistCookies::TOKEN ] = $token;
		$this->fresh();
		self::assertEqualsCanonicalizing( [ $mug, $hat, $cup ], $this->wishlist()->ids( $user ) );
		self::assertNull( $this->cookies->token(), 'The guest token is cleared.' );
		self::assertNull( $this->wishlist()->repository()->for_token( hash( 'sha256', $token ) ), 'The guest list is gone.' );
		self::assertStringStartsWith( $user . ':', $_COOKIE[ WishlistCookies::IDS ] );

		// A guest list of a customer with no list becomes theirs.
		unset( $_COOKIE[ WishlistCookies::TOKEN ] );
		$this->fresh();
		$this->wishlist()->add( 0, $hat );
		$_COOKIE[ WishlistCookies::TOKEN ] = (string) $this->cookies->token();
		$this->fresh();
		$other = self::factory()->user->create();
		self::assertSame( [ $hat ], $this->wishlist()->ids( $other ) );
		self::assertNotNull( $this->wishlist()->repository()->for_user( $other ) );
	}

	public function test_new_guest_lists_are_rate_limited_per_ip(): void {
		$mug = $this->simple();
		for ( $i = 0; $i < 20; $i++ ) {
			$this->fresh();
			$this->wishlist()->add( 0, $mug );
		}
		$this->fresh();
		$this->assert_refused( 'limit', fn () => $this->wishlist()->add( 0, $mug ) );
		$user = self::factory()->user->create();
		self::assertSame( [ $mug ], $this->wishlist()->add( $user, $mug ), 'A customer is not limited.' );
	}

	public function test_the_share_link_shows_the_products_only(): void {
		$user = self::factory()->user->create( [ 'display_name' => 'Ada Lovelace' ] );
		$mug  = $this->simple();
		$this->wishlist()->add( $user, $mug );
		$list = $this->wishlist()->repository()->for_user( $user );
		self::assertNotNull( $list );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $list->share_key );
		self::assertSame( $list->id, $this->wishlist()->shared( $list->share_key )?->id );
		self::assertNull( $this->wishlist()->shared( 'nope' ) );
		self::assertNull( $this->wishlist()->shared( str_repeat( 'a', 32 ) ) );

		$_GET['fxcm_wishlist'] = $list->share_key;
		$html                  = AccountPage::instance()->render();
		self::assertStringContainsString( 'Shared wishlist', $html );
		self::assertStringContainsString( get_the_title( $mug ), $html );
		self::assertStringNotContainsString( 'Ada Lovelace', $html );
		self::assertStringNotContainsString( 'wishlist_remove', $html, 'A visitor cannot change it.' );

		$_GET['fxcm_wishlist'] = str_repeat( 'b', 32 );
		self::assertStringContainsString( 'This wishlist link does not work any more.', AccountPage::instance()->render() );
	}

	public function test_the_daily_run_drops_old_guest_lists_and_gone_products(): void {
		global $wpdb;
		$mug = $this->simple();
		$cup = $this->simple();
		$this->wishlist()->add( 0, $mug );
		$old = $this->wishlist()->repository()->for_token( hash( 'sha256', (string) $this->cookies->token() ) );
		self::assertNotNull( $old );
		$wpdb->update( Schema::table( 'wishlists' ), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ], [ 'id' => $old->id ] ); // phpcs:ignore WordPress.DB

		$this->fresh();
		$this->wishlist()->add( 0, $mug );
		$young = $this->wishlist()->repository()->for_token( hash( 'sha256', (string) $this->cookies->token() ) );
		self::assertNotNull( $young );

		$user = self::factory()->user->create();
		$this->wishlist()->add( $user, $mug );
		$this->wishlist()->add( $user, $cup );
		$wpdb->update( Schema::table( 'wishlists' ), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ], [ 'user_id' => $user ] ); // phpcs:ignore WordPress.DB
		$gone = self::factory()->user->create();
		$this->wishlist()->add( $gone, $mug );
		$lost = $this->wishlist()->repository()->for_user( $gone );
		self::assertNotNull( $lost );
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $gone );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $gone );
		}
		wp_delete_post( $cup, true );

		$this->wishlist()->daily();
		self::assertSame( [], $this->wishlist()->repository()->items( $old->id ), 'A guest list left 100 days goes.' );
		self::assertNull( $this->wishlist()->repository()->for_token( hash( 'sha256', 'x' ) ) );
		self::assertSame( [ $mug ], $this->wishlist()->repository()->items( $young->id ) );
		self::assertSame( [ $mug ], $this->wishlist()->ids( $user ), 'A customer list stays however old, without the deleted product.' );
		self::assertSame( [], $this->wishlist()->repository()->items( $lost->id ), 'The list of a deleted user goes.' );
		self::assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id IN (%d, %d)', Schema::table( 'wishlists' ), $old->id, $lost->id ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_the_store_routes_read_save_and_remove(): void {
		$mug  = $this->simple();
		$cup  = $this->simple();
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$empty = $this->send( 'GET', '/store/wishlist' );
		self::assertSame( 200, $empty->get_status() );
		self::assertSame( 0, $empty->get_data()['count'] );

		$saved = $this->send( 'POST', '/store/wishlist/items', [ 'product_id' => $mug ] );
		self::assertSame( 201, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		self::assertSame( [ $mug ], $saved->get_data()['items'] );
		self::assertSame( 'Saved to your wishlist.', $saved->get_data()['message'] );
		$this->send( 'POST', '/store/wishlist/items', [ 'product_id' => $cup ] );
		self::assertSame( [ $cup, $mug ], $this->send( 'GET', '/store/wishlist' )->get_data()['items'] );

		$bad = $this->send( 'POST', '/store/wishlist/items', [ 'product_id' => 999999 ] );
		self::assertSame( 400, $bad->get_status() );
		self::assertSame( 'flexacommerce_wishlist_product', $bad->as_error()->get_error_code() );

		$removed = $this->send( 'DELETE', '/store/wishlist/items/' . $mug );
		self::assertSame( 200, $removed->get_status() );
		self::assertSame( [ $cup ], $removed->get_data()['items'] );

		self::assertSame( 403, $this->send( 'POST', '/store/wishlist/items', [ 'product_id' => $mug ], 'wrong' )->get_status() );
		self::assertSame( [ $cup ], $this->send( 'GET', '/store/wishlist' )->get_data()['items'] );

		Settings::update( [ 'wishlist' => 'off' ] );
		self::assertSame( 'flexacommerce_wishlist_off', $this->send( 'POST', '/store/wishlist/items', [ 'product_id' => $mug ] )->as_error()->get_error_code() );
	}

	public function test_the_no_script_forms_save_and_remove(): void {
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new \RuntimeException( $location );
			}
		);
		$_SERVER['HTTP_REFERER'] = home_url( '/shop/mug/' );
		$user                    = self::factory()->user->create();
		wp_set_current_user( $user );
		$mug = $this->simple();

		self::assertSame( 'wishlist_added', $this->post( 'wishlist_add', $mug ) );
		self::assertSame( [ $mug ], $this->wishlist()->ids( $user ) );
		self::assertSame( 'wishlist_product', $this->post( 'wishlist_add', 999999 ) );
		self::assertSame( 'wishlist_removed', $this->post( 'wishlist_remove', $mug ) );
		self::assertSame( [], $this->wishlist()->ids( $user ) );

		$_GET[ FormHandler::RESULT ] = 'wishlist_added';
		$_GET['fxc-product']         = (string) $mug;
		self::assertStringContainsString( 'View wishlist', FormHandler::notice_for( $mug, FormHandler::SCOPE_WISHLIST ) );
		self::assertSame( '', FormHandler::notice_for( $mug ), 'The cart notice does not repeat it.' );
		$_GET = [];
	}

	public function test_the_buttons_and_the_account_page(): void {
		$mug  = $this->simple();
		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $mug . '"]' );
		self::assertStringContainsString( 'fxc-wishlist-button', $html );
		self::assertStringContainsString( 'name="fxcm_op" value="wishlist_add"', $html );
		self::assertStringContainsString( 'actions.toggleWishlist', $html );
		self::assertStringNotContainsString( 'aria-pressed="true"', $html, 'The markup is the same for every visitor.' );

		Settings::update( [ 'wishlist' => 'off' ] );
		self::assertStringNotContainsString( 'fxc-wishlist-button', do_shortcode( '[flexacommerce_add_to_cart id="' . $mug . '"]' ) );
		Settings::update( [ 'wishlist' => 'all' ] );

		// A guest sees their own list at the endpoint.
		$this->wishlist()->add( 0, $mug );
		$_GET['fxcm_wishlist'] = '1';
		$guest                 = AccountPage::instance()->render();
		self::assertStringContainsString( get_the_title( $mug ), $guest );
		self::assertStringContainsString( 'to keep your wishlist on every device', $guest );
		self::assertStringContainsString( 'wishlist_remove', $guest );

		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$_GET['fxcm_wishlist'] = '1';
		$own                   = AccountPage::instance()->render();
		self::assertStringContainsString( 'fxc-wishlist', $own );
		self::assertStringContainsString( get_the_title( $mug ), $own, 'The guest list came along.' );
		self::assertStringContainsString( 'fxc-account__nav', $own );
		$list = $this->wishlist()->repository()->for_user( $user );
		self::assertNotNull( $list );
		self::assertStringContainsString( $list->share_key, $own );
		self::assertStringContainsString( 'Wishlist', do_shortcode( '[flexacommerce_wishlist]' ) );
	}

	public function test_privacy_exports_and_erases_the_list(): void {
		Privacy::instance()->register();
		self::assertArrayHasKey( 'flexacommerce-wishlist', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		self::assertArrayHasKey( 'flexacommerce-wishlist', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
		$user  = self::factory()->user->create( [ 'user_email' => 'ada@example.com' ] );
		$other = self::factory()->user->create( [ 'user_email' => 'grace@example.com' ] );
		$mug   = $this->simple();
		$this->wishlist()->add( $user, $mug );
		$this->wishlist()->add( $other, $mug );

		$export = Privacy::instance()->export_wishlist( 'ada@example.com' );
		self::assertCount( 1, $export['data'] );
		self::assertSame( get_the_title( $mug ), $export['data'][0]['data'][0]['value'] );
		self::assertSame( [], Privacy::instance()->export_wishlist( 'nobody@example.com' )['data'] );

		self::assertTrue( Privacy::instance()->erase_wishlist( 'ada@example.com' )['items_removed'] );
		self::assertNull( $this->wishlist()->repository()->for_user( $user ) );
		self::assertFalse( Privacy::instance()->erase_wishlist( 'ada@example.com' )['items_removed'] );
		self::assertNotNull( $this->wishlist()->repository()->for_user( $other ) );
	}

	private function wishlist(): WishlistService {
		return Plugin::instance()->wishlist();
	}

	/** A new request: a new service whose cookies come from `$_COOKIE`. */
	private function fresh(): void {
		global $wpdb;
		$plugin        = Plugin::instance();
		$this->cookies = new WishlistCookies();
		$service       = new WishlistService(
			new WishlistRepository( $wpdb ),
			$plugin->catalog()->products(),
			$this->cookies,
			$plugin->services()->clock()
		);
		( new \ReflectionProperty( Plugin::class, 'wishlist' ) )->setValue( $plugin, $service );
	}

	private function assert_refused( string $reason, callable $run ): void {
		try {
			$run();
		} catch ( WishlistRefused $e ) {
			self::assertSame( $reason, $e->reason );
			return;
		}
		self::fail( "Expected the wishlist to refuse with {$reason}." );
	}

	private function post( string $operation, int $product ): string {
		$cart  = Plugin::instance()->cart();
		$_POST = [
			'action'           => FormHandler::ACTION,
			'fxcm_op'          => $operation,
			FormHandler::FIELD => $cart->nonce()->create( get_current_user_id(), wp_get_session_token(), $cart->sessions()->token() ),
			'product_id'       => (string) $product,
		];
		try {
			FormHandler::handle();
		} catch ( \RuntimeException $redirect ) {
			parse_str( (string) wp_parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $query );
			return (string) ( $query[ FormHandler::RESULT ] ?? '' );
		}
		self::fail( 'No redirect.' );
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null, ?string $nonce = null ): \WP_REST_Response {
		$cart    = Plugin::instance()->cart();
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_header( 'origin', home_url() );
		$request->set_header( 'x_fxc_nonce', $nonce ?? $cart->nonce()->create( get_current_user_id(), wp_get_session_token(), $cart->sessions()->token() ) );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
