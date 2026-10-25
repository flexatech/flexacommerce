<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cart\CartItem;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\NotPurchasable;
use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Inventory\StockLine;
use Flexa\Commerce\Plugin;

final class CartServiceTest extends CartTestCase {
	public function test_reading_an_empty_cart_writes_nothing(): void {
		$tokens = new MemoryTokenStore();
		$state  = $this->browser( $tokens )->cart()->state( 0 );
		self::assertTrue( $state->cart->is_empty() );
		self::assertFalse( $state->has_session );
		self::assertSame( 0, $tokens->writes );
		self::assertNull( $tokens->get() );
	}

	public function test_first_add_creates_the_session_and_the_count(): void {
		$id     = $this->simple( 1250 );
		$tokens = new MemoryTokenStore();
		$state  = $this->browser( $tokens )->cart()->add( 0, $id, 0, 2 );

		self::assertNotNull( $tokens->get() );
		self::assertSame( 2, $tokens->count );
		self::assertSame( 2500, $state->subtotal->amount );

		// A new request with the same cookie sees the same cart.
		$again = $this->browser( new MemoryTokenStore( $tokens->get() ) )->cart()->state( 0 );
		self::assertSame( 2, $again->item_count() );
		self::assertTrue( $again->has_session );
	}

	public function test_adding_again_merges_and_respects_stock(): void {
		$id   = $this->simple( 1000, 3 );
		$cart = $this->browser()->cart();
		$cart->add( 0, $id, 0, 2 );
		self::assertCount( 1, $cart->add( 0, $id, 0, 1 )->cart->lines );

		$this->expectException( InsufficientStock::class );
		$cart->add( 0, $id, 0, 1 );
	}

	public function test_variable_product_needs_a_choice(): void {
		[ $id, $sizes ] = $this->sized();
		$cart           = $this->browser()->cart();
		try {
			$cart->add( 0, $id, 0, 1 );
			self::fail( 'Expected an incomplete selection.' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'incomplete_selection', $e->reason );
		}

		$state = $cart->add( 0, $id, 0, 1, [ 'l:size' => 'm' ] );
		self::assertSame( $sizes['m'], $state->cart->lines[0]->variant_id );
		self::assertSame(
			[
				[
					'name'  => 'Size',
					'value' => 'M',
				],
			],
			$state->cart->lines[0]->labels
		);

		$this->expectException( InvalidCartRequest::class );
		$cart->add( 0, $id, 0, 1, [ 'l:size' => 'xxl' ] );
	}

	public function test_draft_and_unpriced_products_cannot_be_bought(): void {
		$draft = $this->simple();
		wp_update_post(
			[
				'ID'          => $draft,
				'post_status' => 'draft',
			]
		);
		$this->expectException( NotPurchasable::class );
		$this->browser()->cart()->add( 0, $draft, 0, 1 );
	}

	public function test_quantity_update_and_removal(): void {
		$id   = $this->simple( 1000, 10 );
		$cart = $this->browser()->cart();
		$key  = $cart->add( 0, $id, 0, 1 )->cart->lines[0]->key;
		self::assertSame( 4, $cart->set_quantity( 0, $key, 4 )->item_count() );
		self::assertSame( 0, $cart->set_quantity( 0, $key, 0 )->item_count() );

		$key = $cart->add( 0, $id, 0, 1 )->cart->lines[0]->key;
		self::assertTrue( $cart->remove( 0, $key )->cart->is_empty() );

		try {
			$cart->remove( 0, $key );
			self::fail( 'Expected unknown_line.' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'unknown_line', $e->reason );
		}
	}

	public function test_stock_sold_elsewhere_is_flagged_not_dropped(): void {
		$id      = $this->simple( 1000, 2 );
		$browser = $this->browser();
		$browser->cart()->add( 0, $id, 0, 2 );

		$product = $this->service()->get( $id );
		Plugin::instance()->cart()->stock()->deduct( [ new StockLine( $product, $product->base, 1 ) ], 'test' );

		$state = $this->browser( new MemoryTokenStore( (string) $browser->tokens()->get() ) )->cart()->state( 0 );
		self::assertCount( 1, $state->items );
		self::assertSame( CartItem::INSUFFICIENT_STOCK, $state->items[0]->status );
		self::assertTrue( $state->has_problems() );
		self::assertSame( CartItem::INSUFFICIENT_STOCK, $state->notices[0]['code'] );
	}

	public function test_guest_cart_survives_login_and_merges_once(): void {
		$a = $this->simple( 1000, 10 );
		$b = $this->simple( 500 );

		$user = self::factory()->user->create();
		// The user filled a cart on another device.
		$this->browser()->cart()->add( $user, $a, 0, 2 );

		// As a guest on this device.
		$tokens = new MemoryTokenStore();
		$this->browser( $tokens )->cart()->add( 0, $a, 0, 1 );
		$this->browser( $tokens )->cart()->add( 0, $b, 0, 3 );
		$guest_token = $tokens->get();

		// After login, the first read merges.
		$state = $this->browser( $tokens )->cart()->state( $user );
		self::assertSame( 6, $state->item_count() );
		self::assertCount( 2, $state->cart->lines );

		// The guest row is gone, the cookie now finds the user cart.
		self::assertSame( $guest_token, $tokens->get() );
		$again = $this->browser( new MemoryTokenStore( $tokens->get() ) )->cart()->state( $user );
		self::assertSame( 6, $again->item_count() );
		self::assertTrue( $this->browser( new MemoryTokenStore( $tokens->get() ) )->cart()->state( 0 )->cart->is_empty(), 'A logged-out visitor must not see the user cart.' );

		global $wpdb;
		self::assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Flexa\Commerce\Database\Schema::table( 'sessions' ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_guest_cart_becomes_the_user_cart_when_the_user_has_none(): void {
		$id     = $this->simple();
		$user   = self::factory()->user->create();
		$tokens = new MemoryTokenStore();
		$this->browser( $tokens )->cart()->add( 0, $id, 0, 2 );

		self::assertSame( 2, $this->browser( $tokens )->cart()->state( $user )->item_count() );
		self::assertSame( 2, $this->browser()->cart()->state( $user )->item_count(), 'Another device of the user sees the claimed cart.' );
	}

	public function test_merge_caps_quantities_by_stock(): void {
		$id   = $this->simple( 1000, 3 );
		$user = self::factory()->user->create();
		$this->browser()->cart()->add( $user, $id, 0, 2 );
		$tokens = new MemoryTokenStore();
		$this->browser( $tokens )->cart()->add( 0, $id, 0, 3 );

		$state = $this->browser( $tokens )->cart()->state( $user );
		self::assertSame( 3, $state->item_count() );
		self::assertSame( 'quantity_reduced', $state->notices[0]['code'] );
	}

	public function test_writes_are_rate_limited(): void {
		$id = $this->simple();
		add_filter( 'flexacommerce.cart.writes_per_minute', static fn (): int => 2 );
		$cart = $this->browser()->cart();
		$cart->add( 0, $id, 0, 1 );
		$cart->add( 0, $id, 0, 1 );
		$this->expectException( RateLimited::class );
		$cart->add( 0, $id, 0, 1 );
	}

	public function test_stale_version_is_retried_once(): void {
		$id     = $this->simple();
		$tokens = new MemoryTokenStore();
		$first  = $this->browser( $tokens );
		$first->cart()->add( 0, $id, 0, 1 );
		$first->cart()->state( 0 );

		// Another window writes in between; the first service still holds the old version.
		$this->browser( new MemoryTokenStore( $tokens->get() ) )->cart()->add( 0, $id, 0, 1 );
		self::assertSame( 3, $first->cart()->add( 0, $id, 0, 1 )->item_count() );
	}
}
