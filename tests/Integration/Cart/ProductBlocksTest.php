<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Storefront\Storefront;
use Flexa\Commerce\Support\Settings;

/**
 * The product blocks of phase 5: SKU, stock, details table, gallery,
 * breadcrumbs, related products, and the block supports wrapper.
 */
final class ProductBlocksTest extends CartTestCase {
	public function tear_down(): void {
		Settings::update(
			[
				'weight_unit'    => 'kg',
				'dimension_unit' => 'cm',
			]
		);
		parent::tear_down();
	}

	public function test_sku_of_a_simple_product_and_of_one_with_options(): void {
		$id = $this->simple();
		self::assertSame( '', $this->block( 'product-sku', $id ) );

		$sku = $this->sku( 'TEE' );
		$this->save( $id, [ 'base' => [ 'sku' => $sku ] ] );
		$html = $this->block( 'product-sku', $id );
		self::assertStringContainsString( 'class="fxc-sku', $html );
		self::assertStringContainsString( esc_html( $sku ), $html );
		self::assertStringNotContainsString( 'data-wp-interactive', $html );

		[ $variable ] = $this->sized();
		$html         = $this->block( 'product-sku', $variable );
		self::assertStringContainsString( 'data-wp-interactive="flexacommerce/cart"', $html );
		self::assertStringContainsString( 'data-wp-text="state.sku"', $html );
		self::assertStringContainsString( ' hidden', $html, 'no product SKU: hidden until an option has one' );
	}

	public function test_stock_lines(): void {
		self::assertStringContainsString( '3 in stock', $this->block( 'product-stock', $this->simple( 1000, 3 ) ) );
		self::assertStringContainsString( 'In stock', $this->block( 'product-stock', $this->simple() ) );
		$out = $this->block( 'product-stock', $this->simple( 1000, 0 ) );
		self::assertStringContainsString( 'Out of stock', $out );
		self::assertStringContainsString( 'fxc-stock--outofstock', $out );

		[ $variable ] = $this->sized();
		self::assertSame( '', $this->block( 'product-stock', $variable ), 'the add-to-cart form shows the option stock' );
	}

	public function test_the_details_table_shows_stored_grams_and_millimetres_in_the_store_units(): void {
		$id = $this->simple();
		$this->save(
			$id,
			[
				'base'       => [
					'weight' => '1500',
					'length' => '100',
					'width'  => '200',
					'height' => '55',
				],
				'attributes' => [
					[
						'type'   => 'local',
						'name'   => 'Material',
						'values' => [ 'Cotton', 'Linen' ],
					],
					[
						'type'    => 'local',
						'name'    => 'Secret',
						'values'  => [ 'Hidden' ],
						'visible' => false,
					],
				],
			]
		);
		$html = $this->block( 'product-attributes', $id );
		self::assertStringContainsString( '<th scope="row">Weight</th><td>1.5 kg</td>', $html );
		self::assertStringContainsString( '<td>10 × 20 × 5.5 cm</td>', $html );
		self::assertStringContainsString( '<th scope="row">Material</th><td>Cotton, Linen</td>', $html );
		self::assertStringNotContainsString( 'Secret', $html );

		Settings::update(
			[
				'weight_unit'    => 'g',
				'dimension_unit' => 'mm',
			]
		);
		$html = $this->block( 'product-attributes', $id );
		self::assertStringContainsString( '<td>1,500 g</td>', $html );
		self::assertStringContainsString( '<td>100 × 200 × 55 mm</td>', $html );
	}

	public function test_global_attribute_values_follow_the_stored_order(): void {
		[ $color, $values ] = $this->attribute( 'Color', 'Red', 'Blue' );
		$id                 = $this->simple();
		$this->save(
			$id,
			[
				'attributes' => [
					[
						'type'              => 'global',
						'attribute_term_id' => $color,
						'value_term_ids'    => [ $values[1], $values[0] ],
					],
				],
			]
		);
		self::assertStringContainsString( '<td>Blue, Red</td>', $this->block( 'product-attributes', $id ) );
	}

	public function test_cart_lines_keep_the_stored_grams_and_millimetres(): void {
		$id = $this->simple();
		$this->save(
			$id,
			[
				'base' => [
					'weight' => '250.4',
					'length' => '300',
					'width'  => '200',
					'height' => '10',
				],
			]
		);
		Settings::update( [ 'weight_unit' => 'lb' ] );
		$item = $this->browser()->cart()->add( 0, $id, 0, 1 )->items[0];
		self::assertSame( 250, $item->weight_g() );
		self::assertSame( [ 300, 200, 10 ], $item->dimensions_mm() );
	}

	public function test_gallery_markup(): void {
		$id    = $this->simple();
		$first = $this->image( $id );
		self::assertStringNotContainsString( 'data-wp-interactive', $this->block( 'product-gallery', $id ), 'one image needs no script' );

		$second = $this->image( $id, false );
		$this->save( $id, [ 'gallery_ids' => [ $second ] ] );
		$html = $this->block( 'product-gallery', $id );
		self::assertStringContainsString( 'data-wp-interactive="flexacommerce/cart"', $html );
		self::assertStringContainsString( 'fetchpriority="high"', $html );
		self::assertSame( 2, substr_count( $html, 'data-wp-on--click="actions.showImage"' ) );
		self::assertSame( 1, substr_count( $html, 'aria-current="true"' ) );
		self::assertStringContainsString( 'data-image="' . $first . '"', $html );
		self::assertStringContainsString( 'Show image 2 of 2', $html );
	}

	public function test_breadcrumbs_of_a_product_and_the_filter(): void {
		$parent = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Clothing',
			]
		);
		$child  = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Shirts',
				'parent'   => $parent,
			]
		);
		$id     = $this->simple();
		wp_set_object_terms( $id, [ $child ], PostTypes::CATEGORY );
		$this->go_to( (string) get_permalink( $id ) );

		$html = do_blocks( '<!-- wp:flexacommerce/breadcrumbs /-->' );
		self::assertStringContainsString( '<nav class="fxc-breadcrumbs', $html );
		self::assertStringContainsString( 'aria-label="Breadcrumb"', $html );
		self::assertMatchesRegularExpression( '#Home</a></li><li><a[^>]*>Shop</a></li><li><a[^>]*>Clothing</a></li><li><a[^>]*>Shirts</a></li><li><span aria-current="page">Test product</span>#', $html );

		$filter = static fn ( array $crumbs ): array => array_merge( $crumbs, [ 'junk', [ 'Extra', '' ] ] );
		add_filter( 'flexacommerce.breadcrumbs', $filter );
		$html = do_blocks( '<!-- wp:flexacommerce/breadcrumbs /-->' );
		remove_filter( 'flexacommerce.breadcrumbs', $filter );
		self::assertStringContainsString( '<span aria-current="page">Extra</span>', $html );
		self::assertStringNotContainsString( 'junk', $html );
	}

	public function test_related_products_share_a_term_and_skip_hidden_ones(): void {
		$term    = self::factory()->term->create( [ 'taxonomy' => PostTypes::CATEGORY ] );
		$current = $this->simple();
		$shown   = $this->simple( 1500 );
		$hidden  = $this->simple();
		$other   = $this->simple();
		wp_update_post(
			[
				'ID'         => $shown,
				'post_title' => 'Shown neighbour',
			]
		);
		wp_update_post(
			[
				'ID'         => $hidden,
				'post_title' => 'Hidden neighbour',
			]
		);
		foreach ( [ $current, $shown, $hidden ] as $id ) {
			wp_set_object_terms( $id, [ $term ], PostTypes::CATEGORY );
		}
		$this->save( $hidden, [ 'visibility' => 'hidden' ] );

		$html = $this->block( 'related-products', $current );
		self::assertStringContainsString( 'aria-labelledby="fxc-related-' . $current . '"', $html );
		self::assertStringContainsString( 'Shown neighbour', $html );
		self::assertStringContainsString( '15.00', $html );
		self::assertStringNotContainsString( 'Hidden neighbour', $html );
		self::assertStringNotContainsString( 'post-' . $other, $html );

		// A product with no terms has no related products.
		self::assertSame( '', $this->block( 'related-products', $other ) );
	}

	public function test_block_supports_reach_the_markup(): void {
		$id   = $this->simple();
		$html = do_blocks( '<!-- wp:flexacommerce/product-price {"productId":' . $id . ',"className":"is-big"} /-->' );
		self::assertStringStartsWith( '<p class="fxc-price ', $html );
		$tags = new \WP_HTML_Tag_Processor( $html );
		$tags->next_tag();
		self::assertTrue( (bool) $tags->has_class( 'wp-block-flexacommerce-product-price' ) );
		self::assertTrue( (bool) $tags->has_class( 'is-big' ) );

		$html = do_blocks( '<!-- wp:flexacommerce/related-products {"align":"wide"} /-->' );
		self::assertSame( '', $html, 'no product in context: nothing to wrap' );

		$term  = self::factory()->term->create( [ 'taxonomy' => PostTypes::CATEGORY ] );
		$other = $this->simple();
		wp_set_object_terms( $id, [ $term ], PostTypes::CATEGORY );
		wp_set_object_terms( $other, [ $term ], PostTypes::CATEGORY );
		$html = do_blocks( '<!-- wp:flexacommerce/related-products {"productId":' . $id . ',"align":"wide"} /-->' );
		self::assertStringStartsWith( '<section class="fxc-related ', $html );
		self::assertStringContainsString( 'alignwide', $html );
	}

	public function test_every_block_registers(): void {
		do_blocks( '<!-- wp:flexacommerce/breadcrumbs /-->' );
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( Storefront::BLOCKS as $block ) {
			self::assertTrue( $registry->is_registered( 'flexacommerce/' . $block ), $block );
		}
	}

	private function block( string $name, int $product_id ): string {
		return do_blocks( '<!-- wp:flexacommerce/' . $name . ' {"productId":' . $product_id . '} /-->' );
	}

	private function image( int $product_id, bool $featured = true ): int {
		$file = DIR_TESTDATA . '/images/canola.jpg';
		$id   = self::factory()->attachment->create_upload_object( $file, $product_id );
		$this->track_post( $id );
		if ( $featured ) {
			set_post_thumbnail( $product_id, $id );
		}
		return $id;
	}
}
