<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog;

use Flexa\Commerce\Catalog\Bundle;
use Flexa\Commerce\Catalog\BundleItem;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Tests\Unit\TestCase;

final class BundleTest extends TestCase {
	public function test_price_follows_the_mode(): void {
		self::assertSame( 3000, ( new Bundle( Bundle::SUM ) )->price_of( 3000 ) );
		self::assertSame( 2700, ( new Bundle( Bundle::DISCOUNT, 10 ) )->price_of( 3000 ) );
		// 999 less 15% is 849.15, rounded half up.
		self::assertSame( 849, ( new Bundle( Bundle::DISCOUNT, 15 ) )->price_of( 999 ) );
		self::assertSame( 850, ( new Bundle( Bundle::DISCOUNT, 50 ) )->price_of( 1699 ) );
		self::assertFalse( ( new Bundle() )->is_computed() );
		self::assertTrue( ( new Bundle( Bundle::SUM ) )->is_computed() );
	}

	public function test_meta_round_trips_and_drops_bad_rows(): void {
		$bundle = new Bundle( Bundle::DISCOUNT, 20, [ new BundleItem( 5, 50, 2 ), new BundleItem( 7, 71, 1 ) ] );
		self::assertEquals( $bundle, Bundle::from_meta( $bundle->to_meta() ) );
		self::assertSame( [ 50, 71 ], $bundle->variant_ids() );
		self::assertSame( [ 5, 7 ], $bundle->product_ids() );

		$read = Bundle::from_meta( '{"mode":"sum","discount":30,"items":[{"product_id":1,"variant_id":0},{"product_id":2,"variant_id":3,"quantity":5000}]}' );
		self::assertSame( Bundle::SUM, $read->mode );
		self::assertSame( 0, $read->discount );
		self::assertCount( 1, $read->items );
		self::assertSame( Bundle::MAX_QTY, $read->items[0]->quantity );
		self::assertEquals( new Bundle(), Bundle::from_meta( 'not json' ) );
		self::assertEquals( new Bundle(), Bundle::from_meta( '' ) );
	}

	public function test_input_is_checked(): void {
		$bundle = Bundle::from_input(
			[
				'mode'     => 'discount',
				'discount' => '15',
				'items'    => [
					[ 'product_id' => 4 ],
					[
						'variant_id' => 9,
						'quantity'   => 3,
					],
				],
			]
		);
		self::assertSame( 15, $bundle->discount );
		self::assertEquals( [ new BundleItem( 4, 0, 1 ), new BundleItem( 0, 9, 3 ) ], $bundle->items );

		foreach (
			[
				'bundle'                  => 'nope',
				'bundle.mode'             => [ 'mode' => 'free' ],
				'bundle.discount'         => [
					'mode'     => 'discount',
					'discount' => 100,
				],
				'bundle.items'            => [ 'items' => [ 'a' => 1 ] ],
				'bundle.items.0.quantity' => [
					'items' => [
						[
							'product_id' => 1,
							'quantity'   => 0,
						],
					],
				],
			] as $field => $raw
		) {
			try {
				Bundle::from_input( $raw );
				self::fail( 'Expected InvalidProduct for ' . $field );
			} catch ( InvalidProduct $e ) {
				self::assertSame( $field, $e->field, $field );
			}
		}
		$this->expectException( InvalidProduct::class );
		Bundle::from_input( [ 'items' => array_fill( 0, Bundle::MAX_ITEMS + 1, [ 'product_id' => 1 ] ) ] );
	}
}
