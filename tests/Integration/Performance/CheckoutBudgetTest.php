<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Performance;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Maintenance\Cron;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Support\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 T1 budgets (doc 10 §4.1): `POST store/checkout` with cash on
 * delivery, 1 and 10 lines, in a fresh process. Emails are deferred, as in
 * the T3 P6 scenario. A checkout uses up its cart, so every probe run gets
 * a new one; the "off" run is the same request without the plugin (a 404
 * from the REST server).
 */
final class CheckoutBudgetTest extends TestCase {
	private const BILLING = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Infinite Loop',
		'city'       => 'Cupertino',
		'state'      => 'CA',
		'postcode'   => '95014',
		'country'    => 'US',
		'email'      => 'ada@example.com',
	];

	/** @var list<int> */
	private static array $products = [];

	private static string $rate_id = '';

	/** @var array<string,mixed> */
	private static array $budgets = [];

	private static bool $deferred = false;

	private static int $page = 0;

	private static int $page_before = 0;

	public static function setUpBeforeClass(): void {
		global $wpdb;
		if ( is_multisite() ) {
			return;
		}
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		self::$budgets = require __DIR__ . '/budgets.php';
		wp_set_current_user( 0 );
		self::clear_rate_limits();

		for ( $i = 1; $i <= 10; $i++ ) {
			$id               = (int) wp_insert_post(
				[
					'post_type'   => PostTypes::PRODUCT,
					'post_status' => 'publish',
					'post_title'  => 'Checkout budget ' . $i,
				]
			);
			self::$products[] = $id;
			Plugin::instance()->catalog()->product_service()->save(
				$id,
				ProductInput::from_array(
					[
						'base' => [
							'regular_price'  => 1000 + $i,
							'manage_stock'   => true,
							'stock_quantity' => 1000,
						],
					]
				)
			);
		}
		$cod = Plugin::instance()->payments()->gateways()->get( 'cod' );
		self::assertNotNull( $cod );
		Plugin::instance()->payments()->gateways()->settings()->save( $cod, [ 'enabled' => true ] );
		$zones          = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		self::$rate_id  = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
		// A real checkout page, as on a store: its URL goes into the response and the emails.
		self::$page        = (int) wp_insert_post(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Checkout',
			]
		);
		self::$deferred    = Settings::bool( 'defer_emails' );
		self::$page_before = Settings::int( 'checkout_page_id' );
		Settings::update(
			[
				'defer_emails'     => true,
				'checkout_page_id' => self::$page,
			]
		);
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;
		if ( is_multisite() ) {
			return;
		}
		foreach ( self::$products as $id ) {
			$ids = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT(DISTINCT order_id) FROM ' . Schema::table( 'order_items' ) . ' WHERE product_id = %d', $id ) ); // phpcs:ignore WordPress.DB
			foreach ( array_filter( array_map( 'intval', explode( ',', $ids ) ) ) as $order_id ) {
				foreach ( [ 'order_items', 'order_addresses', 'order_history', 'order_meta', 'transactions' ] as $table ) {
					$wpdb->delete( Schema::table( $table ), [ 'order_id' => $order_id ] ); // phpcs:ignore WordPress.DB
				}
				$wpdb->delete( Schema::table( 'orders' ), [ 'id' => $order_id ] ); // phpcs:ignore WordPress.DB
			}
			wp_delete_post( $id, true );
		}
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'sessions' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fxc\\_mail\\_%'" ); // phpcs:ignore WordPress.DB
		self::clear_rate_limits();
		wp_unschedule_hook( Cron::SEND_EMAIL );
		Settings::update(
			[
				'defer_emails'     => self::$deferred,
				'checkout_page_id' => self::$page_before,
			]
		);
		wp_delete_post( self::$page, true );
		delete_option( GatewaySettings::PREFIX . 'cod' );
		delete_option( ZoneRepository::OPTION );
		wp_cache_flush();
	}

	protected function setUp(): void {
		if ( is_multisite() ) {
			self::markTestSkipped( 'The probe boots a single site.' );
		}
	}

	public function test_p6_checkout_costs_a_fixed_part_plus_one_query_per_line(): void {
		$off   = $this->pick( Probe::run( $this->uri(), false ), Probe::run( $this->uri(), false ) );
		$total = [];
		foreach ( [ 1, 10 ] as $lines ) {
			$this->place( $lines ); // Warm-up: first-request transients and the OPcache file cache.
			$on = $this->pick( $this->place( $lines ), $this->place( $lines ) );

			$total[ $lines ] = count( $on['queries'] ) - count( $off['queries'] );
			$this->report( "P6q queries at {$lines}", $total[ $lines ] );
			$delta = (int) $on['peak'] - (int) $off['peak'];
			$this->report( "P6m memory at {$lines}", $delta );
			if ( Probe::has_opcache() ) {
				self::assertLessThanOrEqual( (int) self::$budgets['P6m']['memory'], $delta, "P6m at {$lines}: extra peak memory {$delta} B is over the budget." );
			}
		}

		$per_line = (int) self::$budgets['P6q']['per_line'];
		$fixed    = $total[1] - $per_line;
		self::assertLessThanOrEqual( (int) self::$budgets['P6q']['fixed'], $fixed, "P6q: fixed part of {$fixed} queries is over the budget." );
		self::assertSame( 9 * $per_line, $total[10] - $total[1], 'P6q: checkout queries per line changed.' );
	}

	/** @return array<string,mixed> the probe result of one checkout with a new cart */
	private function place( int $lines ): array {
		$plugin   = Plugin::instance();
		$tokens   = new MemoryTokenStore();
		$cart     = new CartServices( $plugin->services(), $plugin->catalog(), $tokens );
		$checkout = ( new CheckoutServices( $plugin->services(), $cart, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();
		foreach ( array_slice( self::$products, 0, $lines ) as $product_id ) {
			$cart->cart()->add( 0, $product_id, 0, 1 );
		}
		$form    = $checkout->form_state( 0 );
		$session = (string) $tokens->get();

		$run = Probe::run(
			$this->uri(),
			true,
			[
				'method'  => 'POST',
				'cookies' => [ 'fxc_session' => $session ],
				'headers' => [
					'Content-Type' => 'application/json',
					'Origin'       => home_url(),
					'X-FXC-Nonce'  => $plugin->cart()->nonce()->create( 0, '', $session ),
				],
				'body'    => (string) wp_json_encode(
					[
						'checkout_token' => $form['checkout_token'],
						'billing'        => self::BILLING,
						'shipping_rate'  => self::$rate_id,
						'payment_method' => 'cod',
					]
				),
			]
		);
		self::assertSame( 201, $run['status'], (string) wp_json_encode( $run['rest'] ) );
		self::assertFalse( (bool) ( $run['rest']['duplicate'] ?? true ), 'The probe did not place a new order.' );
		return $run;
	}

	private function uri(): string {
		return '/?rest_route=/' . FLEXACOMMERCE_REST_NAMESPACE . '/store/checkout';
	}

	/**
	 * The smaller peak of two runs, which drops one-off allocator noise.
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 * @return array<string,mixed>
	 */
	private function pick( array $a, array $b ): array {
		return $a['peak'] <= $b['peak'] ? $a : $b;
	}

	private function report( string $label, int $value ): void {
		if ( getenv( 'FXC_PERF_REPORT' ) === '1' ) {
			fwrite( STDERR, "\n{$label}: {$value}" );
		}
	}

	private static function clear_rate_limits(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%" . RateLimiter::PREFIX . "%'" ); // phpcs:ignore WordPress.DB
	}
}
