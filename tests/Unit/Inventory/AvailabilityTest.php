<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Inventory;

use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Inventory\Availability;
use Flexa\Commerce\Tests\Unit\TestCase;

final class AvailabilityTest extends TestCase {
	public function test_unmanaged_stock_uses_the_stored_status(): void {
		$a = Availability::of( Variant::base_for( 1 ) );
		self::assertSame( Variant::INSTOCK, $a->status );
		self::assertNull( $a->quantity );
		self::assertTrue( $a->allows( 1000 ) );
		self::assertNull( $a->max_quantity() );

		$out = Availability::of( Variant::base_for( 1 )->with( [ 'stock_status' => Variant::OUTOFSTOCK ] ) );
		self::assertFalse( $out->allows( 1 ) );
		self::assertSame( 0, $out->max_quantity() );
		self::assertTrue( $out->allows( 0 ) );
	}

	public function test_managed_stock_limits_the_quantity(): void {
		$a = Availability::of(
			Variant::base_for( 1 )->with(
				[
					'manage_stock'   => true,
					'stock_quantity' => 2,
				]
			)
		);
		self::assertTrue( $a->allows( 2 ) );
		self::assertFalse( $a->allows( 3 ) );
		self::assertSame( 2, $a->max_quantity() );
	}

	public function test_backorders_lift_the_limit(): void {
		$a = Availability::of(
			Variant::base_for( 1 )->with(
				[
					'manage_stock'   => true,
					'stock_quantity' => 0,
					'backorders'     => 'notify',
				]
			)
		);
		self::assertSame( Variant::ONBACKORDER, $a->status );
		self::assertTrue( $a->is_in_stock() );
		self::assertTrue( $a->allows( 5 ) );
		self::assertNull( $a->max_quantity() );
	}

	public function test_zero_managed_stock_without_backorders_is_out_of_stock(): void {
		$a = Availability::from_stock_row(
			[
				'manage_stock'   => true,
				'stock_quantity' => -1,
				'backorders'     => 'no',
				'stock_status'   => Variant::INSTOCK,
			]
		);
		self::assertSame( Variant::OUTOFSTOCK, $a->status );
		self::assertFalse( $a->is_in_stock() );
		self::assertSame( 0, $a->max_quantity() );
	}
}
