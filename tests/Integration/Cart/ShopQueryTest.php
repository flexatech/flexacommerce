<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Storefront\ShopQuery;

/**
 * The shop's main query (doc 07 phase 5): visibility, page size, sorting,
 * filters, SKU search, and the sort and filter forms.
 */
final class ShopQueryTest extends CartTestCase {
	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	public function test_visibility_in_the_catalog_and_in_search(): void {
		$ids = [];
		foreach ( [ 'visible', 'catalog', 'search', 'hidden' ] as $visibility ) {
			$ids[ $visibility ] = $this->simple();
			wp_update_post(
				[
					'ID'         => $ids[ $visibility ],
					'post_title' => 'Zebra ' . $visibility,
				]
			);
			$this->save( $ids[ $visibility ], [ 'visibility' => $visibility ] );
		}

		$this->go_to( add_query_arg( 'post_type', PostTypes::PRODUCT, home_url( '/' ) ) );
		self::assertEqualsCanonicalizing( [ $ids['visible'], $ids['catalog'] ], $this->found( $ids ) );
		self::assertSame( 16, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );

		$this->go_to( add_query_arg( 's', 'Zebra', home_url( '/' ) ) );
		self::assertEqualsCanonicalizing( [ $ids['visible'], $ids['search'] ], $this->found( $ids ) );
	}

	public function test_the_page_size_filter(): void {
		$size = static fn (): int => 3;
		add_filter( 'flexacommerce.shop.per_page', $size );
		$this->go_to( add_query_arg( 'post_type', PostTypes::PRODUCT, home_url( '/' ) ) );
		remove_filter( 'flexacommerce.shop.per_page', $size );
		self::assertSame( 3, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
	}

	public function test_price_sorting_keeps_unpriced_products(): void {
		$ids = [
			'mid'   => $this->simple( 2000 ),
			'cheap' => $this->simple( 500 ),
			'dear'  => $this->simple( 9000 ),
			'none'  => $this->product(),
		];
		// Other meta of an unpriced product must not decide where it sorts.
		update_post_meta( $ids['none'], '_fxc_test_number', '99999' );

		$this->go_to(
			add_query_arg(
				[
					'post_type' => PostTypes::PRODUCT,
					'orderby'   => 'price',
				],
				home_url( '/' )
			)
		);
		self::assertSame( [ $ids['cheap'], $ids['mid'], $ids['dear'], $ids['none'] ], $this->found( $ids ) );

		$this->go_to(
			add_query_arg(
				[
					'post_type' => PostTypes::PRODUCT,
					'orderby'   => 'price-desc',
				],
				home_url( '/' )
			)
		);
		self::assertSame( [ $ids['dear'], $ids['mid'], $ids['cheap'], $ids['none'] ], $this->found( $ids ) );

		$this->go_to(
			add_query_arg(
				[
					'post_type' => PostTypes::PRODUCT,
					'orderby'   => 'nonsense',
				],
				home_url( '/' )
			)
		);
		self::assertSame( 'menu_order', ShopQuery::current_sort() );
	}

	public function test_category_and_attribute_filters(): void {
		$parent             = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'slug'     => 'clothing-' . strtolower( $this->run ),
			]
		);
		$child              = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'parent'   => $parent,
			]
		);
		[ $color, $values ] = $this->attribute( 'Color', 'Red', 'Blue' );
		$red                = (string) get_term( $values[0] )->slug;
		$attribute          = (string) get_term( $color )->slug;

		$in_child = $this->simple();
		$red_one  = $this->simple();
		$elsewise = $this->simple();
		wp_set_object_terms( $in_child, [ $child ], PostTypes::CATEGORY );
		wp_set_object_terms( $red_one, [ $child ], PostTypes::CATEGORY );
		$this->save(
			$red_one,
			[
				'attributes' => [
					[
						'type'              => 'global',
						'attribute_term_id' => $color,
						'value_term_ids'    => [ $values[0] ],
					],
				],
			]
		);
		$ids = [ $in_child, $red_one, $elsewise ];

		$shop = add_query_arg( 'post_type', PostTypes::PRODUCT, home_url( '/' ) );
		$this->go_to( add_query_arg( 'filter_cat', 'clothing-' . strtolower( $this->run ), $shop ) );
		self::assertEqualsCanonicalizing( [ $in_child, $red_one ], $this->found( $ids ), 'a parent category includes its children' );

		$this->go_to(
			add_query_arg(
				[
					'filter_cat'           => 'clothing-' . strtolower( $this->run ),
					'filter_' . $attribute => $red,
				],
				$shop
			)
		);
		self::assertSame( [ $red_one ], $this->found( $ids ) );
		self::assertSame( [ $red ], ShopQuery::request()[ $attribute ] );

		$this->go_to( add_query_arg( 'filter_' . $attribute, 'no-such-value', $shop ) );
		self::assertSame( [], $this->found( $ids ) );
	}

	public function test_an_exact_sku_finds_the_product(): void {
		$sku = $this->sku( 'QX9' );
		$id  = $this->simple();
		$this->save( $id, [ 'base' => [ 'sku' => $sku ] ] );
		[ $variable, $variants ] = $this->sized();
		$option                  = $this->sku( 'OPT' );
		$this->save(
			$variable,
			[
				'variations' => [
					[
						'id'  => array_values( $variants )[1],
						'sku' => $option,
					],
				],
			]
		);

		$this->go_to( add_query_arg( 's', $sku, home_url( '/' ) ) );
		self::assertSame( [ $id ], $this->found( [ $id, $variable ] ) );
		$this->go_to( add_query_arg( 's', $option, home_url( '/' ) ) );
		self::assertSame( [ $variable ], $this->found( [ $id, $variable ] ) );
		$this->go_to( add_query_arg( 's', substr( $sku, 0, 3 ), home_url( '/' ) ) );
		self::assertSame( [], $this->found( [ $id ] ), 'only an exact SKU matches' );
		self::assertFalse( has_filter( 'posts_search', [ ShopQuery::instance(), 'posts_search' ] ) );
	}

	public function test_query_loop_blocks_hide_hidden_products(): void {
		$vars = ShopQuery::instance()->loop_vars( [ 'post_type' => PostTypes::PRODUCT ] );
		self::assertSame( 'catalog', $vars[ ShopQuery::VISIBILITY_VAR ] );
		self::assertSame( [ 'post_type' => 'post' ], ShopQuery::instance()->loop_vars( [ 'post_type' => 'post' ] ) );

		$ids = [];
		foreach ( [ 'visible', 'catalog', 'search', 'hidden' ] as $visibility ) {
			$ids[ $visibility ] = $this->simple();
			$this->save( $ids[ $visibility ], [ 'visibility' => $visibility ] );
		}
		$query = new \WP_Query(
			$vars + [
				'post__in' => array_values( $ids ),
				'fields'   => 'ids',
			]
		);
		self::assertEqualsCanonicalizing( [ $ids['visible'], $ids['catalog'] ], array_map( 'intval', $query->posts ) );
	}

	/**
	 * The visibility condition is one NOT EXISTS: no postmeta join and no
	 * GROUP BY, so MySQL can stop at the page size (T3 2026-10-25).
	 */
	public function test_visibility_adds_no_join(): void {
		$this->simple();
		$query = new \WP_Query( ShopQuery::hide_in( [ 'post_type' => PostTypes::PRODUCT ], 'catalog' ) );
		self::assertStringContainsString( 'NOT EXISTS', $query->request );
		self::assertStringNotContainsString( 'JOIN', $query->request );
		self::assertStringNotContainsString( 'GROUP BY', $query->request );
		self::assertSame( '', ShopQuery::visibility_where( 'unknown' ) );
	}

	public function test_the_sort_form_keeps_the_filters(): void {
		$this->go_to(
			add_query_arg(
				[
					'post_type'  => PostTypes::PRODUCT,
					'orderby'    => 'price',
					'filter_cat' => 'shoes',
				],
				home_url( '/' )
			)
		);
		$html = do_blocks( '<!-- wp:flexacommerce/catalog-sorting /-->' );
		self::assertStringContainsString( '<form class="fxc-sorting', $html );
		self::assertMatchesRegularExpression( '#<label for="(fxc-orderby-\d+)">Sort products</label> <select name="orderby" id="\1">#', $html );
		self::assertStringContainsString( '<option value="price" selected=\'selected\'>', $html );
		self::assertStringContainsString( '<input type="hidden" name="filter_cat" value="shoes">', $html );
		self::assertStringNotContainsString( 'name="orderby" value=', $html );
	}

	public function test_the_filter_form(): void {
		$term               = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Hats ' . $this->run,
			]
		);
		[ $color, $values ] = $this->attribute( 'Color', 'Red' );
		$id                 = $this->simple();
		wp_set_object_terms( $id, [ $term ], PostTypes::CATEGORY );
		$this->save(
			$id,
			[
				'attributes' => [
					[
						'type'              => 'global',
						'attribute_term_id' => $color,
						'value_term_ids'    => $values,
					],
				],
			]
		);
		$slug = (string) get_term( $term )->slug;

		$this->go_to(
			add_query_arg(
				[
					'post_type'  => PostTypes::PRODUCT,
					'orderby'    => 'date',
					'filter_cat' => $slug,
				],
				home_url( '/' )
			)
		);
		$html = do_blocks( '<!-- wp:flexacommerce/product-filters {"attributeSlugs":["' . get_term( $color )->slug . '"]} /-->' );
		self::assertStringContainsString( 'aria-label="Filter products"', $html );
		self::assertStringContainsString( '<legend>Categories</legend>', $html );
		self::assertStringContainsString( 'name="filter_cat[]" value="' . $slug . '" checked=\'checked\'', $html );
		self::assertStringContainsString( 'name="filter_' . get_term( $color )->slug . '[]" value="' . get_term( $values[0] )->slug . '"', $html );
		self::assertStringContainsString( '<input type="hidden" name="orderby" value="date">', $html );
		self::assertStringContainsString( 'Clear filters', $html );

		$html = do_blocks( '<!-- wp:flexacommerce/product-filters {"showCategories":false,"showPrice":false,"showStock":false,"attributeSlugs":["no-such-attribute"]} /-->' );
		self::assertSame( '', $html );
	}

	public function test_price_and_stock_filters(): void {
		$cheap = $this->simple( 1000 );
		$mid   = $this->simple( 2500 );
		$dear  = $this->simple( 5000 );
		$gone  = $this->simple( 2500, 0 );
		$ids   = [ $cheap, $mid, $dear, $gone ];
		$shop  = add_query_arg( 'post_type', PostTypes::PRODUCT, home_url( '/' ) );

		$this->go_to( add_query_arg( 'min_price', '20', $shop ) );
		self::assertEqualsCanonicalizing( [ $mid, $dear, $gone ], $this->found( $ids ) );
		$this->go_to( add_query_arg( 'max_price', '25', $shop ) );
		self::assertEqualsCanonicalizing( [ $cheap, $mid, $gone ], $this->found( $ids ), 'the bound itself is in' );
		$this->go_to(
			add_query_arg(
				[
					'min_price' => '40,5',
					'max_price' => '',
				],
				$shop
			)
		);
		self::assertSame( [ $dear ], $this->found( $ids ), 'a decimal comma reads as a point' );
		$this->go_to(
			add_query_arg(
				[
					'min_price' => '30',
					'max_price' => '20',
				],
				$shop
			)
		);
		self::assertSame( [ 20.0, 30.0 ], ShopQuery::price(), 'swapped bounds are put right' );
		self::assertEqualsCanonicalizing( [ $mid, $gone ], $this->found( $ids ) );

		$this->go_to( add_query_arg( 'filter_stock_status', 'outofstock', $shop ) );
		self::assertSame( [ $gone ], $this->found( $ids ) );
		$this->go_to( add_query_arg( 'filter_stock_status', 'instock', $shop ) );
		self::assertEqualsCanonicalizing( [ $cheap, $mid, $dear ], $this->found( $ids ) );

		$this->go_to( add_query_arg( 'min_price', '-3', $shop ) );
		self::assertNull( ShopQuery::price(), 'a negative bound is ignored' );
		self::assertCount( 4, $this->found( $ids ) );
	}

	/**
	 * A value counts the products it would list with the other filters on;
	 * its own group's choice does not narrow it, and a category counts its
	 * children's products.
	 */
	public function test_filter_counts(): void {
		$parent             = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Tops ' . $this->run,
			]
		);
		$child              = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Shirts ' . $this->run,
				'parent'   => $parent,
			]
		);
		[ $color, $values ] = $this->attribute( 'Color', 'Red', 'Blue', 'Green' );
		$colored            = function ( int $value, int $price, int $category ) use ( $color ): int {
			$id = $this->simple( $price );
			wp_set_object_terms( $id, [ $category ], PostTypes::CATEGORY );
			$this->save(
				$id,
				[
					'attributes' => [
						[
							'type'              => 'global',
							'attribute_term_id' => $color,
							'value_term_ids'    => [ $value ],
						],
					],
				]
			);
			return $id;
		};
		$colored( $values[0], 1000, $child );
		$colored( $values[0], 3000, $parent );
		$colored( $values[1], 2000, $child );
		$slug  = (string) get_term( $color )->slug;
		$red   = (string) get_term( $values[0] )->slug;
		$block = '<!-- wp:flexacommerce/product-filters {"attributeSlugs":["' . $slug . '"]} /-->';

		$this->go_to( add_query_arg( 'post_type', PostTypes::PRODUCT, home_url( '/' ) ) );
		$html = do_blocks( $block );
		self::assertMatchesRegularExpression( '#value="' . $red . '"> Red <span class="fxc-filters__count">\(2\)</span>#', $html );
		self::assertStringContainsString( 'Blue <span class="fxc-filters__count">(1)</span>', $html );
		self::assertStringNotContainsString( 'Green', $html, 'a value no product has is left out' );
		self::assertStringContainsString( 'Tops ' . $this->run . ' <span class="fxc-filters__count">(3)</span>', $html, 'a parent counts its children' );
		self::assertStringContainsString( 'Shirts ' . $this->run . ' <span class="fxc-filters__count">(2)</span>', $html );
		self::assertStringContainsString( 'In stock <span class="fxc-filters__count">', $html );
		self::assertStringContainsString( 'name="min_price" min="0" step="any" inputmode="decimal" value="" placeholder="', $html );
		self::assertStringContainsString( 'data-wp-init="callbacks.filters"', $html );
		self::assertStringNotContainsString( 'fxc-filters__active', $html, 'nothing chosen, no chips' );

		$this->go_to(
			add_query_arg(
				[
					'post_type'       => PostTypes::PRODUCT,
					'filter_' . $slug => $red,
					'max_price'       => '25',
					'orderby'         => 'price',
				],
				home_url( '/' )
			)
		);
		$html = do_blocks( $block );
		self::assertMatchesRegularExpression( '#value="' . $red . '" checked=\'checked\'> Red <span class="fxc-filters__count">\(1\)</span>#', $html, 'the price narrows the colours' );
		self::assertStringContainsString( 'Blue <span class="fxc-filters__count">(1)</span>', $html, 'choosing Red does not narrow the colours' );
		self::assertStringContainsString( 'Shirts ' . $this->run . ' <span class="fxc-filters__count">(1)</span>', $html, 'categories count Red at that price' );
		self::assertStringContainsString( 'name="max_price" min="0" step="any" inputmode="decimal" value="25"', $html );
		self::assertStringContainsString( '<p class="fxc-filters__active-title">Active filters</p>', $html );
		self::assertMatchesRegularExpression( '#<a class="fxc-filters__chip" href="[^"]*\?orderby=price&\#038;max_price=25" aria-label="Remove filter Color [A-Z0-9]+: Red">Red#', $html );
		self::assertMatchesRegularExpression( '#<a class="fxc-filters__chip" href="[^"]*\?orderby=price&\#038;filter_' . $slug . '=' . $red . '" aria-label="Remove filter Price up to 25">#', $html );
		self::assertMatchesRegularExpression( '#<a class="fxc-filters__clear" href="[^"?]*\?orderby=price">Clear filters</a>#', $html );

		$sort = do_blocks( '<!-- wp:flexacommerce/catalog-sorting /-->' );
		self::assertStringContainsString( '<input type="hidden" name="max_price" value="25">', $sort, 'sorting keeps the price' );

		$plain = do_blocks( '<!-- wp:flexacommerce/product-filters {"showCounts":false,"attributeSlugs":["' . $slug . '"]} /-->' );
		self::assertStringNotContainsString( 'fxc-filters__count', $plain );
		self::assertStringNotContainsString( 'Green', $plain, 'without counts, unused values are still left out' );
		self::assertStringContainsString( 'Blue', $plain );
	}

	public function test_the_stored_price_range(): void {
		self::assertSame( [ 1050, null ], ShopQuery::stored_range( [ 10.5, null ] ) );
		self::assertSame( [ 1000, 2001 ], ShopQuery::stored_range( [ 10.0, 20.001 ] ) );
	}

	/**
	 * The main query's posts among `$ids`, in query order.
	 *
	 * @param array<array-key,int> $ids
	 * @return list<int>
	 */
	private function found( array $ids ): array {
		$posts = array_map( static fn ( \WP_Post $post ): int => $post->ID, $GLOBALS['wp_query']->posts );
		return array_values( array_intersect( $posts, $ids ) );
	}
}
