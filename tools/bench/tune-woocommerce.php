<?php
/**
 * The "tuned" WooCommerce configuration (doc 10 §6.3): features a careful
 * store turns off and that have no FlexaCommerce equivalent. Runs on the
 * copy of the WooCommerce store served as wct.bench. Benchmark stack only.
 *
 *   wp eval-file tools/bench/tune-woocommerce.php <out.json>
 *
 * The list goes to <out.json> and into the report.
 */

// No strict_types: wp eval-file runs this through eval(), where a declare is not allowed.

use Automattic\WooCommerce\Internal\Features\FeaturesController;

// phpcs:disable -- dev tooling, not part of the plugin.

$out_file = (string) ( $args[0] ?? '' );

// Features that are on by default in WooCommerce 11.2, can be turned off and
// have no FlexaCommerce equivalent. Marketplace and point of sale are on by
// default too, but deprecated and always on (deprecated_value).
$features = [
	'analytics',             // WooCommerce Analytics: order stats tables and their sync jobs.
	'order_attribution',     // Order source tracking: a storefront script and checkout fields.
	'remote_logging',        // Error reports sent to WooCommerce.com.
	'site_visibility_badge', // Coming-soon badge in the admin bar.
	'blueprint',             // Settings export and import.
];
$controller = wc_get_container()->get( FeaturesController::class );
$result     = [];
foreach ( $features as $feature ) {
	$was = $controller->feature_is_enabled( $feature );
	$controller->change_feature_enable( $feature, false );
	$result['features'][ $feature ] = [
		'was' => $was,
		'now' => $controller->feature_is_enabled( $feature ),
	];
}

$options = [
	'woocommerce_allow_tracking'               => 'no',
	'woocommerce_show_marketplace_suggestions' => 'no',
	'woocommerce_coming_soon'                  => 'no', // Already off in the default store, or the shop is hidden.
];
foreach ( $options as $name => $value ) {
	$result['options'][ $name ] = [
		'was' => get_option( $name ),
		'now' => $value,
	];
	update_option( $name, $value );
}

wp_cache_flush();
file_put_contents( $out_file, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
WP_CLI::success( 'Tuned: ' . implode( ', ', $features ) );
