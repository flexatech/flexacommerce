<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Setup;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Maintenance\Doctor;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Setup\Pages;
use Flexa\Commerce\Storefront\Templates;
use Flexa\Commerce\Support\Settings;

/** The store pages (ADR-083): status, create, restore and repair, the routes behind the Settings card and the Status checks. */
final class PagesTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Settings::update(
			[
				'cart_page_id'     => 0,
				'checkout_page_id' => 0,
				'account_page_id'  => 0,
				'terms_page_id'    => 0,
			]
		);
	}

	public function test_install_creates_the_required_pages_and_keeps_a_picked_one(): void {
		$mine = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'My basket',
				'post_content' => '[flexacommerce_cart]',
			]
		);
		Settings::update( [ 'cart_page_id' => $mine ] );

		Pages::install();

		self::assertSame( $mine, Settings::int( 'cart_page_id' ) );
		self::assertTrue( has_block( 'flexacommerce/checkout', Settings::int( 'checkout_page_id' ) ) );
		self::assertTrue( has_block( 'flexacommerce/account', Settings::int( 'account_page_id' ) ) );
		$rows = array_column( Pages::status(), null, 'key' );
		foreach ( Pages::REQUIRED as $key ) {
			self::assertSame( '', $rows[ $key ]['problem'], $key );
		}
		self::assertSame( 'missing', $rows['terms']['problem'] );
		self::assertNull( $rows['wishlist']['page'] );
		self::assertSame( 'My basket', $rows['cart']['page']['title'] );
		self::assertStringContainsString( 'post=' . $mine, $rows['cart']['page']['edit_url'] );
	}

	public function test_fix_restores_a_trashed_page_publishes_a_draft_and_adds_a_lost_block(): void {
		Pages::install();
		$cart     = Settings::int( 'cart_page_id' );
		$checkout = Settings::int( 'checkout_page_id' );
		$account  = Settings::int( 'account_page_id' );
		wp_trash_post( $cart );
		wp_update_post(
			[
				'ID'          => $checkout,
				'post_status' => 'draft',
			]
		);
		wp_update_post(
			[
				'ID'           => $account,
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);

		self::assertSame( 'trash', Pages::problem( 'cart' ) );
		self::assertSame( 'unpublished', Pages::problem( 'checkout' ) );
		self::assertSame( 'content', Pages::problem( 'account' ) );

		// Activation brings back only what is gone, not a draft or an edited page.
		Pages::install();
		self::assertSame( $cart, Settings::int( 'cart_page_id' ) );
		self::assertSame( 'publish', get_post_status( $cart ) );
		self::assertSame( 'unpublished', Pages::problem( 'checkout' ) );

		self::assertTrue( Pages::fix( 'checkout' ) );
		self::assertTrue( Pages::fix( 'account' ) );
		self::assertSame( '', Pages::problem( 'checkout' ) );
		self::assertSame( '', Pages::problem( 'account' ) );
		self::assertStringContainsString( '<p>Hello</p>', (string) get_post_field( 'post_content', $account ) );
		self::assertFalse( Pages::fix( 'account' ) );
	}

	public function test_optional_pages_are_found_by_their_shortcode_or_block(): void {
		$draft     = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:flexacommerce/order-lookup /-->',
			]
		);
		$published = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => 'Find your order: [flexacommerce_order_tracking]',
			]
		);
		self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => '[flexacommerce_wishlist]',
			]
		);

		self::assertSame( $published, Pages::find( 'tracking' ) );
		self::assertNotSame( $draft, Pages::find( 'tracking' ) );
		self::assertSame( 0, Pages::find( 'wishlist' ) );

		self::assertTrue( Pages::fix( 'wishlist' ) );
		$wishlist = Pages::find( 'wishlist' );
		self::assertGreaterThan( 0, $wishlist );
		self::assertSame( '', Pages::problem( 'wishlist' ) );
		self::assertFalse( Pages::assign( 'wishlist', $wishlist ) );
	}

	public function test_the_routes_list_fix_and_assign_pages(): void {
		$listed = $this->send( 'GET', '/admin/pages' );
		self::assertSame( 200, $listed->get_status() );
		self::assertSame( Pages::KEYS, array_column( $listed->get_data()['pages'], 'key' ) );

		$fixed = $this->send( 'POST', '/admin/pages/fix', [] );
		self::assertSame( Pages::REQUIRED, $fixed->get_data()['fixed'] );
		self::assertSame( [], $this->send( 'POST', '/admin/pages/fix', [] )->get_data()['fixed'] );

		$terms = $this->send( 'POST', '/admin/pages/fix', [ 'keys' => [ 'terms' ] ] );
		self::assertSame( [ 'terms' ], $terms->get_data()['fixed'] );
		self::assertGreaterThan( 0, Settings::int( 'terms_page_id' ) );
		self::assertSame( 400, $this->send( 'POST', '/admin/pages/fix', [ 'keys' => [ 'shipping' ] ] )->get_status() );

		$other = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Our terms',
			]
		);
		self::assertSame(
			200,
			$this->send(
				'POST',
				'/admin/pages/assign',
				[
					'key'     => 'terms',
					'page_id' => $other,
				]
			)->get_status()
		);
		self::assertSame( $other, Settings::int( 'terms_page_id' ) );
		self::assertContains( 'Our terms', array_column( $this->send( 'GET', '/admin/pages' )->get_data()['choices'], 'title' ) );

		self::assertSame(
			200,
			$this->send(
				'POST',
				'/admin/pages/assign',
				[
					'key'     => 'terms',
					'page_id' => 0,
				]
			)->get_status()
		);
		self::assertSame( 0, Settings::int( 'terms_page_id' ) );
		self::assertSame(
			400,
			$this->send(
				'POST',
				'/admin/pages/assign',
				[
					'key'     => 'cart',
					'page_id' => 0,
				]
			)->get_status()
		);
		$post = self::factory()->post->create();
		self::assertSame(
			400,
			$this->send(
				'POST',
				'/admin/pages/assign',
				[
					'key'     => 'cart',
					'page_id' => $post,
				]
			)->get_status()
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'GET', '/admin/pages' )->get_status() );
	}

	public function test_doctor_warns_about_a_broken_page(): void {
		Pages::install();
		wp_trash_post( Settings::int( 'checkout_page_id' ) );

		$results = array_column( ( new Doctor( Plugin::instance()->services()->integrity() ) )->run(), null, 'id' );

		self::assertSame( 'ok', $results['page_cart']['status'] );
		self::assertSame( 'warning', $results['page_checkout']['status'] );
		self::assertSame( 'The page is in the trash.', $results['page_checkout']['detail'] );
		self::assertArrayNotHasKey( 'page_wishlist', $results );
		self::assertArrayNotHasKey( 'page_terms', $results );
	}

	public function test_the_shop_template_puts_the_filters_beside_the_grid(): void {
		Settings::update( [ 'shop_filters' => 'off' ] );
		$plain = Templates::archive();
		self::assertStringNotContainsString( 'product-filters', $plain );
		self::assertStringContainsString( '"columnCount":4', $plain );

		Settings::update( [ 'shop_filters' => 'left' ] );
		$left = Templates::archive();
		self::assertStringContainsString( '<!-- wp:columns {"align":"wide","className":"fxc-shop fxc-shop--filters-left"} -->', $left );
		self::assertStringContainsString( '<!-- wp:flexacommerce/product-filters {"collapsible":true} /-->', $left );
		self::assertStringContainsString( '"columnCount":3', $left );
		self::assertLessThan( strpos( $left, 'wp:query ' ), strpos( $left, 'product-filters' ) );
		self::assertCount( 1, parse_blocks( $left )[1]['innerBlocks'][3]['innerBlocks'][0]['innerBlocks'] );

		Settings::update( [ 'shop_filters' => 'right' ] );
		$right = Templates::archive();
		self::assertGreaterThan( strpos( $right, 'wp:query ' ), strpos( $right, 'product-filters' ) );
		Settings::update( [ 'shop_filters' => 'off' ] );
	}

	public function test_the_shop_route_sets_the_filters_and_reports_a_page_in_the_way(): void {
		$this->set_permalink_structure( '/%postname%/' );
		Settings::update( [ 'shop_filters' => 'off' ] );
		$listed = $this->send( 'GET', '/admin/pages' )->get_data()['shop'];
		self::assertSame( 'off', $listed['filters'] );
		self::assertSame( (string) get_post_type_archive_link( PostTypes::PRODUCT ), $listed['view_url'] );
		self::assertNull( $listed['page'] );

		$set = $this->send( 'POST', '/admin/pages/shop', [ 'filters' => 'right' ] );
		self::assertSame( 200, $set->get_status() );
		self::assertSame( 'right', $set->get_data()['shop']['filters'] );
		self::assertSame( 'right', Settings::string( 'shop_filters' ) );
		self::assertSame( 400, $this->send( 'POST', '/admin/pages/shop', [ 'filters' => 'top' ] )->get_status() );

		$base = trim( (string) wp_parse_url( (string) get_post_type_archive_link( PostTypes::PRODUCT ), PHP_URL_PATH ), '/' );
		$page = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Our shop',
				'post_name'  => $base,
			]
		);
		self::assertSame( $page, Pages::shop()['page']['id'] ?? 0 );
		Settings::update( [ 'shop_filters' => 'off' ] );
		$this->set_permalink_structure( '' );
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( (object) $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
