<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Concurrency;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Tests\Integration\Payments\FakeGateway;
use PHPUnit\Framework\TestCase;

/**
 * The concurrency suite of doc 06 §5.4: buyers, cron runs and webhook
 * deliveries in parallel processes, each with its own database connection.
 * A plain TestCase, because the workers must see committed rows, so
 * everything written here is removed again in tearDown().
 */
final class ConcurrencyTest extends TestCase {
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
	private array $posts = [];

	private string $rate_id = '';

	protected function setUp(): void {
		global $wpdb;
		if ( is_multisite() ) {
			self::markTestSkipped( 'The workers boot a single site.' );
		}
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		wp_set_current_user( 0 );

		$gateways = Plugin::instance()->payments()->gateways();
		if ( ! $gateways->has( FakeGateway::ID ) ) {
			$gateways->register( FakeGateway::ID, static fn (): PaymentGateway => new FakeGateway() );
		}
		foreach ( [ 'cod', FakeGateway::ID ] as $id ) {
			$gateway = $gateways->get( $id );
			self::assertNotNull( $gateway );
			$gateways->settings()->save(
				$gateway,
				[
					'enabled'        => true,
					'webhook_secret' => FakeGateway::SECRET,
				]
			);
		}
		FakeGateway::$payment_mode = 'redirect';
		$zones         = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		$this->rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->posts as $id ) {
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
		$wpdb->delete( Schema::table( 'webhook_events' ), [ 'gateway' => FakeGateway::ID ] ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%" . RateLimiter::PREFIX . "%'" ); // phpcs:ignore WordPress.DB
		foreach ( [ 'cod', FakeGateway::ID ] as $id ) {
			delete_option( GatewaySettings::PREFIX . $id );
		}
		delete_option( ZoneRepository::OPTION );
		wp_cache_flush();
	}

	/** @return array<string,array{int}> */
	public static function stock_levels(): array {
		return [
			'last unit'    => [ 1 ],
			'last k units' => [ 5 ],
		];
	}

	/** @dataProvider stock_levels */
	public function test_parallel_checkouts_never_oversell( int $stock ): void {
		$id      = $this->product( $stock );
		$results = $this->race( 'checkout', 20, fn ( int $i ): array => $this->buyer( $i, [ $id ] ) );

		self::assertCount( $stock, $this->orders_of( $results ), 'Wrong number of buyers got an order.' );
		foreach ( array_filter( $results, static fn ( array $r ): bool => ! $r['ok'] ) as $failed ) {
			self::assertSame( 'Flexa\\Commerce\\Contracts\\Exceptions\\InvalidCheckout', $failed['error'] );
			self::assertArrayHasKey( 'cart', $failed['errors'] );
		}
		self::assertSame( 0, $this->stock_of( $id ) );
	}

	public function test_lines_locked_in_opposite_order_do_not_deadlock(): void {
		$a       = $this->product( 5 );
		$b       = $this->product( 5 );
		$results = $this->race( 'checkout', 10, fn ( int $i ): array => $this->buyer( $i, $i % 2 === 0 ? [ $a, $b ] : [ $b, $a ] ) );

		self::assertCount( 5, $this->orders_of( $results ) );
		foreach ( array_filter( $results, static fn ( array $r ): bool => ! $r['ok'] ) as $failed ) {
			self::assertSame( 'Flexa\\Commerce\\Contracts\\Exceptions\\InvalidCheckout', $failed['error'], 'A deadlock reached the buyer.' );
		}
		self::assertSame( 0, $this->stock_of( $a ) );
		self::assertSame( 0, $this->stock_of( $b ) );
	}

	public function test_the_same_checkout_submitted_five_times_makes_one_order(): void {
		$id     = $this->product( 3 );
		$tokens = new MemoryTokenStore();
		$cart   = $this->cart( $tokens );
		$cart->cart()->add( 0, $id, 0, 1 );
		$this->checkout( $cart )->form_state( 0 );

		$results = $this->race(
			'checkout',
			5,
			fn ( int $i ): array => [ 'token' => (string) $tokens->get() ] + $this->buyer( $i, [] )
		);

		foreach ( $results as $result ) {
			self::assertTrue( $result['ok'], 'A repeated submit failed: ' . wp_json_encode( $result ) );
		}
		self::assertCount( 1, array_unique( array_column( $results, 'order' ) ) );
		self::assertCount( 4, array_filter( array_column( $results, 'duplicate' ) ) );
		self::assertSame( 2, $this->stock_of( $id ) );
	}

	public function test_a_webhook_delivered_ten_times_at_once_pays_once(): void {
		$id    = $this->product( 3 );
		$order = $this->pending_order( $id );
		$txns  = Plugin::instance()->payments()->service()->for_order( $order->id );
		self::assertCount( 1, $txns );
		$payload = [
			'id'     => 'evt_storm',
			'type'   => 'succeeded',
			'ref'    => (string) $txns[0]->gateway_reference,
			'amount' => $order->total,
		];

		$results = $this->race( 'webhook', 10, static fn (): array => [ 'payload' => $payload ] );

		self::assertSame( [ 200 ], array_values( array_unique( array_column( $results, 'code' ) ) ) );
		$fresh = $this->fresh_order( $order->id );
		self::assertSame( OrderStatus::PROCESSING, $fresh['status'] );
		self::assertSame( (string) $order->total, $fresh['amount_paid'] );
		self::assertSame( 1, $this->status_rows( $order->id, OrderStatus::PROCESSING ) );
		self::assertSame( 1, $this->history_rows( $order->id, 'payment' ) );
	}

	public function test_cron_expiry_and_customer_cancel_restore_stock_once(): void {
		global $wpdb;
		$id    = $this->product( 3 );
		$order = $this->pending_order( $id );
		self::assertSame( 2, $this->stock_of( $id ) );
		$wpdb->update( Schema::table( 'orders' ), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ], [ 'id' => $order->id ] ); // phpcs:ignore WordPress.DB

		$results = $this->race(
			'cancel',
			8,
			static fn ( int $i ): array => [
				'mode'  => $i % 2 === 0 ? 'expire' : 'cancel',
				'order' => $order->id,
			]
		);

		foreach ( $results as $result ) {
			self::assertTrue( $result['ok'], 'A cancel failed: ' . wp_json_encode( $result ) );
		}
		self::assertSame( OrderStatus::CANCELLED, $this->fresh_order( $order->id )['status'] );
		self::assertSame( 3, $this->stock_of( $id ), 'Stock was restored more than once.' );
		self::assertSame( 1, $this->status_rows( $order->id, OrderStatus::CANCELLED ) );
	}

	public function test_parallel_adds_to_one_cart_lose_no_update(): void {
		$id     = $this->product( null );
		$tokens = new MemoryTokenStore();
		$this->cart( $tokens )->cart()->add( 0, $id, 0, 1 );

		$results = $this->race(
			'add',
			8,
			static fn (): array => [
				'product' => $id,
				'token'   => (string) $tokens->get(),
			]
		);
		$added   = count( array_filter( $results, static fn ( array $r ): bool => $r['ok'] ) );
		self::assertGreaterThan( 0, $added );

		$fresh = $this->cart( new MemoryTokenStore( $tokens->get() ) );
		self::assertSame( 1 + $added, $fresh->cart()->state( 0 )->cart->item_count(), 'A concurrent cart write was lost.' );
	}

	/**
	 * Starts $count workers; $args gives each its JSON arguments (a `mode`
	 * key there overrides $mode for that worker).
	 *
	 * @param callable(int):array<string,mixed> $args
	 * @return list<array<string,mixed>>
	 */
	private function race( string $mode, int $count, callable $args ): array {
		// Enough time for every process to boot and set up before the start signal.
		$start     = sprintf( '%.6F', microtime( true ) + 2.0 + 0.15 * $count );
		$processes = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$worker  = $args( $i );
			$process = proc_open(
				[ PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/worker.php', (string) ( $worker['mode'] ?? $mode ), $start, (string) wp_json_encode( $worker ) ],
				[
					1 => [ 'pipe', 'w' ],
					2 => [ 'pipe', 'w' ],
				],
				$pipes
			);
			self::assertIsResource( $process );
			$processes[] = [ $process, $pipes ];
		}
		$results = [];
		foreach ( $processes as [ $process, $pipes ] ) {
			$out = (string) stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
			$line = json_decode( trim( (string) strrchr( "\n" . trim( $out ), "\n" ) ), true );
			self::assertIsArray( $line, "Worker produced no result.\nstdout: {$out}\nstderr: {$err}" );
			self::assertStringStartsNotWith( 'setup:', (string) ( $line['error'] ?? '' ), (string) ( $line['error'] ?? '' ) );
			$results[] = $line;
		}
		return $results;
	}

	/**
	 * Each buyer has its own IP so the checkout rate limit stays out of the way.
	 *
	 * @param list<int> $products
	 * @return array<string,mixed>
	 */
	private function buyer( int $i, array $products ): array {
		return [
			'products' => $products,
			'billing'  => self::BILLING,
			'rate'     => $this->rate_id,
			'ip'       => '198.51.100.' . ( $i + 1 ),
		];
	}

	/**
	 * @param list<array<string,mixed>> $results
	 * @return list<int> the distinct orders the workers placed
	 */
	private function orders_of( array $results ): array {
		return array_values( array_unique( array_column( array_filter( $results, static fn ( array $r ): bool => $r['ok'] ), 'order' ) ) );
	}

	/** An order paid by FakeGateway that waits for its webhook. */
	private function pending_order( int $product_id ): Order {
		$cart = $this->cart( new MemoryTokenStore() );
		$cart->cart()->add( 0, $product_id, 0, 1 );
		$checkout = $this->checkout( $cart );
		$result   = $checkout->place_order(
			CheckoutRequest::from_array(
				[
					'checkout_token' => $checkout->form_state( 0 )['checkout_token'],
					'billing'        => self::BILLING,
					'shipping_rate'  => $this->rate_id,
					'payment_method' => FakeGateway::ID,
				],
				0,
				'198.51.100.200'
			)
		);
		self::assertSame( OrderStatus::PENDING, $result->order->status );
		return $result->order;
	}

	private function cart( MemoryTokenStore $tokens ): CartServices {
		return new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), $tokens );
	}

	private function checkout( CartServices $cart ): CheckoutService {
		$plugin = Plugin::instance();
		return ( new CheckoutServices( $plugin->services(), $cart, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();
	}

	/** Straight from the table: this process still caches the product as it was. */
	private function stock_of( int $product_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT stock_quantity FROM ' . Schema::table( 'variants' ) . ' WHERE product_id = %d AND is_base = 1', $product_id ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<string,string> */
	private function fresh_order( int $order_id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT status, amount_paid FROM ' . Schema::table( 'orders' ) . ' WHERE id = %d', $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private function status_rows( int $order_id, string $to ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'order_history' ) . " WHERE order_id = %d AND type = 'status' AND to_status = %s", $order_id, $to ) ); // phpcs:ignore WordPress.DB
	}

	private function history_rows( int $order_id, string $type ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'order_history' ) . ' WHERE order_id = %d AND type = %s', $order_id, $type ) ); // phpcs:ignore WordPress.DB
	}

	private function product( ?int $stock ): int {
		$id            = (int) wp_insert_post(
			[
				'post_type'   => PostTypes::PRODUCT,
				'post_status' => 'publish',
				'post_title'  => 'Concurrency product',
			]
		);
		$this->posts[] = $id;
		$base          = [ 'regular_price' => 1000 ];
		if ( $stock !== null ) {
			$base += [
				'manage_stock'   => true,
				'stock_quantity' => $stock,
			];
		}
		Plugin::instance()->catalog()->product_service()->save( $id, ProductInput::from_array( [ 'base' => $base ] ) );
		return $id;
	}
}
