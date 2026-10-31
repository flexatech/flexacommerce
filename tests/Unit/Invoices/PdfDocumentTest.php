<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Invoices;

use Flexa\Commerce\Invoices\Pdf\PdfDocument;
use Flexa\Commerce\Invoices\Pdf\TrueTypeFont;
use Flexa\Commerce\Tests\Unit\TestCase;

/** The PDF writer and font subsetter behind invoices (ADR-060). */
final class PdfDocumentTest extends TestCase {
	private const FONT = __DIR__ . '/../../../assets/fonts/NotoSans-Regular.ttf';

	public function test_text_gets_subset_ids_in_first_use_order(): void {
		$font = TrueTypeFont::from_file( self::FONT );
		// Id 0 stays .notdef, which a character the font lacks maps to.
		self::assertSame( [ 1, 2, 2, 3 ], $font->encode( 'abbc' ) );
		self::assertSame( [ 3, 4 ], $font->encode( 'cé' ) );
		self::assertSame( [ 0 ], $font->encode( "\u{E000}" ) );
		self::assertSame( [ ord( 'a' ), ord( 'b' ), ord( 'c' ), 0xE9 ], array_values( $font->unicode_map() ) );
		self::assertTrue( $font->has( 0x0416 ), 'Cyrillic is covered.' );
		self::assertGreaterThan( $font->width( 'i' ), $font->width( 'W' ) );
	}

	public function test_the_subset_is_a_smaller_font_that_still_parses(): void {
		$full = (string) file_get_contents( self::FONT ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$font = new TrueTypeFont( $full );
		$font->encode( 'Invoice 0123 $€' );
		$subset = $font->subset();
		self::assertLessThan( strlen( $full ) / 10, strlen( $subset ) );

		new TrueTypeFont( $subset ); // Throws when the tables are broken.
		$widths = $font->subset_widths();
		self::assertGreaterThanOrEqual( 15, count( $widths ) );
		self::assertGreaterThan( 0, $widths[1], 'The width of "I".' );
		self::assertMatchesRegularExpression( '/^[A-Z]{6}$/', $font->subset_tag() );
	}

	public function test_long_text_wraps_at_spaces_and_inside_long_words(): void {
		$pdf = $this->pdf();
		self::assertSame( [ 'one two', 'three' ], $pdf->wrap( 'one two three', 'regular', 10, $pdf->measure( 'one two', 'regular', 10 ) + 1 ) );
		self::assertSame( [ 'a', 'b' ], $pdf->wrap( "a\nb", 'regular', 10, 500 ) );
		$long = $pdf->wrap( str_repeat( 'x', 40 ), 'regular', 10, $pdf->measure( 'xxxxxxxxxx', 'regular', 10 ) );
		self::assertSame( [ 'xxxxxxxxxx', 'xxxxxxxxxx', 'xxxxxxxxxx', 'xxxxxxxxxx' ], $long );
	}

	public function test_the_output_has_a_valid_cross_reference_table(): void {
		$pdf = $this->pdf();
		$pdf->add_page();
		$pdf->text( 50, 50, 'Hello Привет', 'regular', 12 );
		$pdf->add_page();
		$pdf->text( 50, 50, 'Page two', 'regular', 12, [ 0.2, 0.2, 0.2 ], 'right' );
		$bytes = $pdf->output( 'Test' );

		self::assertStringStartsWith( '%PDF-', $bytes );
		self::assertStringEndsWith( "%%EOF\n", $bytes );
		self::assertStringContainsString( '/Count 2', $bytes );
		self::assertSame( 1, preg_match( '/startxref\s+(\d+)/', $bytes, $start ) );
		self::assertSame( 'xref', substr( $bytes, (int) $start[1], 4 ) );
		self::assertSame( 1, preg_match( '/xref\s+0 (\d+)\s+((?:\d{10} \d{5} [nf]\s*\r?\n)+)/', $bytes, $table ) );
		preg_match_all( '/(\d{10}) \d{5} n/', $table[2], $offsets );
		self::assertCount( (int) $table[1] - 1, $offsets[1] );
		foreach ( $offsets[1] as $i => $offset ) {
			self::assertStringStartsWith( ( $i + 1 ) . ' 0 obj', substr( $bytes, (int) $offset, 12 ) );
		}
	}

	public function test_an_unknown_font_or_page_is_an_error(): void {
		$pdf = $this->pdf();
		$this->expectException( \OutOfRangeException::class );
		$pdf->page( 3 );
	}

	private function pdf(): PdfDocument {
		$pdf = new PdfDocument( 'letter' );
		$pdf->add_font( 'regular', TrueTypeFont::from_file( self::FONT ) );
		return $pdf;
	}
}
