<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Coupons\RuleTargets;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Contracts\Exceptions\NotPurchasable;
use Flexa\Commerce\Plugin;

/**
 * Grouped and external products (ADR-062).
 */
final class GroupedProductTest extends CartTestCase {
	public function test_a_group_keeps_its_children_in_order_and_takes_their_prices(): void {
		$cheap = $this->simple( 500 );
		$dear  = $this->simple( 1500 );
		$group = $this->product( 'Pack' );
		$saved = $this->save(
			$group,
			[
				'type'     => 'grouped',
				'children' => [ $dear, $cheap, $dear ],
			]
		);
		self::assertTrue( $saved->is_grouped() );
		self::assertSame( [ $dear, $cheap ], $saved->children );
		self::assertSame( [], $saved->sellable_rows() );
		self::assertSame( [ '500', '1500' ], [ get_post_meta( $group, ProductRepository::META_PRICE_MIN, true ), get_post_meta( $group, ProductRepository::META_PRICE_MAX, true ) ] );
		self::assertSame( [ $group ], Plugin::instance()->catalog()->products()->groups_of( $cheap ) );

		// A child's new price reaches the group.
		$this->save( $cheap, [ 'base' => [ 'regular_price' => 300 ] ] );
		self::assertSame( '300', get_post_meta( $group, ProductRepository::META_PRICE_MIN, true ) );

		// The group is sold out only when every child is.
		$this->save(
			$cheap,
			[
				'base' => [
					'manage_stock'   => true,
					'stock_quantity' => 0,
				],
			]
		);
		self::assertSame( Variant::INSTOCK, get_post_meta( $group, ProductRepository::META_STOCK_STATUS, true ) );

		// A deleted child leaves the group.
		wp_delete_post( $dear, true );
		self::assertSame( [ $cheap ], $this->service()->get( $group )->children );
		self::assertSame( Variant::OUTOFSTOCK, get_post_meta( $group, ProductRepository::META_STOCK_STATUS, true ) );
	}

	public function test_a_group_refuses_itself_other_groups_and_unknown_products(): void {
		$child = $this->simple();
		$group = $this->product();
		$this->save(
			$group,
			[
				'type'     => 'grouped',
				'children' => [ $child ],
			]
		);
		$other = $this->product();
		foreach ( [ [ $other ], [ $group ], [ 999999 ] ] as $children ) {
			try {
				$this->save(
					$other,
					[
						'type'     => 'grouped',
						'children' => $children,
					]
				);
				self::fail( 'Expected a refused child: ' . (string) wp_json_encode( $children ) );
			} catch ( InvalidProduct $e ) {
				self::assertSame( 'children', $e->field );
			}
		}
		// A product listed in a group cannot become a group itself.
		try {
			$this->save(
				$child,
				[
					'type'     => 'grouped',
					'children' => [ $other ],
				]
			);
			self::fail( 'Expected a refused type.' );
		} catch ( InvalidProduct $e ) {
			self::assertSame( 'type', $e->field );
		}
	}

	public function test_adding_a_group_adds_each_quantity_or_nothing(): void {
		$tee   = $this->simple( 1000, 5 );
		$mug   = $this->simple( 400, 1 );
		$group = $this->product();
		$this->save(
			$group,
			[
				'type'     => 'grouped',
				'children' => [ $tee, $mug ],
			]
		);
		$cart  = $this->browser()->cart();
		$state = $cart->add_group(
			0,
			$group,
			[
				$tee => 2,
				$mug => 1,
			]
		);
		self::assertCount( 2, $state->cart->lines );
		self::assertSame( 2400, $state->subtotal->amount );

		// The mug is now all in the cart: nothing of this request is added.
		try {
			$cart->add_group(
				0,
				$group,
				[
					$tee => 1,
					$mug => 1,
				]
			);
			self::fail( 'Expected too little stock.' );
		} catch ( InsufficientStock ) {
			self::assertSame( 3, $cart->state( 0 )->item_count() );
		}

		$this->expectException( InvalidCartRequest::class );
		$cart->add_group( 0, $group, [] );
	}

	public function test_a_group_ignores_products_it_does_not_list(): void {
		$tee     = $this->simple();
		$outside = $this->simple();
		$group   = $this->product();
		$this->save(
			$group,
			[
				'type'     => 'grouped',
				'children' => [ $tee ],
			]
		);
		$this->expectException( InvalidCartRequest::class );
		$this->browser()->cart()->add_group( 0, $group, [ $outside => 1 ] );
	}

	public function test_an_external_product_links_out_and_never_enters_the_cart(): void {
		$id      = $this->product( 'Partner Lamp' );
		$product = $this->save(
			$id,
			[
				'type'        => 'external',
				'product_url' => 'https://partner.example/lamp',
				'button_text' => 'Buy at Partner',
				'base'        => [
					'regular_price'  => 8000,
					'manage_stock'   => true,
					'stock_quantity' => 3,
				],
			]
		);
		self::assertTrue( $product->is_external() );
		self::assertFalse( $product->base->manage_stock, 'An external product keeps no stock.' );
		self::assertSame( 8000, $product->base->regular_price );
		self::assertSame( Variant::INSTOCK, get_post_meta( $id, ProductRepository::META_STOCK_STATUS, true ) );

		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertStringContainsString( 'href="https://partner.example/lamp"', $html );
		self::assertStringContainsString( 'rel="nofollow sponsored noopener"', $html );
		self::assertStringContainsString( 'Buy at Partner', $html );
		self::assertStringContainsString( '80.00', $html );
		self::assertStringNotContainsString( 'name="quantity"', $html );

		$this->expectException( NotPurchasable::class );
		$this->browser()->cart()->add( 0, $id, 0, 1 );
	}

	public function test_an_external_product_needs_a_full_web_address(): void {
		$this->expectException( InvalidProduct::class );
		$this->save(
			$this->product(),
			[
				'type'        => 'external',
				'product_url' => 'javascript:alert(1)',
			]
		);
	}

	public function test_the_group_page_lists_each_product_with_a_quantity(): void {
		$tee       = $this->simple( 1000 );
		$sold_out  = $this->simple( 700, 0 );
		[ $sized ] = $this->sized();
		$lamp      = $this->product( 'Lamp' );
		$this->save(
			$lamp,
			[
				'type'        => 'external',
				'product_url' => 'https://partner.example/lamp',
			]
		);
		$group = $this->product( 'Pack' );
		$this->save(
			$group,
			[
				'type'     => 'grouped',
				'children' => [ $tee, $sold_out, $sized, $lamp ],
			]
		);

		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $group . '"]' );
		self::assertStringContainsString( 'fxc-group', $html );
		self::assertStringContainsString( 'name="quantities[' . $tee . ']"', $html );
		self::assertStringNotContainsString( 'name="quantities[' . $sold_out . ']"', $html );
		self::assertStringContainsString( 'Out of stock', $html );
		self::assertStringContainsString( 'Choose options', $html );
		self::assertStringContainsString( 'Buy product', $html );
		self::assertStringContainsString( 'value="add_group"', $html );
		self::assertStringContainsString( '10.00', $html );
	}

	public function test_searches_for_coupons_and_rules_leave_out_products_not_sold(): void {
		$tee   = $this->simple();
		$group = $this->product( 'Searchable pack ' . $this->run );
		$this->save(
			$group,
			[
				'type'     => 'grouped',
				'children' => [ $tee ],
			]
		);
		$catalog = Plugin::instance()->catalog();
		$found   = ( new RuleTargets( $catalog->products(), $catalog->variants() ) )->search_products( 'Searchable pack ' . $this->run );
		self::assertSame( [], $found );
	}
}
