<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog\Csv;

use Flexa\Commerce\Catalog\Csv\CsvColumns;
use Flexa\Commerce\Tests\Unit\TestCase;

final class CsvColumnsTest extends TestCase {
	public function test_match_reads_woocommerce_headers(): void {
		self::assertSame( 'sku', CsvColumns::match( "\xEF\xBB\xBFSKU" ) );
		self::assertSame( 'stock_status', CsvColumns::match( 'In stock?' ) );
		self::assertSame( 'regular_price', CsvColumns::match( 'regular_price' ) );
		self::assertSame( 'weight', CsvColumns::match( 'Weight (lbs)' ) );
		self::assertSame( 'attribute:2:value', CsvColumns::match( 'Attribute 2 value(s)' ) );
		self::assertSame( 'download:1:url', CsvColumns::match( 'Download 1 URL' ) );
		self::assertSame( 'meta:_custom key', CsvColumns::match( 'Meta: _custom key' ) );
		self::assertSame( 'unsupported:upsell_ids', CsvColumns::match( 'Upsells' ) );
		self::assertNull( CsvColumns::match( 'Attribute 0 name' ) );
		self::assertNull( CsvColumns::match( 'Something else' ) );
	}

	public function test_headers_round_trip_through_match(): void {
		$keys = array_merge( array_keys( CsvColumns::fixed() ), CsvColumns::attribute_keys( 3 ), CsvColumns::download_keys( 2 ), [ 'meta:color' ] );
		foreach ( $keys as $key ) {
			self::assertSame( $key, CsvColumns::match( CsvColumns::header( $key ) ), $key );
			self::assertTrue( CsvColumns::is_key( $key ), $key );
		}
	}

	public function test_is_key_rejects_unknown_and_unsupported(): void {
		self::assertFalse( CsvColumns::is_key( 'unsupported:upsell_ids' ) );
		self::assertFalse( CsvColumns::is_key( 'attribute:0:name' ) );
		self::assertFalse( CsvColumns::is_key( 'meta: spaced' ) );
		self::assertFalse( CsvColumns::is_key( 'post_author' ) );
	}

	public function test_unit_names_a_known_unit(): void {
		$factors = [
			'kg' => 1000.0,
			'lb' => 453.59237,
		];
		self::assertSame( 'lb', CsvColumns::unit( 'Weight (lbs)', $factors ) );
		self::assertSame( 'kg', CsvColumns::unit( 'Weight ( KG )', $factors ) );
		self::assertNull( CsvColumns::unit( 'Weight (st)', $factors ) );
		self::assertNull( CsvColumns::unit( 'Weight', $factors ) );
	}
}
