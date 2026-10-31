<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog;

use Flexa\Commerce\Catalog\ProductTypes;
use Flexa\Commerce\Tests\Unit\TestCase;

final class ProductTypesTest extends TestCase {
	public function test_only_simple_and_variable_products_are_sold(): void {
		self::assertSame( [ 'simple', 'variable', 'grouped', 'external' ], ProductTypes::all() );
		foreach ( ProductTypes::all() as $type ) {
			self::assertTrue( ProductTypes::is_valid( $type ) );
			$sold = in_array( $type, [ ProductTypes::SIMPLE, ProductTypes::VARIABLE ], true );
			self::assertSame( $sold, ProductTypes::is_sold( $type ), $type );
			self::assertSame( $sold, ProductTypes::has_stock( $type ), $type );
			self::assertSame( $type === ProductTypes::VARIABLE, ProductTypes::has_variations( $type ), $type );
		}
		self::assertFalse( ProductTypes::is_valid( 'bundle' ) );
		self::assertFalse( ProductTypes::is_sold( 'bundle' ) );
	}
}
