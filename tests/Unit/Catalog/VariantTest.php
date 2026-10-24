<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog;

use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Tests\Unit\TestCase;

final class VariantTest extends TestCase {
	private const NOW = '2026-10-24 12:00:00';

	public function test_active_price_follows_the_sale_window(): void {
		$v = Variant::base_for( 1 )->with(
			[
				'regular_price' => 1000,
				'sale_price'    => 800,
			]
		);
		self::assertSame( 800, $v->active_price( self::NOW ) );
		self::assertSame( 1000, $v->with( [ 'sale_from' => '2026-10-25 00:00:00' ] )->active_price( self::NOW ) );
		self::assertSame( 1000, $v->with( [ 'sale_to' => '2026-10-24 12:00:00' ] )->active_price( self::NOW ) );
		self::assertSame( 1000, $v->with( [ 'sale_price' => 1000 ] )->active_price( self::NOW ) );
		self::assertNull( $v->with( [ 'regular_price' => null ] )->active_price( self::NOW ) );
	}

	public function test_next_price_change(): void {
		$v = Variant::base_for( 1 )->with(
			[
				'regular_price' => 1000,
				'sale_price'    => 800,
				'sale_from'     => '2026-10-20 00:00:00',
				'sale_to'       => '2026-11-01 00:00:00',
			]
		);
		self::assertSame( '2026-11-01 00:00:00', $v->next_price_change( self::NOW ) );
		self::assertNull( $v->next_price_change( '2026-12-01 00:00:00' ) );
	}

	public function test_computed_stock_status(): void {
		$v = Variant::base_for( 1 )->with(
			[
				'manage_stock'   => true,
				'stock_quantity' => 0,
			]
		);
		self::assertSame( Variant::OUTOFSTOCK, $v->computed_stock_status() );
		self::assertSame( Variant::ONBACKORDER, $v->with( [ 'backorders' => 'notify' ] )->computed_stock_status() );
		self::assertSame( Variant::INSTOCK, $v->with( [ 'stock_quantity' => 3 ] )->computed_stock_status() );
		self::assertSame( Variant::OUTOFSTOCK, $v->with( [ 'manage_stock' => false, 'stock_status' => Variant::OUTOFSTOCK ] )->computed_stock_status() );
	}

	public function test_row_round_trip(): void {
		$v   = Variant::base_for( 4 )->with(
			[
				'sku'           => 'ABC',
				'regular_price' => 1999,
				'weight'        => '250.000',
				'meta'          => [ 'x' => 1 ],
			]
		);
		$row = $v->to_row() + [
			'id'         => 9,
			'created_at' => 'a',
			'updated_at' => 'b',
		];
		$back = Variant::from_row( $row );
		self::assertSame( 'ABC', $back->sku );
		self::assertSame( 1999, $back->regular_price );
		self::assertSame( '250.000', $back->weight );
		self::assertSame( [ 'x' => 1 ], $back->meta );
		self::assertTrue( $back->is_base );
		self::assertSame( sha1( '' ), $row['combination_hash'] );
	}
}
