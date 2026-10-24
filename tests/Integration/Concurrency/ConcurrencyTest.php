<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Concurrency;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Buyers in parallel processes, each with its own database connection
 * (doc 07 phase 3 exit: the last-unit test). A plain TestCase, because the
 * workers must see committed rows.
 */
final class ConcurrencyTest extends TestCase {
	private const WORKERS = 8;

	/** @var list<int> */
	private array $posts = [];

	protected function setUp(): void {
		global $wpdb;
		if ( is_multisite() ) {
			self::markTestSkipped( 'The workers boot a single site.' );
		}
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'sessions' ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<string,array{int}> */
	public static function stock_levels(): array {
		return [
			'last unit'   => [ 1 ],
			'three units' => [ 3 ],
		];
	}

	/** @dataProvider stock_levels */
	public function test_parallel_buyers_never_oversell( int $stock ): void {
		$id      = $this->product( $stock );
		$results = $this->race( 'deduct', $id );

		self::assertSame( $stock, count( array_filter( $results, static fn ( array $r ): bool => $r['ok'] ) ), 'Wrong number of buyers got a unit.' );
		foreach ( array_filter( $results, static fn ( array $r ): bool => ! $r['ok'] ) as $failed ) {
			self::assertSame( 'Flexa\\Commerce\\Contracts\\Exceptions\\InsufficientStock', $failed['error'] );
		}
		// Straight from the table: this process still caches the product as it was.
		global $wpdb;
		$left = $wpdb->get_var( $wpdb->prepare( 'SELECT stock_quantity FROM ' . Schema::table( 'variants' ) . ' WHERE product_id = %d AND is_base = 1', $id ) ); // phpcs:ignore WordPress.DB
		self::assertSame( '0', $left );
	}

	public function test_parallel_adds_to_one_cart_lose_no_update(): void {
		$id       = $this->product( null );
		$tokens   = new MemoryTokenStore();
		$services = new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), $tokens );
		$services->cart()->add( 0, $id, 0, 1 );

		$results = $this->race( 'add', $id, (string) $tokens->get() );
		$added   = count( array_filter( $results, static fn ( array $r ): bool => $r['ok'] ) );
		self::assertGreaterThan( 0, $added );

		$fresh = new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), new MemoryTokenStore( $tokens->get() ) );
		self::assertSame( 1 + $added, $fresh->cart()->state( 0 )->cart->item_count(), 'A concurrent cart write was lost.' );
	}

	/** @return list<array{ok:bool,error?:string}> */
	private function race( string $mode, int $product_id, string $token = '' ): array {
		// Enough time for every process to boot before the start signal.
		$start     = sprintf( '%.6F', microtime( true ) + 2.0 );
		$processes = [];
		for ( $i = 0; $i < self::WORKERS; $i++ ) {
			$process = proc_open(
				[ PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/worker.php', $mode, (string) $product_id, $start, $token ],
				[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
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
			$results[] = $line;
		}
		return $results;
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
