<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog;

use Flexa\Commerce\Catalog\ProductTypes;
use Flexa\Commerce\Tests\Unit\TestCase;

final class ProductTypesTest extends TestCase {
	public function test_simple_variable_and_bundle_products_are_sold(): void {
		self::assertSame( [ 'simple', 'variable', 'grouped', 'external', 'bundle' ], ProductTypes::all() );
		foreach ( ProductTypes::all() as $type ) {
			self::assertTrue( ProductTypes::is_valid( $type ) );
			self::assertSame( in_array( $type, [ ProductTypes::SIMPLE, ProductTypes::VARIABLE, ProductTypes::BUNDLE ], true ), ProductTypes::is_sold( $type ), $type );
			self::assertSame( in_array( $type, [ ProductTypes::SIMPLE, ProductTypes::VARIABLE ], true ), ProductTypes::has_stock( $type ), $type );
			self::assertSame( $type === ProductTypes::VARIABLE, ProductTypes::has_variations( $type ), $type );
		}
		self::assertFalse( ProductTypes::is_valid( 'subscription' ) );
		self::assertFalse( ProductTypes::is_sold( 'subscription' ) );
	}
}
