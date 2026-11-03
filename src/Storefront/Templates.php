<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Support\ShopLayout;

defined( 'ABSPATH' ) || exit;

/**
 * Block markup of the plugin templates for block themes (doc 02 §6.3).
 * They use the theme's `header` and `footer` parts and core blocks around
 * the FlexaCommerce ones, so a theme or the Site Editor can replace any
 * piece.
 */
final class Templates {
	public static function single(): string {
		return self::wrap(
			'<!-- wp:flexacommerce/breadcrumbs {"align":"wide"} /-->'
			. '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:flexacommerce/product-gallery /--></div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:post-title {"level":1} /-->'
			. '<!-- wp:flexacommerce/product-rating /-->'
			. '<!-- wp:flexacommerce/product-price /-->'
			. '<!-- wp:post-excerpt /-->'
			. '<!-- wp:flexacommerce/product-add-to-cart /-->'
			. '<!-- wp:flexacommerce/product-sku /-->'
			. '<!-- wp:post-terms {"term":"' . PostTypes::CATEGORY . '"} /-->'
			. '</div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->'
			. '<!-- wp:post-content {"align":"wide","layout":{"type":"constrained"}} /-->'
			. '<!-- wp:flexacommerce/product-attributes {"align":"wide"} /-->'
			. '<!-- wp:flexacommerce/product-reviews {"align":"wide"} /-->'
			. '<!-- wp:flexacommerce/related-products {"align":"wide"} /-->'
		);
	}

	/**
	 * The shop, category and tag lists. With `shop_filters` set (ADR-084)
	 * the Product filters block sits in a column beside the grid, which
	 * then shows three products per row; on narrow screens the columns
	 * stack and the filters fold into a "Filters" toggle.
	 */
	public static function archive(): string {
		$side  = ShopLayout::side();
		$query = self::product_query( '{"queryId":1,"query":{"inherit":true,"perPage":' . ShopQuery::per_page() . ',"postType":"' . PostTypes::PRODUCT . '"},"align":"wide"}', $side === 'off' ? 4 : 3 );
		$head  = '<!-- wp:flexacommerce/breadcrumbs {"align":"wide"} /-->'
			. '<!-- wp:query-title {"type":"archive","showPrefix":false,"align":"wide"} /-->'
			. '<!-- wp:term-description {"align":"wide"} /-->';
		if ( $side === 'off' ) {
			return self::wrap( $head . '<!-- wp:flexacommerce/catalog-sorting {"align":"wide"} /-->' . $query );
		}
		$filters = '<!-- wp:column {"width":"25%","className":"fxc-shop__sidebar"} --><div class="wp-block-column fxc-shop__sidebar" style="flex-basis:25%">'
			. '<!-- wp:flexacommerce/product-filters {"collapsible":true} /-->'
			. '</div><!-- /wp:column -->';
		$grid    = '<!-- wp:column {"width":"75%"} --><div class="wp-block-column" style="flex-basis:75%">'
			. '<!-- wp:flexacommerce/catalog-sorting /-->'
			. $query
			. '</div><!-- /wp:column -->';
		return self::wrap(
			$head
			. '<!-- wp:columns {"align":"wide","className":"fxc-shop fxc-shop--filters-' . $side . '"} --><div class="wp-block-columns alignwide fxc-shop fxc-shop--filters-' . $side . '">'
			. ( $side === 'left' ? $filters . $grid : $grid . $filters )
			. '</div><!-- /wp:columns -->'
		);
	}

	/**
	 * The product grid: a Query Loop with image, title, price and button,
	 * pagination and a "no results" message. The Query Loop variation in
	 * the editor inserts the same inner blocks.
	 */
	public static function product_query( string $attributes, int $columns = 4 ): string {
		return '<!-- wp:query ' . $attributes . ' -->'
			. '<div class="wp-block-query alignwide">'
			. '<!-- wp:post-template {"layout":{"type":"grid","columnCount":' . $columns . '}} -->'
			. '<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1"} /-->'
			. '<!-- wp:post-title {"isLink":true,"level":2,"fontSize":"medium"} /-->'
			. '<!-- wp:flexacommerce/product-rating /-->'
			. '<!-- wp:flexacommerce/product-price /-->'
			. '<!-- wp:flexacommerce/product-add-to-cart /-->'
			. '<!-- /wp:post-template -->'
			. '<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->'
			. '<!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /-->'
			. '<!-- /wp:query-pagination -->'
			. '<!-- wp:query-no-results --><!-- wp:paragraph --><p>' . esc_html__( 'No products found.', 'flexacommerce' ) . '</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
			. '</div><!-- /wp:query -->';
	}

	private static function wrap( string $main ): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
			. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
			. $main
			. '</main><!-- /wp:group -->'
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}
}
