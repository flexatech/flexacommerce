<?php
/**
 * One buyer in its own PHP process and database connection. Run by
 * ConcurrencyTest, never directly by PHPUnit.
 *
 *   php worker.php <deduct|add> <product-id> <start-at> [<session-token>]
 *
 * Waits until <start-at> (a microtime) so all workers hit the database
 * together, then prints one JSON line with the outcome.
 */

declare(strict_types=1);

// phpcs:disable -- test tooling, runs outside WordPress until wp-settings.php.

[ , $fxc_mode, $fxc_product, $fxc_start, $fxc_token ] = $argv + [ '', 'deduct', '0', '0', '' ];

$_SERVER['HTTP_HOST']   = 'example.org';
$_SERVER['SERVER_NAME'] = 'example.org';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

define( 'WP_USE_THEMES', false );
define( 'DISABLE_WP_CRON', true );

require dirname( __DIR__, 2 ) . '/wp-tests-config.php';

$GLOBALS['wp_filter']['option_active_plugins'][10][] = [
	'function'      => static fn (): array => [],
	'accepted_args' => 1,
];
$GLOBALS['wp_filter']['muplugins_loaded'][10][] = [
	'function'      => static function (): void {
		require dirname( __DIR__, 3 ) . '/flexacommerce.php';
	},
	'accepted_args' => 1,
];

require ABSPATH . 'wp-settings.php';

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Inventory\StockLine;
use Flexa\Commerce\Plugin;

$plugin   = Plugin::instance();
$services = new CartServices( $plugin->services(), $plugin->catalog(), new MemoryTokenStore( $fxc_token !== '' ? $fxc_token : null ) );
$product  = $plugin->catalog()->product_service()->get( (int) $fxc_product );

while ( microtime( true ) < (float) $fxc_start ) {
	usleep( 200 );
}

try {
	if ( $fxc_mode === 'deduct' ) {
		$services->stock()->deduct( [ new StockLine( $product, $product->base, 1 ) ], 'concurrency-test' );
	} else {
		$services->cart()->add( 0, $product->id, 0, 1 );
	}
	echo json_encode( [ 'ok' => true ] ), "\n";
} catch ( CommerceException $e ) {
	echo json_encode( [ 'ok' => false, 'error' => get_class( $e ) ] ), "\n";
}
