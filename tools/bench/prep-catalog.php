<?php
/**
 * Gives both stores the same catalog pages for the doc 10 §6.4 scenarios,
 * after the dataset is loaded. Benchmark stack only.
 *
 *   wp eval-file tools/bench/prep-catalog.php
 *
 * 12 products per shop page (doc 10 §6.3), and a page `collection` with
 * each store's own product collection block: 12 products, newest first,
 * image, title, price and button. FlexaCommerce also runs its activation
 * (migrations, pages).
 */

// No strict_types: wp eval-file runs this through eval(), where a declare is not allowed.

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Setup\Activator;
use Flexa\Commerce\Storefront\Templates;
use Flexa\Commerce\Support\Settings;

// phpcs:disable -- dev tooling, not part of the plugin.

const BENCH_PER_PAGE = 12;

$woo = class_exists( 'WooCommerce' );

if ( $woo ) {
	// The shop shows columns x rows products (loop_shop_per_page); 4 columns is the default.
	update_option( 'woocommerce_catalog_columns', 4 );
	update_option( 'woocommerce_catalog_rows', BENCH_PER_PAGE / 4 );
	$content = '<!-- wp:woocommerce/product-collection {"queryId":2,"query":{"perPage":' . BENCH_PER_PAGE . ',"pages":0,"offset":0,"postType":"product","order":"desc","orderBy":"date","search":"","exclude":[],"inherit":false,"taxQuery":{},"isProductCollectionBlock":true,"featured":false,"woocommerceOnSaleOnly":false,"woocommerceStockStatus":["instock","outofstock","onbackorder"],"woocommerceAttributes":[],"woocommerceHandPickedProducts":[]},"tagName":"div","displayLayout":{"type":"flex","columns":4},"collection":"woocommerce/product-collection/product-catalog"} -->'
		. '<div class="wp-block-woocommerce-product-collection">'
		. '<!-- wp:woocommerce/product-template -->'
		. '<!-- wp:woocommerce/product-image {"isDescendentOfQueryLoop":true} /-->'
		. '<!-- wp:post-title {"isLink":true,"level":2,"fontSize":"medium","__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->'
		. '<!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true} /-->'
		. '<!-- wp:woocommerce/product-button {"isDescendentOfQueryLoop":true} /-->'
		. '<!-- /wp:woocommerce/product-template -->'
		. '</div><!-- /wp:woocommerce/product-collection -->';
} else {
	Activator::activate_site();
	Settings::update( [ 'products_per_page' => BENCH_PER_PAGE ] );
	$content = Templates::product_query( '{"queryId":2,"query":{"perPage":' . BENCH_PER_PAGE . ',"pages":0,"offset":0,"postType":"' . PostTypes::PRODUCT . '","order":"desc","orderBy":"date","inherit":false},"namespace":"flexacommerce/product-query"}' );
}

$existing = get_page_by_path( 'collection' );
wp_insert_post(
	[
		'ID'           => $existing ? $existing->ID : 0,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Collection',
		'post_name'    => 'collection',
		'post_content' => $content,
	]
);
wp_cache_flush();
WP_CLI::success( ( $woo ? 'woocommerce' : 'flexacommerce' ) . ': ' . BENCH_PER_PAGE . ' products per page, /collection/ ready.' );
