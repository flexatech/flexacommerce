<?php
/**
 * Prepares a store for the checkout scenario (doc 10 §4.2 P6, §6.4) and
 * writes the IDs the load test needs. Benchmark stack only.
 *
 *   wp eval-file tools/bench/prep-checkout.php <spec.json> <out.json>
 *
 * Both stores get the same setup: cash on delivery on, one US zone with a
 * flat rate of 5.00, deferred order emails, and the first five simple
 * products of the spec with managed stock of 1,000,000 so a run never sells
 * out. FlexaCommerce also runs its activation (migrations, pages), because
 * the setup dumps can be older than the code under test.
 */

// No strict_types: wp eval-file runs this through eval(), where a declare is not allowed.

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Setup\Activator;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\Settings;

// phpcs:disable -- dev tooling, not part of the plugin.

const BENCH_CHECKOUT_LINES = 5;
const BENCH_CHECKOUT_STOCK = 1000000;

$spec     = json_decode( (string) file_get_contents( (string) ( $args[0] ?? '' ) ), true );
$out_file = (string) ( $args[1] ?? '' );
if ( ! is_array( $spec ) || $out_file === '' ) {
	WP_CLI::error( 'Usage: prep-checkout.php <spec.json> <out.json>' );
}
$woo = class_exists( 'WooCommerce' );

$ids = [];
foreach ( $spec['products'] as $product ) {
	if ( $product['type'] === 'simple' && count( $ids ) < BENCH_CHECKOUT_LINES ) {
		$found = get_page_by_path( (string) $product['slug'], OBJECT, $woo ? 'product' : PostTypes::PRODUCT );
		if ( ! $found ) {
			WP_CLI::error( 'Missing product ' . $product['slug'] );
		}
		$ids[] = (int) $found->ID;
	}
}

if ( $woo ) {
	update_option( 'woocommerce_cod_settings', array_merge( (array) get_option( 'woocommerce_cod_settings', [] ), [ 'enabled' => 'yes' ] ) );
	update_option( 'woocommerce_calc_taxes', 'no' );
	foreach ( WC_Shipping_Zones::get_zones() as $existing ) {
		WC_Shipping_Zones::delete_zone( (int) $existing['id'] );
	}
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'US' );
	$zone->add_location( 'US', 'country' );
	$zone->save();
	$instance = (int) $zone->add_shipping_method( 'flat_rate' );
	update_option(
		"woocommerce_flat_rate_{$instance}_settings",
		[
			'title'      => 'Flat rate',
			'tax_status' => 'none',
			'cost'       => '5',
		]
	);
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( BENCH_CHECKOUT_STOCK );
		$product->save();
	}
	$rate = "flat_rate:{$instance}";
} else {
	Activator::activate_site();
	$gateways = Plugin::instance()->payments()->gateways();
	$gateways->settings()->save( $gateways->get( 'cod' ), [ 'enabled' => true ] );
	$zones = ( new ZoneRepository() )->save(
		[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
	);
	Settings::update( [ 'defer_emails' => true ] );
	global $wpdb;
	foreach ( $ids as $id ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET manage_stock = 1, stock_quantity = %d, stock_status = %s WHERE product_id = %d', Schema::table( 'variants' ), BENCH_CHECKOUT_STOCK, 'instock', $id ) );
	}
	wp_cache_flush();
	$rate = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
}

file_put_contents(
	$out_file,
	json_encode(
		[
			'store' => $woo ? 'woocommerce' : 'flexacommerce',
			'ids'   => $ids,
			'rate'  => $rate,
			'stock' => BENCH_CHECKOUT_STOCK,
		],
		JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
	) . "\n"
);
WP_CLI::success( ( $woo ? 'woocommerce' : 'flexacommerce' ) . ' ready for checkout: products ' . implode( ',', $ids ) . ", rate {$rate}" );
