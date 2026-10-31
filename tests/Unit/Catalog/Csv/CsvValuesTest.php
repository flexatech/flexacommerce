<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog\Csv;

use Flexa\Commerce\Catalog\Csv\CsvValues;
use Flexa\Commerce\Tests\Unit\TestCase;

final class CsvValuesTest extends TestCase {
	public function test_formula_guard_round_trips(): void {
		self::assertSame( "'=SUM(A1)", CsvValues::escape( '=SUM(A1)' ) );
		self::assertSame( "'-5", CsvValues::escape( '-5' ) );
		self::assertSame( 'plain', CsvValues::escape( 'plain' ) );
		self::assertSame( '=SUM(A1)', CsvValues::unescape( "'=SUM(A1)" ) );
		self::assertSame( "'quoted", CsvValues::unescape( "'quoted" ) );
	}

	public function test_lists_escape_commas(): void {
		$joined = CsvValues::join( [ 'Red, dark', 'Blue' ] );

		self::assertSame( 'Red\\, dark, Blue', $joined );
		self::assertSame( [ 'Red, dark', 'Blue' ], CsvValues::split( $joined ) );
		self::assertSame( [ 'a', 'b' ], CsvValues::split( ' a ,, b ' ) );
		self::assertSame( [], CsvValues::split( '  ' ) );
	}

	public function test_decimal_reads_both_separators(): void {
		self::assertSame( '19.99', CsvValues::decimal( '19.99' ) );
		self::assertSame( '19.99', CsvValues::decimal( '19,99' ) );
		self::assertSame( '1299.50', CsvValues::decimal( '1.299,50' ) );
		self::assertSame( '1299.50', CsvValues::decimal( '1,299.50' ) );
		self::assertSame( '0.5', CsvValues::decimal( '.5' ) );
		self::assertNull( CsvValues::decimal( ' ' ) );
	}

	public function test_decimal_rejects_text(): void {
		$this->expectException( \InvalidArgumentException::class );
		CsvValues::decimal( 'ten' );
	}

	public function test_bool_and_number(): void {
		self::assertTrue( CsvValues::bool( 'Yes' ) );
		self::assertTrue( CsvValues::bool( '1' ) );
		self::assertFalse( CsvValues::bool( 'no' ) );
		self::assertSame( '1.5', CsvValues::number( 1.5 ) );
		self::assertSame( '2', CsvValues::number( 2.0 ) );
		self::assertSame( '0', CsvValues::number( -0.0000001 ) );
	}

	public function test_line_quotes_only_when_needed(): void {
		self::assertSame( "a,\"b,c\",\"say \"\"hi\"\"\",\" pad\"\n", CsvValues::line( [ 'a', 'b,c', 'say "hi"', ' pad' ] ) );
		self::assertSame( 'text', CsvValues::strip_bom( "\xEF\xBB\xBFtext" ) );
	}
}
