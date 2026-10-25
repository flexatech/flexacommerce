<?php
/**
 * One buyer (or cron run, or webhook delivery) in its own PHP process and
 * database connection. Run by ConcurrencyTest, never directly by PHPUnit.
 *
 *   php worker.php <mode> <start-at> <json-args>
 *
 * Modes: add, checkout, webhook, expire, cancel. Setup work (a
 * fresh cart for `checkout`) happens first; then the worker waits until
 * <start-at> (a microtime) so all workers hit the database together, and
 * prints one JSON line with the outcome.
 */

declare(strict_types=1);

// phpcs:disable -- test tooling, runs outside WordPress until wp-settings.php.

[ , $fxc_mode, $fxc_start, $fxc_json ] = $argv + [ '', 'add', '0', '{}' ];
$fxc_args = (array) json_decode( $fxc_json, true );

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
// Order emails are not under test here.
$GLOBALS['wp_filter']['pre_wp_mail'][10][] = [
	'function'      => static fn (): bool => true,
	'accepted_args' => 1,
];

require ABSPATH . 'wp-settings.php';
$GLOBALS['wpdb']->hide_errors(); // Keeps stdout to the one JSON line; stderr still gets the log.
require dirname( __DIR__ ) . '/Payments/FakeGateway.php';

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Tests\Integration\Payments\FakeGateway;

$plugin = Plugin::instance();
$plugin->payments()->gateways()->register( FakeGateway::ID, static fn (): PaymentGateway => new FakeGateway() );
$token    = (string) ( $fxc_args['token'] ?? '' );
$services = new CartServices( $plugin->services(), $plugin->catalog(), new MemoryTokenStore( $token !== '' ? $token : null ) );
$checkout = ( new CheckoutServices( $plugin->services(), $services, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();

$fxc_run = null;
try {
	switch ( $fxc_mode ) {
		case 'add':
			$fxc_run = static function () use ( $services, $fxc_args ): array {
				$services->cart()->add( 0, (int) $fxc_args['product'], 0, 1 );
				return [];
			};
			break;
		case 'checkout':
			// A new cart per worker unless the test shares one by token.
			foreach ( $token === '' ? (array) $fxc_args['products'] : [] as $product_id ) {
				$services->cart()->add( 0, (int) $product_id, 0, 1 );
			}
			$form    = $checkout->form_state( 0 );
			$request = CheckoutRequest::from_array(
				[
					'checkout_token' => $form['checkout_token'],
					'billing'        => (array) $fxc_args['billing'],
					'shipping_rate'  => (string) $fxc_args['rate'],
					'payment_method' => 'cod',
				],
				0,
				(string) $fxc_args['ip']
			);
			$fxc_run = static function () use ( $checkout, $request ): array {
				$result = $checkout->place_order( $request );
				return [
					'order'     => $result->order->id,
					'duplicate' => $result->duplicate,
				];
			};
			break;
		case 'webhook':
			$fxc_run = static function () use ( $plugin, $fxc_args ): array {
				return [ 'code' => $plugin->payments()->service()->handle_webhook( FakeGateway::ID, FakeGateway::signed( (array) $fxc_args['payload'] ) ) ];
			};
			break;
		case 'expire':
			$fxc_run = static function () use ( $plugin ): array {
				return [ 'cancelled' => $plugin->orders()->service()->expire_pending() ];
			};
			break;
		case 'cancel':
			$fxc_run = static function () use ( $plugin, $fxc_args ): array {
				$plugin->orders()->service()->cancel( (int) $fxc_args['order'], new TransitionContext( 'customer' ) );
				return [];
			};
			break;
	}
} catch ( \Throwable $e ) {
	echo json_encode(
		[
			'ok'    => false,
			'error' => 'setup: ' . get_class( $e ) . ': ' . $e->getMessage(),
		]
	), "\n";
	exit;
}

while ( microtime( true ) < (float) $fxc_start ) {
	usleep( 200 );
}

try {
	echo json_encode( [ 'ok' => true ] + $fxc_run() ), "\n";
} catch ( InvalidCheckout $e ) {
	echo json_encode(
		[
			'ok'     => false,
			'error'  => get_class( $e ),
			'errors' => $e->errors,
		]
	), "\n";
} catch ( CommerceException $e ) {
	echo json_encode(
		[
			'ok'    => false,
			'error' => get_class( $e ),
		]
	), "\n";
}
