<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Performance;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Tax\TaxLocation;
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
use Flexa\Commerce\Tax\RateRow;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.5 T1 budgets (doc 10 §4.1, PT-*). Each probe compares the same
 * request with taxes on and off, the plugin loaded both times, so the
 * difference is what taxes add. The store sits in Cupertino, where four
 * stacked rates apply in each of five classes.
 */
final class TaxBudgetTest extends TestCase {
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

	private const CLASSES = [ 'reduced-rate', 'zero-rate', 'food', 'clothing' ];

	/** @var list<int> */
	private static array $products = [];

	private static int $variable = 0;

	private static string $rate_id = '';

	private static int $page = 0;

	/** @var array<string,mixed> */
	private static array $budgets = [];

	private static mixed $settings = null;

	public static function setUpBeforeClass(): void {
		global $wpdb;
		if ( is_multisite() ) {
			return;
		}
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		self::$budgets  = require __DIR__ . '/budgets.php';
		self::$settings = get_option( Settings::OPTION );
		wp_set_current_user( 0 );
		self::clear_rate_limits();

		$service = Plugin::instance()->catalog()->product_service();
		for ( $i = 1; $i <= 12; $i++ ) {
			$id               = (int) wp_insert_post(
				[
					'post_type'   => PostTypes::PRODUCT,
					'post_status' => 'publish',
					'post_title'  => 'Tax budget ' . $i,
				]
			);
			self::$products[] = $id;
			$service->save(
				$id,
				ProductInput::from_array(
					[
						'base' => [
							'regular_price'  => 1000 + $i,
							'manage_stock'   => true,
							'stock_quantity' => 1000,
							'tax_class'      => $i % 2 === 0 ? 'reduced-rate' : '',
						],
					]
				)
			);
		}
		self::$variable   = (int) wp_insert_post(
			[
				'post_type'   => PostTypes::PRODUCT,
				'post_status' => 'publish',
				'post_title'  => 'Tax budget variable',
			]
		);
		self::$products[] = self::$variable;
		$service->save(
			self::$variable,
			ProductInput::from_array(
				[
					'type'       => 'variable',
					'attributes' => [
						[
							'type'      => 'local',
							'name'      => 'Size',
							'values'    => [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ],
							'variation' => true,
						],
					],
				]
			)
		);
		$rows = [];
		foreach ( $service->generate_variants( self::$variable )->product->variations as $n => $variation ) {
			$rows[] = [
				'id'            => $variation->id,
				'regular_price' => 3000 + $n,
			];
		}
		$service->save( self::$variable, ProductInput::from_array( [ 'variations' => $rows ] ) );

		$cod = Plugin::instance()->payments()->gateways()->get( 'cod' );
		self::assertNotNull( $cod );
		Plugin::instance()->payments()->gateways()->settings()->save( $cod, [ 'enabled' => true ] );
		$zones         = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		self::$rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
		self::$page    = (int) wp_insert_post(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Checkout',
			]
		);

		Settings::update(
			[
				'defer_emails'         => true,
				'checkout_page_id'     => self::$page,
				'store_country'        => 'US',
				'store_state'          => 'CA',
				'store_postcode'       => '95014',
				'store_city'           => 'Cupertino',
				'tax_display_shop'     => 'incl',
				'price_display_suffix' => 'incl. tax',
				'tax_classes'          => array_map( static fn ( string $slug ): array => [ 'slug' => $slug, 'name' => ucfirst( $slug ) ], self::CLASSES ),
			]
		);
		$repository = Plugin::instance()->tax()->repository();
		foreach ( [ '', ...self::CLASSES ] as $class ) {
			foreach ( self::stacked( $class ) as $input ) {
				$repository->save( RateRow::from_input( $input ) );
			}
		}
		self::taxes( false );
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
		foreach ( [ 'tax_rate_locations', 'tax_rates', 'sessions' ] as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fxc\\_mail\\_%'" ); // phpcs:ignore WordPress.DB
		self::clear_rate_limits();
		wp_unschedule_hook( Cron::SEND_EMAIL );
		wp_delete_post( self::$page, true );
		delete_option( GatewaySettings::PREFIX . 'cod' );
		delete_option( ZoneRepository::OPTION );
		update_option( Settings::OPTION, self::$settings, true );
		wp_cache_flush();
	}

	protected function setUp(): void {
		if ( is_multisite() ) {
			self::markTestSkipped( 'The probe boots a single site.' );
		}
	}

	protected function tearDown(): void {
		self::taxes( false );
	}

	public function test_pt_opt_compiled_base_rates_keep_the_settings_option_small(): void {
		self::taxes( true );
		$compiled = Settings::get( 'tax_base_rates' );
		self::assertIsArray( $compiled );
		self::assertCount( 5, $compiled );
		foreach ( $compiled as $class => $rates ) {
			self::assertCount( 4, $rates, "class {$class}" );
		}
		$bytes = strlen( (string) maybe_serialize( get_option( Settings::OPTION ) ) );
		$this->report( 'PT-opt bytes', $bytes );
		self::assertLessThanOrEqual( (int) self::$budgets['PT-opt']['bytes'], $bytes );
	}

	public function test_pt_off_and_pt_cat_catalog_pages_cost_nothing_for_taxes(): void {
		$pages = [
			'archive'  => '/?post_type=' . PostTypes::PRODUCT,
			'simple'   => '/?post_type=' . PostTypes::PRODUCT . '&p=' . self::$products[0],
			'variable' => '/?post_type=' . PostTypes::PRODUCT . '&p=' . self::$variable,
		];
		foreach ( $pages as $label => $uri ) {
			[ $off, $on ] = $this->both( $uri );
			self::assertSame( 200, $on['status'] );
			self::assertSame( [], $this->tax_classes( $off ), "PT-off {$label}: a catalog page with taxes off loaded tax classes." );
			$extra = count( $on['queries'] ) - count( $off['queries'] );
			$this->report( "PT-cat {$label} queries", $extra );
			self::assertLessThanOrEqual( (int) self::$budgets['PT-cat']['queries'], $extra, "PT-cat {$label}:\n  " . implode( "\n  ", $this->extra( $on, $off ) ) );
		}
	}

	public function test_pt_cart_reads_the_rates_at_most_once(): void {
		$extra = [];
		foreach ( [ 1, 10 ] as $lines ) {
			$token = $this->cart( $lines )[2];
			$uri   = '/?rest_route=/' . FLEXACOMMERCE_REST_NAMESPACE . '/store/cart';
			[ $off, $on ] = $this->both( $uri, [ 'cookies' => [ 'fxc_session' => $token ] ] );
			self::assertSame( 200, $on['status'] );
			self::assertSame( $lines, $on['rest']['item_count'] ?? null, 'The probe did not read the cart.' );
			$this->assert_off( $off, "PT-off cart at {$lines}" );
			$extra[ $lines ] = count( $on['queries'] ) - count( $off['queries'] );
			$this->report( "PT-cart queries at {$lines}", $extra[ $lines ] );
			self::assertLessThanOrEqual( (int) self::$budgets['PT-cart']['queries'], $extra[ $lines ], "PT-cart at {$lines}:\n  " . implode( "\n  ", $this->extra( $on, $off ) ) );
		}
		self::assertSame( $extra[1], $extra[10], 'PT-cart: tax queries grow with the number of lines.' );
	}

	public function test_pt_q_and_pt_m_checkout_with_four_stacked_rates(): void {
		$extra = [];
		foreach ( [ 1, 10 ] as $lines ) {
			self::taxes( false );
			$this->place( $lines, false ); // Warm-up.
			$off = $this->pick( $this->place( $lines, false ), $this->place( $lines, false ) );
			$this->assert_off( $off, "PT-off checkout at {$lines}" );
			self::taxes( true );
			$this->place( $lines, true );
			$on = $this->pick( $this->place( $lines, true ), $this->place( $lines, true ) );

			$extra[ $lines ] = count( $on['queries'] ) - count( $off['queries'] );
			$this->report( "PT-q queries at {$lines}", $extra[ $lines ] );
			self::assertLessThanOrEqual( (int) self::$budgets['PT-q']['queries'], $extra[ $lines ], "PT-q at {$lines}:\n  " . implode( "\n  ", $this->extra( $on, $off ) ) );
			if ( $lines === 10 ) {
				$delta = (int) $on['peak'] - (int) $off['peak'];
				$this->report( 'PT-m memory at 10', $delta );
				if ( Probe::has_opcache() ) {
					self::assertLessThanOrEqual( (int) self::$budgets['PT-m']['memory'], $delta, "PT-m: extra peak memory {$delta} B is over the budget." );
				}
			}
		}
		self::assertSame( $extra[1], $extra[10], 'PT-q: tax queries grow with the number of lines.' );
	}

	public function test_pt_rows_one_match_reads_few_rows_of_a_large_table(): void {
		global $wpdb;
		$rates = Schema::table( 'tax_rates' );
		$locs  = Schema::table( 'tax_rate_locations' );
		$now   = current_time( 'mysql', true );
		$first = 0;
		// 5 000 rates over the states, 10 ZIP codes each, and 50 ZIP ranges: 50 050 locations.
		$states = [ 'AL', 'AZ', 'AR', 'CO', 'CT', 'FL', 'GA', 'IL', 'IN', 'KS', 'NY', 'TX', 'WA', 'CA' ];
		for ( $chunk = 0; $chunk < 50; $chunk++ ) {
			$values = [];
			for ( $i = 0; $i < 100; $i++ ) {
				$n        = $chunk * 100 + $i;
				$values[] = $wpdb->prepare( "('', 'US', %s, 10000, %s, 2, 0, 1, %d, 10, 'import', %s, %s)", $states[ $n % count( $states ) ], 'Local ' . $n, $n + 100, $now, $now );
			}
			$wpdb->query( "INSERT INTO {$rates} (tax_class, country, state, rate, name, priority, compound, shipping, rate_order, location_count, source, created_at, updated_at) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
			if ( $chunk === 0 ) {
				$first = (int) $wpdb->insert_id;
			}
		}
		for ( $chunk = 0; $chunk < 50; $chunk++ ) {
			$values = [];
			for ( $i = 0; $i < 100; $i++ ) {
				$n = $chunk * 100 + $i;
				for ( $z = 0; $z < 10; $z++ ) {
					$values[] = sprintf( "(%d, 'postcode', '%05d', NULL, NULL)", $first + $n, 10000 + ( $n * 10 + $z ) % 89999 );
				}
			}
			$wpdb->query( "INSERT INTO {$locs} (rate_id, type, code, range_from, range_to) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		}
		$values = [];
		for ( $r = 0; $r < 50; $r++ ) {
			$values[] = sprintf( "(%d, 'postcode', '%d...%d', %d, %d)", $first + $r, 10000 + $r * 1700, 10000 + $r * 1700 + 99, 10000 + $r * 1700, 10000 + $r * 1700 + 99 );
		}
		$wpdb->query( "INSERT INTO {$locs} (rate_id, type, code, range_from, range_to) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( "ANALYZE TABLE {$rates}, {$locs}" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();

		$repository = Plugin::instance()->tax()->repository();
		$read       = static function () use ( $wpdb ): int {
			$sum = 0;
			foreach ( (array) $wpdb->get_results( "SHOW SESSION STATUS LIKE 'Handler\\_read\\_%'", ARRAY_N ) as $row ) { // phpcs:ignore WordPress.DB
				$sum += (int) $row[1];
			}
			return $sum;
		};
		$wpdb->query( 'FLUSH STATUS' ); // phpcs:ignore WordPress.DB
		$noise = $read();
		$noise = $read() - $noise;

		$at    = new TaxLocation( 'US', 'CA', '95014', 'Cupertino' );
		$wpdb->query( 'FLUSH STATUS' ); // phpcs:ignore WordPress.DB
		$start = $read();
		$found = $repository->matching( $at, [ '', ...self::CLASSES ] );
		$rows  = $read() - $start - 2 * $noise;
		$this->report( 'PT-rows', $rows );
		self::assertCount( 4, $found[''], 'The stacked Cupertino rates are still found.' );
		self::assertLessThanOrEqual( (int) self::$budgets['PT-rows']['rows'], $rows, "PT-rows: {$rows} rows read for one match." );

		$wpdb->query( "DELETE FROM {$locs} WHERE rate_id >= {$first}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$rates} WHERE id >= {$first}" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
	}

	/**
	 * State 6 %, county 0.25 %, city 1 % and a district 2.125 % for one ZIP.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function stacked( string $class ): array {
		$row = [
			'country'   => 'US',
			'state'     => 'CA',
			'tax_class' => $class,
		];
		return [
			$row + [ 'rate' => '6', 'name' => 'CA State', 'priority' => 1 ],
			$row + [ 'rate' => '0.25', 'name' => 'County', 'priority' => 2, 'postcodes' => '950*' ],
			$row + [ 'rate' => '1', 'name' => 'City', 'priority' => 3, 'postcodes' => '95014', 'cities' => 'Cupertino' ],
			$row + [ 'rate' => '2.125', 'name' => 'District', 'priority' => 4, 'postcodes' => '95000...95099', 'shipping' => false ],
		];
	}

	private static function taxes( bool $on ): void {
		Settings::update( [ 'tax_enabled' => $on ] );
		Plugin::instance()->tax()->service()->compile_base_rates();
		wp_cache_flush();
	}

	/**
	 * The request with taxes off and on, each the smaller peak of two runs
	 * after a warm-up.
	 *
	 * @param array<string,mixed> $options
	 * @return array{array<string,mixed>,array<string,mixed>}
	 */
	private function both( string $uri, array $options = [] ): array {
		$out = [];
		foreach ( [ false, true ] as $on ) {
			self::taxes( $on );
			Probe::run( $uri, true, $options );
			$out[] = $this->pick( Probe::run( $uri, true, $options ), Probe::run( $uri, true, $options ) );
		}
		return $out;
	}

	/**
	 * A guest cart with `$lines` lines and a known US address.
	 *
	 * @return array{CartServices,\Flexa\Commerce\Checkout\CheckoutService,string}
	 */
	private function cart( int $lines ): array {
		$plugin   = Plugin::instance();
		$tokens   = new MemoryTokenStore();
		$cart     = new CartServices( $plugin->services(), $plugin->catalog(), $tokens );
		$checkout = ( new CheckoutServices( $plugin->services(), $cart, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();
		foreach ( array_slice( self::$products, 0, $lines ) as $product_id ) {
			$cart->cart()->add( 0, $product_id, 0, 1 );
		}
		$cart->cart()->set_shipping(
			0,
			[
				'country'  => 'US',
				'state'    => 'CA',
				'postcode' => '95014',
				'city'     => 'Cupertino',
			],
			self::$rate_id
		);
		return [ $cart, $checkout, (string) $tokens->get() ];
	}

	/** @return array<string,mixed> the probe result of one checkout with a new cart */
	private function place( int $lines, bool $taxes ): array {
		[ , $checkout, $session ] = $this->cart( $lines );
		$form = $checkout->form_state( 0 );
		self::assertSame( $taxes, $form['totals']['tax']['amount'] > 0, 'The form state has the wrong tax.' );

		$run = Probe::run(
			'/?rest_route=/' . FLEXACOMMERCE_REST_NAMESPACE . '/store/checkout',
			true,
			[
				'method'  => 'POST',
				'cookies' => [ 'fxc_session' => $session ],
				'headers' => [
					'Content-Type' => 'application/json',
					'Origin'       => home_url(),
					'X-FXC-Nonce'  => Plugin::instance()->cart()->nonce()->create( 0, '', $session ),
				],
				'body'    => (string) wp_json_encode(
					[
						'checkout_token' => $form['checkout_token'],
						'billing'        => self::BILLING,
						'shipping_rate'  => self::$rate_id,
						'payment_method' => 'cod',
						'expected_total' => $form['totals']['total']['amount'],
					]
				),
			]
		);
		self::assertSame( 201, $run['status'], (string) wp_json_encode( $run['rest'] ) );
		self::assertFalse( (bool) ( $run['rest']['duplicate'] ?? true ), 'The probe did not place a new order.' );
		return $run;
	}

	/**
	 * PT-off: with taxes off, the only tax class a request loads is the
	 * service that answers enabled().
	 *
	 * @param array<string,mixed> $off
	 */
	private function assert_off( array $off, string $label ): void {
		$allowed = [ 'Flexa\\Commerce\\Tax\\TaxService', 'Flexa\\Commerce\\Tax\\TaxServices' ];
		self::assertSame( [], array_values( array_diff( $this->tax_classes( $off ), $allowed ) ), "{$label}: loaded tax classes with taxes off." );
	}

	/**
	 * @param array<string,mixed> $run
	 * @return list<string>
	 */
	private function tax_classes( array $run ): array {
		return array_values( array_filter( (array) $run['own'], static fn ( string $class ): bool => preg_match( '/^Flexa\\\\Commerce\\\\(Contracts\\\\)?Tax\\\\/', $class ) === 1 ) );
	}

	/**
	 * The queries of `$on` that `$off` does not have, with their callers.
	 *
	 * @param array<string,mixed> $on
	 * @param array<string,mixed> $off
	 * @return list<string>
	 */
	private function extra( array $on, array $off ): array {
		$seen = array_count_values( array_column( $off['queries'], 'sql' ) );
		$list = [];
		foreach ( $on['queries'] as $query ) {
			if ( ( $seen[ $query['sql'] ] ?? 0 ) > 0 ) {
				--$seen[ $query['sql'] ];
				continue;
			}
			$list[] = $query['sql'] . "\n    from " . $query['caller'];
		}
		return $list;
	}

	/**
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
