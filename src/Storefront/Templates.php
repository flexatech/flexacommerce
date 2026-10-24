<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Block markup of the plugin templates for block themes (doc 02 §6.3).
 * They use the theme's `header` and `footer` parts and core blocks around
 * the FlexaCommerce ones, so a theme or the Site Editor can replace any
 * piece. Phase 5 adds the gallery and the remaining product blocks.
 */
final class Templates {
	public static function single(): string {
		return self::wrap(
			'<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:post-featured-image /--></div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:post-title {"level":1} /-->'
			. '<!-- wp:flexacommerce/product-price /-->'
			. '<!-- wp:post-excerpt /-->'
			. '<!-- wp:flexacommerce/product-add-to-cart /-->'
			. '<!-- wp:post-terms {"term":"' . PostTypes::CATEGORY . '"} /-->'
			. '</div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->'
			. '<!-- wp:post-content {"align":"wide","layout":{"type":"constrained"}} /-->'
		);
	}

	public static function archive(): string {
		return self::wrap(
			'<!-- wp:query-title {"type":"archive","showPrefix":false,"align":"wide"} /-->'
			. '<!-- wp:term-description {"align":"wide"} /-->'
			. '<!-- wp:query {"queryId":1,"query":{"inherit":true,"perPage":12,"postType":"' . PostTypes::PRODUCT . '"},"align":"wide"} -->'
			. '<div class="wp-block-query alignwide">'
			. '<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->'
			. '<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"1"} /-->'
			. '<!-- wp:post-title {"isLink":true,"level":2,"fontSize":"medium"} /-->'
			. '<!-- wp:flexacommerce/product-price /-->'
			. '<!-- wp:flexacommerce/product-add-to-cart /-->'
			. '<!-- /wp:post-template -->'
			. '<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->'
			. '<!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /-->'
			. '<!-- /wp:query-pagination -->'
			. '<!-- wp:query-no-results --><!-- wp:paragraph --><p>' . esc_html__( 'No products found.', 'flexacommerce' ) . '</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
			. '</div><!-- /wp:query -->'
		);
	}

	private static function wrap( string $main ): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
			. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
			. $main
			. '</main><!-- /wp:group -->'
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}
}
