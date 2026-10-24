<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Storefront;

/**
 * Blocks are registered on demand (P1): the first top-level block whose tree
 * holds one of ours triggers it, even when ours is nested.
 */
final class StorefrontRenderTest extends CartTestCase {
	public function set_up(): void {
		parent::set_up();
		$this->unregister();
	}

	public function tear_down(): void {
		$this->unregister();
		parent::tear_down();
	}

	public function test_blocks_stay_unregistered_for_core_content(): void {
		do_blocks( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' );
		self::assertFalse( \WP_Block_Type_Registry::get_instance()->is_registered( 'flexacommerce/product-price' ) );
	}

	public function test_a_nested_block_registers_and_renders(): void {
		$id   = $this->simple( 1250 );
		$html = do_blocks(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:flexacommerce/product-price {"productId":' . $id . '} /-->'
			. '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->'
		);
		self::assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'flexacommerce/product-price' ) );
		self::assertStringContainsString( 'fxc-price', $html );
		self::assertStringContainsString( '12.50', $html );
	}

	public function test_add_to_cart_shortcode_and_cart_shortcode(): void {
		$id   = $this->simple( 900, 3 );
		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertStringContainsString( 'name="product_id" value="' . $id . '"', $html );
		self::assertStringContainsString( 'fxc_nonce', $html );
		self::assertStringContainsString( 'fxc-price', $html );

		self::assertSame( '', do_shortcode( '[flexacommerce_add_to_cart id="999999"]' ) );
		self::assertNotSame( '', do_shortcode( '[flexacommerce_cart]' ) );
	}

	public function test_drafts_render_nothing_for_visitors(): void {
		$id = $this->simple();
		wp_update_post(
			[
				'ID'          => $id,
				'post_status' => 'draft',
			]
		);
		self::assertSame( '', do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' ) );
	}

	public function test_block_templates_for_block_themes(): void {
		Storefront::instance()->register_templates();
		$registry = \WP_Block_Templates_Registry::get_instance();
		foreach ( [ 'single-fxc_product', 'archive-fxc_product' ] as $slug ) {
			self::assertTrue( $registry->is_registered( 'flexacommerce//' . $slug ), $slug );
		}
	}

	/** Back to the state of a fresh request: nothing registered, the gate in place. */
	private function unregister(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( Storefront::BLOCKS as $block ) {
			if ( $registry->is_registered( 'flexacommerce/' . $block ) ) {
				$registry->unregister( 'flexacommerce/' . $block );
			}
		}
		( new \ReflectionProperty( Plugin::class, 'blocks' ) )->setValue( Plugin::instance(), false );
		( new \ReflectionProperty( Storefront::class, 'blocks' ) )->setValue( Storefront::instance(), false );
		add_filter( 'pre_render_block', [ Plugin::instance(), 'on_pre_render_block' ], 10, 3 );
	}
}
