<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Inventory\StockLine;
use Flexa\Commerce\Plugin;

final class StockServiceTest extends CartTestCase {
	private function line( int $product_id, int $quantity ): StockLine {
		$product = $this->service()->get( $product_id );
		return new StockLine( $product, $product->base, $quantity );
	}

	public function test_the_last_unit_is_sold_once(): void {
		$id    = $this->simple( 1000, 1 );
		$stock = Plugin::instance()->cart()->stock();

		$changes = $stock->deduct( [ $this->line( $id, 1 ) ], 'test' );
		self::assertSame( 0, $changes[0]->new_quantity );

		// A second buyer read the product before the first one paid.
		try {
			$stock->deduct( [ $this->line( $id, 1 ) ], 'test' );
			self::fail( 'The last unit was sold twice.' );
		} catch ( InsufficientStock $e ) {
			self::assertSame( 0, $e->available );
		}
		$fresh = $this->service()->get( $id )->base;
		self::assertSame( 0, $fresh->stock_quantity );
		self::assertSame( Variant::OUTOFSTOCK, $fresh->stock_status );
	}

	public function test_deduct_is_all_or_nothing(): void {
		$a     = $this->simple( 1000, 5 );
		$b     = $this->simple( 1000, 1 );
		$stock = Plugin::instance()->cart()->stock();
		try {
			$stock->deduct( [ $this->line( $a, 2 ), $this->line( $b, 2 ) ], 'test' );
			self::fail( 'Expected InsufficientStock.' );
		} catch ( InsufficientStock ) {
			// Expected.
		}
		self::assertSame( 5, $this->service()->get( $a )->base->stock_quantity );
		self::assertSame( 1, $this->service()->get( $b )->base->stock_quantity );
	}

	public function test_release_and_adjust(): void {
		$id    = $this->simple( 1000, 2 );
		$stock = Plugin::instance()->cart()->stock();
		$stock->deduct( [ $this->line( $id, 2 ) ], 'test' );
		$stock->release( [ $this->line( $id, 1 ) ], 'cancel' );
		self::assertSame( 1, $this->service()->get( $id )->base->stock_quantity );

		$product = $this->service()->get( $id );
		self::assertSame( 4, $stock->adjust( $product, $product->base, 3, 'count' ) );
		self::assertNull( $stock->adjust( $this->service()->get( $this->simple() ), $this->service()->get( $this->simple() )->base, 3, 'count' ) );
	}

	public function test_backorders_go_below_zero(): void {
		$id = $this->product();
		$this->save(
			$id,
			[
				'base' => [
					'regular_price'  => 1000,
					'manage_stock'   => true,
					'stock_quantity' => 1,
					'backorders'     => 'notify',
				],
			]
		);
		Plugin::instance()->cart()->stock()->deduct( [ $this->line( $id, 3 ) ], 'test' );
		$base = $this->service()->get( $id )->base;
		self::assertSame( -2, $base->stock_quantity );
		self::assertSame( Variant::ONBACKORDER, $base->stock_status );
	}

	public function test_low_and_out_events_and_mail(): void {
		$id     = $this->simple( 1000, 3 );
		$events = [];
		add_action( 'flexacommerce.stock.low', static function ( int $variant ) use ( &$events ): void {
			$events[] = 'low';
		} );
		add_action( 'flexacommerce.stock.out', static function () use ( &$events ): void {
			$events[] = 'out';
		} );
		reset_phpmailer_instance();
		$stock = Plugin::instance()->cart()->stock();
		$stock->deduct( [ $this->line( $id, 1 ) ], 'test' );
		$stock->deduct( [ $this->line( $id, 2 ) ], 'test' );

		self::assertSame( [ 'low', 'out' ], $events );
		$mailer = tests_retrieve_phpmailer_instance();
		self::assertStringContainsString( 'Out of stock', (string) $mailer->get_sent( 1 )->subject );
	}
}
