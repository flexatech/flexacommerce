<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * A small PDF writer for invoices and packing slips (ADR-060): pages of
 * text, lines, filled rectangles and images, with TrueType fonts embedded
 * as subsets (Type0, Identity-H, with a ToUnicode map so text can be
 * searched and copied). Positions are in points from the top left corner;
 * text is placed by its baseline.
 */
final class PdfDocument {
	/** Width and height in points. */
	public const SIZES = [
		'a4'     => [ 595.28, 841.89 ],
		'letter' => [ 612.0, 792.0 ],
	];

	public readonly float $width;

	public readonly float $height;

	/** @var list<string> content stream of each page */
	private array $pages = [];

	private int $current = -1;

	/** @var array<string,TrueTypeFont> */
	private array $fonts = [];

	/** @var array<int,array{name:string,image:PdfImage}> by object id of the image */
	private array $images = [];

	public function __construct( string $size = 'a4' ) {
		[ $this->width, $this->height ] = self::SIZES[ $size ] ?? self::SIZES['a4'];
	}

	public function add_font( string $key, TrueTypeFont $font ): void {
		$this->fonts[ $key ] = $font;
	}

	public function font( string $key ): TrueTypeFont {
		if ( ! isset( $this->fonts[ $key ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown font %s.', $key ) );
		}
		return $this->fonts[ $key ];
	}

	/** Adds a page and makes it current; returns its index. */
	public function add_page(): int {
		$this->pages[] = '';
		$this->current = count( $this->pages ) - 1;
		return $this->current;
	}

	/** Makes an earlier page current, to add a footer once the page count is known. */
	public function page( int $index ): void {
		if ( ! isset( $this->pages[ $index ] ) ) {
			throw new \OutOfRangeException( 'No such page.' );
		}
		$this->current = $index;
	}

	public function page_count(): int {
		return count( $this->pages );
	}

	/** Width of a string in points. */
	public function measure( string $text, string $font, float $size ): float {
		return $this->font( $font )->width( $text ) * $size / 1000;
	}

	/**
	 * Lines no wider than `$max`, breaking at spaces and at the line breaks
	 * of the text. A word wider than the line is broken between characters.
	 *
	 * @return list<string>
	 */
	public function wrap( string $text, string $font, float $size, float $max ): array {
		$lines = [];
		foreach ( preg_split( '/\R/u', $text ) ?: [] as $paragraph ) {
			$line = '';
			foreach ( preg_split( '/[ \t]+/u', trim( (string) $paragraph ) ) ?: [] as $word ) {
				$try = $line === '' ? $word : $line . ' ' . $word;
				if ( $this->measure( $try, $font, $size ) <= $max ) {
					$line = $try;
					continue;
				}
				if ( $line !== '' ) {
					$lines[] = $line;
				}
				$line = '';
				foreach ( mb_str_split( $word, 1, 'UTF-8' ) as $char ) {
					if ( $line !== '' && $this->measure( $line . $char, $font, $size ) > $max ) {
						$lines[] = $line;
						$line    = '';
					}
					$line .= $char;
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * @param array{0:float,1:float,2:float} $color RGB, 0 to 1
	 * @param string                         $align left, right or center of `$x`
	 */
	public function text( float $x, float $y, string $text, string $font, float $size, array $color = [ 0.0, 0.0, 0.0 ], string $align = 'left' ): void {
		if ( $text === '' ) {
			return;
		}
		if ( $align !== 'left' ) {
			$width = $this->measure( $text, $font, $size );
			$x    -= $align === 'right' ? $width : $width / 2;
		}
		$hex = '';
		foreach ( $this->font( $font )->encode( $text ) as $id ) {
			$hex .= sprintf( '%04X', $id );
		}
		$this->out(
			sprintf(
				'%s rg BT /F%d %s Tf 1 0 0 1 %s %s Tm <%s> Tj ET',
				self::color( $color ),
				$this->font_number( $font ),
				self::num( $size ),
				self::num( $x ),
				self::num( $this->height - $y ),
				$hex
			)
		);
	}

	/** @param array{0:float,1:float,2:float} $color */
	public function line( float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $color = [ 0.0, 0.0, 0.0 ] ): void {
		$this->out(
			sprintf(
				'%s RG %s w %s %s m %s %s l S',
				self::color( $color ),
				self::num( $width ),
				self::num( $x1 ),
				self::num( $this->height - $y1 ),
				self::num( $x2 ),
				self::num( $this->height - $y2 )
			)
		);
	}

	/** @param array{0:float,1:float,2:float} $color */
	public function rect( float $x, float $y, float $w, float $h, array $color ): void {
		$this->out( sprintf( '%s rg %s %s %s %s re f', self::color( $color ), self::num( $x ), self::num( $this->height - $y - $h ), self::num( $w ), self::num( $h ) ) );
	}

	public function image( PdfImage $image, float $x, float $y, float $w, float $h ): void {
		$id                    = spl_object_id( $image );
		$this->images[ $id ] ??= [
			'name'  => 'Im' . ( count( $this->images ) + 1 ),
			'image' => $image,
		];
		$this->out( sprintf( 'q %s 0 0 %s %s %s cm /%s Do Q', self::num( $w ), self::num( $h ), self::num( $x ), self::num( $this->height - $y - $h ), $this->images[ $id ]['name'] ) );
	}

	/** The finished file. */
	public function output( string $title = '' ): string {
		if ( $this->pages === [] ) {
			$this->add_page();
		}
		/** @var list<string> $objects body of object n at index n-1 */
		$objects   = [];
		$reserve   = static function () use ( &$objects ): int {
			$objects[] = '';
			return count( $objects );
		};
		$catalog   = $reserve();
		$tree      = $reserve();
		$resources = $reserve();

		$fonts = [];
		foreach ( array_keys( $this->fonts ) as $n => $key ) {
			$fonts[] = '/F' . ( $n + 1 ) . ' ' . $this->write_font( $this->fonts[ $key ], $objects ) . ' 0 R';
		}
		$xobjects = [];
		foreach ( $this->images as $entry ) {
			$xobjects[] = '/' . $entry['name'] . ' ' . self::write_image( $entry['image'], $objects ) . ' 0 R';
		}
		$objects[ $resources - 1 ] = '<< /ProcSet [/PDF /Text /ImageB /ImageC /ImageI] /Font << ' . implode( ' ', $fonts ) . ' >>'
			. ( $xobjects !== [] ? ' /XObject << ' . implode( ' ', $xobjects ) . ' >>' : '' ) . ' >>';

		$kids = [];
		foreach ( $this->pages as $content ) {
			$stream    = self::stream( $content, $objects );
			$objects[] = sprintf(
				'<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources %d 0 R /Contents %d 0 R >>',
				$tree,
				self::num( $this->width ),
				self::num( $this->height ),
				$resources,
				$stream
			);
			$kids[]    = count( $objects ) . ' 0 R';
		}
		$objects[ $tree - 1 ]    = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		$objects[ $catalog - 1 ] = '<< /Type /Catalog /Pages ' . $tree . ' 0 R >>';
		$objects[]               = '<< /Producer ' . self::text_string( 'FlexaCommerce' ) . ( $title !== '' ? ' /Title ' . self::text_string( $title ) : '' )
			. ' /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';
		$info                    = count( $objects );

		$pdf     = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ( $objects as $n => $body ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $n + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$id   = md5( $pdf );
		$pdf .= 'trailer' . "\n<< /Size " . ( count( $objects ) + 1 ) . ' /Root ' . $catalog . ' 0 R /Info ' . $info . ' 0 R /ID [<' . $id . '> <' . $id . ">] >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	/**
	 * Writes the five objects of a Type0 font and returns the id of the top one.
	 *
	 * @param list<string> $objects
	 */
	private function write_font( TrueTypeFont $font, array &$objects ): int {
		$file   = $font->subset();
		$name   = $font->subset_tag() . '+' . $font->name;
		$widths = $font->subset_widths();

		$compressed = (string) gzcompress( $file );
		$objects[]  = '<< /Length ' . strlen( $compressed ) . ' /Length1 ' . strlen( $file ) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream";
		$font_file  = count( $objects );

		$bbox       = array_map( [ $font, 'scale' ], $font->bbox );
		$flags      = 32 | ( $font->italic_angle !== 0 ? 64 : 0 );
		$objects[]  = sprintf(
			'<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%s] /ItalicAngle %d /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
			$name,
			$flags,
			implode( ' ', $bbox ),
			$font->italic_angle,
			$font->scale( $font->ascent ),
			$font->scale( $font->descent ),
			$font->scale( $font->cap_height ),
			$font_file
		);
		$descriptor = count( $objects );

		$objects[] = sprintf(
			'<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 1000 /W [0 [%s]] /CIDToGIDMap /Identity >>',
			$name,
			$descriptor,
			implode( ' ', $widths )
		);
		$cid       = count( $objects );

		$to_unicode = self::stream( self::to_unicode( $font->unicode_map() ), $objects );

		$objects[] = sprintf( '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>', $name, $cid, $to_unicode );
		return count( $objects );
	}

	/** @param array<int,int> $map subset id => code point */
	private static function to_unicode( array $map ): string {
		$cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
			. "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
			. "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
			. "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
		foreach ( array_chunk( $map, 100, true ) as $chunk ) {
			$cmap .= count( $chunk ) . " beginbfchar\n";
			foreach ( $chunk as $id => $code ) {
				$cmap .= sprintf( '<%04X> <%s>', $id, strtoupper( bin2hex( (string) mb_convert_encoding( (string) mb_chr( $code, 'UTF-8' ), 'UTF-16BE', 'UTF-8' ) ) ) ) . "\n";
			}
			$cmap .= "endbfchar\n";
		}
		return $cmap . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
	}

	/** @param list<string> $objects */
	private static function write_image( PdfImage $image, array &$objects ): int {
		$mask = null;
		if ( $image->mask !== null ) {
			$objects[] = sprintf(
				"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns %d >> /Length %d >>\nstream\n%s\nendstream",
				$image->width,
				$image->height,
				$image->width,
				strlen( $image->mask ),
				$image->mask
			);
			$mask      = count( $objects );
		}
		$dict = '';
		foreach ( $image->dict as $key => $value ) {
			$dict .= ' /' . $key . ' ' . $value;
		}
		$objects[] = sprintf(
			"<< /Type /XObject /Subtype /Image /Width %d /Height %d%s%s /Length %d >>\nstream\n%s\nendstream",
			$image->width,
			$image->height,
			$dict,
			$mask !== null ? ' /SMask ' . $mask . ' 0 R' : '',
			strlen( $image->data ),
			$image->data
		);
		return count( $objects );
	}

	/** @param list<string> $objects */
	private static function stream( string $content, array &$objects ): int {
		$data      = (string) gzcompress( $content );
		$objects[] = '<< /Length ' . strlen( $data ) . " /Filter /FlateDecode >>\nstream\n" . $data . "\nendstream";
		return count( $objects );
	}

	private function font_number( string $key ): int {
		$index = array_search( $key, array_keys( $this->fonts ), true );
		if ( $index === false ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown font %s.', $key ) );
		}
		return $index + 1;
	}

	private function out( string $operators ): void {
		if ( $this->current < 0 ) {
			$this->add_page();
		}
		$this->pages[ $this->current ] .= $operators . "\n";
	}

	/** A text string for the document information: UTF-16BE with a byte order mark. */
	private static function text_string( string $text ): string {
		return '<FEFF' . strtoupper( bin2hex( (string) mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' ) ) ) . '>';
	}

	/** @param array{0:float,1:float,2:float} $color */
	private static function color( array $color ): string {
		return self::num( $color[0] ) . ' ' . self::num( $color[1] ) . ' ' . self::num( $color[2] );
	}

	private static function num( float $value ): string {
		$text = rtrim( rtrim( sprintf( '%.3F', $value ), '0' ), '.' );
		return $text === '-0' ? '0' : $text;
	}
}
