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

		$this->go_to(
			add_query_arg(
				[
					'post_type' => PostTypes::PRODUCT,
					'orderby'   => 'price',
				],
				home_url( '/' )
			)
		);
		$found = $this->found( $ids );
		self::assertCount( 4, $found );
		self::assertSame( [ $ids['cheap'], $ids['mid'], $ids['dear'] ], array_values( array_diff( $found, [ $ids['none'] ] ) ) );

		$this->go_to(
			add_query_arg(
				[
					'post_type' => PostTypes::PRODUCT,
					'orderby'   => 'price-desc',
				],
				home_url( '/' )
			)
		);
		$found = $this->found( $ids );
		self::assertSame( [ $ids['dear'], $ids['mid'], $ids['cheap'] ], array_values( array_diff( $found, [ $ids['none'] ] ) ) );

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

		$html = do_blocks( '<!-- wp:flexacommerce/product-filters {"showCategories":false,"attributeSlugs":["no-such-attribute"]} /-->' );
		self::assertSame( '', $html );
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
