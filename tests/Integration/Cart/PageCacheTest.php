<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

/**
 * Page caches (doc 02 §7.3.1): the cart page turns caching off. PHPUnit has
 * sent output already, so nocache_headers() does nothing here; the
 * constant is what is left to check. That catalog pages send neither is
 * checked in a fresh process (P11 in StorefrontBudgetTest).
 */
final class PageCacheTest extends CartTestCase {
	private int $saved = 0;

	public function set_up(): void {
		parent::set_up();
		$this->saved = Settings::int( 'cart_page_id' );
	}

	public function tear_down(): void {
		Settings::update( [ 'cart_page_id' => $this->saved ] );
		parent::tear_down();
	}

	public function test_the_cart_page_is_not_cached(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Settings::update( [ 'cart_page_id' => $page ] );
		$this->go_to( (string) get_permalink( $page ) );
		Plugin::instance()->on_template_redirect();
		self::assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}
}
