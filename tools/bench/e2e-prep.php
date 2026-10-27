<?php
/**
 * Prepares e2e.bench for the browser tests (tests/e2e) after dataset S is
 * loaded, and writes what the tests need to <out.json>. Benchmark stack only.
 *
 *   wp eval-file tools/bench/e2e-prep.php <spec.json> <out.json>
 *
 * Cash on delivery on, one US zone with a flat rate of 5.00, a terms page,
 * a page with the filter block, a customer account, a product with colour
 * swatches in a category of its own, a sign priced by formula, and the
 * pages and products the journeys open.
 */

// No strict_types: wp eval-file runs this through eval(), where a declare is not allowed.

use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Setup\Activator;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\Settings;

// phpcs:disable -- dev tooling, not part of the plugin.

const E2E_CUSTOMER = 'shopper';
const E2E_PASSWORD = 'shopper-e2e-pass';

$spec     = json_decode( (string) file_get_contents( (string) ( $args[0] ?? '' ) ), true );
$out_file = (string) ( $args[1] ?? '' );
if ( ! is_array( $spec ) || $out_file === '' ) {
	WP_CLI::error( 'Usage: e2e-prep.php <spec.json> <out.json>' );
}

Activator::activate_site();
$gateways = Plugin::instance()->payments()->gateways();
$gateways->settings()->save( $gateways->get( 'cod' ), [ 'enabled' => true ] );
( new ZoneRepository() )->save(
	[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
);

$terms = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Terms and conditions',
		'post_name'    => 'terms',
		'post_content' => '<!-- wp:paragraph --><p>Store terms for the browser tests.</p><!-- /wp:paragraph -->',
	]
);
Settings::update( [ 'terms_page_id' => (int) $terms ] );

// The default templates have no filter block; the form submits to the shop.
$filters = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Browse',
		'post_name'    => 'browse',
		'post_content' => '<!-- wp:flexacommerce/product-filters {"attributeSlugs":["color"]} /-->',
	]
);

$user = wp_insert_user(
	[
		'user_login' => E2E_CUSTOMER,
		'user_pass'  => E2E_PASSWORD,
		'user_email' => 'shopper@example.test',
		'first_name' => 'Sam',
		'last_name'  => 'Shopper',
		'role'       => 'subscriber',
	]
);
if ( is_wp_error( $user ) ) {
	WP_CLI::error( $user->get_error_message() );
}

// The first simple product in stock with a price, the first variable product
// with two variation attributes, and a category with products in it.
$simple   = null;
$variable = null;
foreach ( $spec['products'] as $product ) {
	if ( $simple === null && $product['type'] === 'simple' && $product['stock'] > 5 && $product['sale_price'] === null ) {
		$simple = $product;
	}
	if ( $variable === null && $product['type'] === 'variable' && count( array_filter( $product['attributes'], static fn ( array $a ): bool => $a['variation'] ) ) === 2 ) {
		$variable = $product;
	}
}
if ( $simple === null || $variable === null ) {
	WP_CLI::error( 'The spec has no product to test with.' );
}
$post = static function ( array $product ): WP_Post {
	$found = get_page_by_path( (string) $product['slug'], OBJECT, PostTypes::PRODUCT );
	if ( ! $found instanceof WP_Post ) {
		WP_CLI::error( 'Missing product ' . $product['slug'] );
	}
	return $found;
};

$names = [];
foreach ( $spec['attributes'] as $attribute ) {
	foreach ( $attribute['values'] as $value ) {
		$names[ $value['slug'] ] = $value['name'];
	}
}
$variant = $variable['variants'][1];
$choices = [];
foreach ( $variant['attributes'] as $attribute => $value ) {
	$choices[ $attribute ] = $names[ $value ];
}

// A tee with a colour swatch attribute (ADR-038); Satin is out of stock.
$attributes = Plugin::instance()->catalog()->attributes();
$finish     = $attributes->create_attribute( 'Finish' );
$attributes->set_swatch( $finish, AttributeRepository::COLOR );
$looks  = [ 'Matte' => '#333333', 'Gloss' => '#d4af37', 'Satin' => '#8a8a8a' ];
$values = [];
foreach ( $looks as $name => $color ) {
	$values[ $name ] = $attributes->create_value( $finish, $name );
	$attributes->set_look( $values[ $name ], $color, null );
}
$tee_id   = (int) wp_insert_post(
	[
		'post_type'   => PostTypes::PRODUCT,
		'post_status' => 'publish',
		'post_title'  => 'Swatch tee',
		'post_name'   => 'swatch-tee',
	]
);
$products = Plugin::instance()->catalog()->product_service();
$products->save(
	$tee_id,
	ProductInput::from_array(
		[
			'type'       => 'variable',
			'attributes' => [
				[
					'type'              => 'global',
					'attribute_term_id' => $finish,
					'value_term_ids'    => array_values( $values ),
					'variation'         => true,
				],
			],
		]
	)
);
$rows = [];
foreach ( $products->generate_variants( $tee_id )->product->variations as $variation ) {
	$rows[] = [
		'id'             => $variation->id,
		'regular_price'  => 2500,
		'manage_stock'   => true,
		'stock_quantity' => in_array( (string) $values['Satin'], $variation->combination->to_array(), true ) ? 0 : 5,
	];
}
$products->save( $tee_id, ProductInput::from_array( [ 'variations' => $rows ] ) );
$tees = wp_insert_term( 'Swatch tees', PostTypes::CATEGORY, [ 'slug' => 'swatch-tees' ] );
wp_set_object_terms( $tee_id, [ (int) $tees['term_id'] ], PostTypes::CATEGORY );

// A sign priced by its area and its letters (ADR-039), its option set on it alone.
$sign_id = (int) wp_insert_post(
	[
		'post_type'   => PostTypes::PRODUCT,
		'post_status' => 'publish',
		'post_title'  => 'Custom sign',
		'post_name'   => 'custom-sign',
	]
);
$products->save( $sign_id, ProductInput::from_array( [ 'base' => [ 'regular_price' => 1000 ] ] ) );
Plugin::instance()->option_sets()->save(
	[
		'name'        => 'Sign size',
		'applies_to'  => 'products',
		'product_ids' => [ $sign_id ],
		'fields'      => [
			[
				'id'       => 1,
				'type'     => 'number',
				'label'    => 'Width (cm)',
				'required' => true,
			],
			[
				'id'         => 2,
				'type'       => 'number',
				'label'      => 'Height (cm)',
				'required'   => true,
				'price_mode' => 'formula',
				'formula'    => 'round({1} * {2} / 10000 * 25, 2)',
			],
			[
				'id'         => 3,
				'type'       => 'text',
				'label'      => 'Lettering',
				'price_mode' => 'formula',
				'formula'    => '{3} * 0.5',
			],
		],
	]
);

$category = get_term_by( 'slug', (string) $simple['categories'][0], PostTypes::CATEGORY );

$out = [
	'shop'     => (string) get_post_type_archive_link( PostTypes::PRODUCT ),
	'cart'     => (string) get_permalink( Settings::int( 'cart_page_id' ) ),
	'checkout' => (string) get_permalink( Settings::int( 'checkout_page_id' ) ),
	'account'  => (string) get_permalink( Settings::int( 'account_page_id' ) ),
	'filters'  => (string) get_permalink( (int) $filters ),
	'customer' => [
		'login'    => E2E_CUSTOMER,
		'password' => E2E_PASSWORD,
	],
	'simple'   => [
		'id'    => $post( $simple )->ID,
		'name'  => $simple['name'],
		'url'   => (string) get_permalink( $post( $simple ) ),
		'sku'   => $simple['sku'],
		'price' => $simple['regular_price'],
	],
	'variable' => [
		'id'      => $post( $variable )->ID,
		'name'    => $variable['name'],
		'url'     => (string) get_permalink( $post( $variable ) ),
		'choices' => $choices,
		'price'   => $variant['regular_price'],
	],
	'swatches' => [
		'name'     => 'Swatch tee',
		'url'      => (string) get_permalink( $tee_id ),
		'category' => (string) get_term_link( (int) $tees['term_id'], PostTypes::CATEGORY ),
		'price'    => 2500,
	],
	'formula'  => [
		'name'  => 'Custom sign',
		'url'   => (string) get_permalink( $sign_id ),
		'price' => 1000,
	],
	'category' => [
		'slug' => $category instanceof WP_Term ? $category->slug : '',
		'url'  => $category instanceof WP_Term ? (string) get_term_link( $category ) : '',
	],
];
wp_cache_flush();
file_put_contents( $out_file, json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );
WP_CLI::success( 'e2e.bench ready: ' . $out['simple']['url'] . ', ' . $out['variable']['url'] );
