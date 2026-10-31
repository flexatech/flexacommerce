<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a TrueType font (glyf outlines) and writes a subset of it for a PDF
 * (ADR-060). Glyphs get new ids in the order the text first uses them, so
 * the content stream can be written before the subset is built; id 0 stays
 * .notdef. Parts of composite glyphs are added when the subset is written.
 * No shaping: one character is one glyph, without kerning or ligatures.
 */
final class TrueTypeFont {
	/** Tables a PDF viewer needs from an embedded TrueType font. */
	private const KEEP = [ 'cvt ', 'fpgm', 'prep' ];

	public readonly string $name;

	public readonly int $units_per_em;

	public readonly int $ascent;

	public readonly int $descent;

	public readonly int $cap_height;

	public readonly int $italic_angle;

	/** @var array{0:int,1:int,2:int,3:int} */
	public readonly array $bbox;

	/** @var array<string,array{offset:int,length:int}> */
	private array $tables = [];

	/** @var array<int,int> code point => glyph id */
	private array $cmap = [];

	/** @var list<int> advance width of each glyph, font units */
	private array $advances = [];

	/** @var list<int> */
	private array $lsb = [];

	private int $glyph_count;

	private bool $long_loca;

	/** @var array<int,int> original glyph id => subset id */
	private array $used = [ 0 => 0 ];

	/** @var array<int,int> subset id => code point, for ToUnicode */
	private array $unicode = [];

	public function __construct( private readonly string $data ) {
		if ( strlen( $data ) < 12 || ! in_array( substr( $data, 0, 4 ), [ "\x00\x01\x00\x00", 'true' ], true ) ) {
			throw new \InvalidArgumentException( 'Not a TrueType font.' );
		}
		$count = $this->u16( 4 );
		for ( $i = 0; $i < $count; $i++ ) {
			$at                                      = 12 + 16 * $i;
			$this->tables[ substr( $data, $at, 4 ) ] = [
				'offset' => $this->u32( $at + 8 ),
				'length' => $this->u32( $at + 12 ),
			];
		}
		foreach ( [ 'head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap' ] as $tag ) {
			if ( ! isset( $this->tables[ $tag ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'The font has no %s table.', $tag ) );
			}
		}

		$head               = $this->tables['head']['offset'];
		$this->units_per_em = max( 16, $this->u16( $head + 18 ) );
		$this->bbox         = [ $this->i16( $head + 36 ), $this->i16( $head + 38 ), $this->i16( $head + 40 ), $this->i16( $head + 42 ) ];
		$this->long_loca    = $this->i16( $head + 50 ) === 1;

		$hhea          = $this->tables['hhea']['offset'];
		$this->ascent  = $this->i16( $hhea + 4 );
		$this->descent = $this->i16( $hhea + 6 );
		$metrics       = $this->u16( $hhea + 34 );

		$this->glyph_count = $this->u16( $this->tables['maxp']['offset'] + 4 );

		$hmtx    = $this->tables['hmtx']['offset'];
		$advance = 0;
		for ( $g = 0; $g < $this->glyph_count; $g++ ) {
			if ( $g < $metrics ) {
				$advance     = $this->u16( $hmtx + 4 * $g );
				$this->lsb[] = $this->i16( $hmtx + 4 * $g + 2 );
			} else {
				$this->lsb[] = $this->i16( $hmtx + 4 * $metrics + 2 * ( $g - $metrics ) );
			}
			$this->advances[] = $advance;
		}

		$cap = 0;
		if ( isset( $this->tables['OS/2'] ) && $this->tables['OS/2']['length'] >= 90 && $this->u16( $this->tables['OS/2']['offset'] ) >= 2 ) {
			$cap = $this->i16( $this->tables['OS/2']['offset'] + 88 );
		}
		$this->cap_height   = $cap > 0 ? $cap : $this->ascent;
		$this->italic_angle = isset( $this->tables['post'] ) ? $this->i16( $this->tables['post']['offset'] + 4 ) : 0;
		$this->name         = $this->postscript_name();
		$this->read_cmap();
	}

	public static function from_file( string $path ): self {
		$data = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local font file.
		if ( $data === false ) {
			throw new \InvalidArgumentException( sprintf( 'Cannot read the font %s.', $path ) );
		}
		return new self( $data );
	}

	/**
	 * The subset ids of a UTF-8 string, assigning ids to glyphs not used
	 * before. A character the font lacks becomes .notdef (id 0).
	 *
	 * @return list<int>
	 */
	public function encode( string $text ): array {
		$out = [];
		foreach ( self::code_points( $text ) as $code ) {
			$glyph = $this->cmap[ $code ] ?? 0;
			if ( ! isset( $this->used[ $glyph ] ) ) {
				$this->used[ $glyph ] = count( $this->used );
			}
			$id = $this->used[ $glyph ];
			if ( $glyph !== 0 ) {
				$this->unicode[ $id ] ??= $code;
			}
			$out[] = $id;
		}
		return $out;
	}

	/** Width of a UTF-8 string in thousandths of the font size. */
	public function width( string $text ): float {
		$units = 0;
		foreach ( self::code_points( $text ) as $code ) {
			$units += $this->advances[ $this->cmap[ $code ] ?? 0 ] ?? 0;
		}
		return $units * 1000 / $this->units_per_em;
	}

	public function has( int $code ): bool {
		return isset( $this->cmap[ $code ] );
	}

	/** In 1/1000 of the font size, as PDF metrics are. */
	public function scale( int $units ): int {
		return (int) round( $units * 1000 / $this->units_per_em );
	}

	/** @return array<int,int> subset id => code point */
	public function unicode_map(): array {
		return $this->unicode;
	}

	/**
	 * Widths by subset id, in 1/1000 of the font size. Call after subset(),
	 * which may add the parts of composite glyphs.
	 *
	 * @return list<int>
	 */
	public function subset_widths(): array {
		$widths = [];
		foreach ( $this->used as $glyph => $id ) {
			$widths[ $id ] = $this->scale( $this->advances[ $glyph ] ?? 0 );
		}
		ksort( $widths );
		return array_values( $widths );
	}

	/** A six letter tag naming this subset, as PDF subset font names start. */
	public function subset_tag(): string {
		$hash = md5( $this->name . implode( ',', array_keys( $this->used ) ) );
		$tag  = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$tag .= chr( 65 + hexdec( $hash[ $i ] ) % 26 );
		}
		return $tag;
	}

	/** The font file with only the used glyphs, numbered by subset id. */
	public function subset(): string {
		// Composite glyphs need their parts; parts may be composite too.
		$queue = array_keys( $this->used );
		while ( $queue !== [] ) {
			$glyph = (int) array_shift( $queue );
			foreach ( $this->components( $glyph ) as $part ) {
				if ( ! isset( $this->used[ $part ] ) ) {
					$this->used[ $part ] = count( $this->used );
					$queue[]             = $part;
				}
			}
		}
		$order = array_flip( $this->used );
		ksort( $order );

		$glyf = '';
		$loca = '';
		$hmtx = '';
		foreach ( $order as $glyph ) {
			$loca .= pack( 'N', strlen( $glyf ) );
			$data  = $this->glyph_data( $glyph );
			if ( $data !== '' && self::s16( $data, 0 ) < 0 ) {
				$data = $this->renumber( $data );
			}
			$glyf .= $data . str_repeat( "\0", ( 4 - strlen( $data ) % 4 ) % 4 );
			$hmtx .= pack( 'nn', $this->advances[ $glyph ] ?? 0, ( $this->lsb[ $glyph ] ?? 0 ) & 0xFFFF );
		}
		$loca .= pack( 'N', strlen( $glyf ) );
		$count = count( $order );

		$head = $this->table( 'head' );
		$head = substr_replace( $head, "\0\0\0\0", 8, 4 );
		$head = substr_replace( $head, pack( 'n', 1 ), 50, 2 );
		$hhea = substr_replace( $this->table( 'hhea' ), pack( 'n', $count ), 34, 2 );
		$maxp = substr_replace( $this->table( 'maxp' ), pack( 'n', $count ), 4, 2 );
		$post = isset( $this->tables['post'] ) ? substr( $this->table( 'post' ), 0, 32 ) : str_repeat( "\0", 32 );
		$post = substr_replace( str_pad( $post, 32, "\0" ), "\x00\x03\x00\x00", 0, 4 );

		$tables = [
			'cmap' => $this->subset_cmap(),
			'glyf' => $glyf,
			'head' => $head,
			'hhea' => $hhea,
			'hmtx' => $hmtx,
			'loca' => $loca,
			'maxp' => $maxp,
			'post' => $post,
		];
		foreach ( self::KEEP as $tag ) {
			if ( isset( $this->tables[ $tag ] ) ) {
				$tables[ $tag ] = $this->table( $tag );
			}
		}
		ksort( $tables, SORT_STRING );
		$font = self::assemble( $tables );

		// head.checkSumAdjustment makes the whole file sum to 0xB1B0AFBA.
		$at = 12 + 16 * (int) array_search( 'head', array_keys( $tables ), true );
		$at = (int) unpack( 'N', substr( $font, $at + 8, 4 ) )[1];
		return substr_replace( $font, pack( 'N', ( 0xB1B0AFBA - self::checksum( $font ) ) & 0xFFFFFFFF ), $at + 8, 4 );
	}

	/** @param array<string,string> $tables sorted by tag */
	private static function assemble( array $tables ): string {
		$count  = count( $tables );
		$power  = 1;
		$levels = 0;
		while ( $power * 2 <= $count ) {
			$power *= 2;
			++$levels;
		}
		$out    = pack( 'Nnnnn', 0x00010000, $count, $power * 16, $levels, $count * 16 - $power * 16 );
		$offset = 12 + 16 * $count;
		$body   = '';
		foreach ( $tables as $tag => $data ) {
			$out  .= $tag . pack( 'NNN', self::checksum( $data ), $offset + strlen( $body ), strlen( $data ) );
			$body .= $data . str_repeat( "\0", ( 4 - strlen( $data ) % 4 ) % 4 );
		}
		return $out . $body;
	}

	private static function checksum( string $data ): int {
		$data .= str_repeat( "\0", ( 4 - strlen( $data ) % 4 ) % 4 );
		$sum   = 0;
		foreach ( str_split( $data, 4 ) as $word ) {
			$sum = ( $sum + (int) unpack( 'N', $word )[1] ) & 0xFFFFFFFF;
		}
		return $sum;
	}

	/** A format 4 cmap of the used characters in the Basic Multilingual Plane. */
	private function subset_cmap(): string {
		$map = [];
		foreach ( $this->unicode as $id => $code ) {
			if ( $code < 0xFFFF ) {
				$map[ $code ] = $id;
			}
		}
		ksort( $map );
		$map[0xFFFF] = 0;

		$segments = count( $map );
		$ends     = '';
		$starts   = '';
		$deltas   = '';
		foreach ( $map as $code => $id ) {
			$ends   .= pack( 'n', $code );
			$starts .= pack( 'n', $code );
			$deltas .= pack( 'n', $code === 0xFFFF ? 1 : ( $id - $code ) & 0xFFFF );
		}
		$power  = 1;
		$levels = 0;
		while ( $power * 2 <= $segments ) {
			$power *= 2;
			++$levels;
		}
		$body = $ends . "\0\0" . $starts . $deltas . str_repeat( "\0\0", $segments );
		$sub  = pack( 'nnnnnnn', 4, 14 + strlen( $body ), 0, $segments * 2, $power * 2, $levels, $segments * 2 - $power * 2 ) . $body;
		return pack( 'nnnnN', 0, 1, 3, 1, 12 ) . $sub;
	}

	/** @return list<int> the glyph ids a composite glyph is built from */
	private function components( int $glyph ): array {
		$data = $this->glyph_data( $glyph );
		if ( strlen( $data ) < 10 || self::s16( $data, 0 ) >= 0 ) {
			return [];
		}
		$parts = [];
		$at    = 10;
		$end   = strlen( $data );
		do {
			$flags   = self::n16( $data, $at );
			$parts[] = self::n16( $data, $at + 2 );
			$at     += 4 + self::arguments( $flags );
		} while ( ( $flags & 0x0020 ) !== 0 && $at + 4 <= $end );
		return $parts;
	}

	/** A composite glyph with its part ids changed to subset ids. */
	private function renumber( string $data ): string {
		$at  = 10;
		$end = strlen( $data ); // substr_replace() keeps the length.
		do {
			$flags = self::n16( $data, $at );
			$part  = self::n16( $data, $at + 2 );
			$data  = substr_replace( $data, pack( 'n', $this->used[ $part ] ?? 0 ), $at + 2, 2 );
			$at   += 4 + self::arguments( $flags );
		} while ( ( $flags & 0x0020 ) !== 0 && $at + 4 <= $end );
		return $data;
	}

	/** Bytes after the flags and glyph index of one component. */
	private static function arguments( int $flags ): int {
		$size = ( $flags & 0x0001 ) !== 0 ? 4 : 2;
		if ( ( $flags & 0x0008 ) !== 0 ) {
			$size += 2;
		} elseif ( ( $flags & 0x0040 ) !== 0 ) {
			$size += 4;
		} elseif ( ( $flags & 0x0080 ) !== 0 ) {
			$size += 8;
		}
		return $size;
	}

	private function glyph_data( int $glyph ): string {
		if ( $glyph < 0 || $glyph >= $this->glyph_count ) {
			return '';
		}
		$loca = $this->tables['loca']['offset'];
		if ( $this->long_loca ) {
			$start = $this->u32( $loca + 4 * $glyph );
			$end   = $this->u32( $loca + 4 * $glyph + 4 );
		} else {
			$start = $this->u16( $loca + 2 * $glyph ) * 2;
			$end   = $this->u16( $loca + 2 * $glyph + 2 ) * 2;
		}
		return $end > $start ? substr( $this->data, $this->tables['glyf']['offset'] + $start, $end - $start ) : '';
	}

	private function read_cmap(): void {
		$cmap  = $this->tables['cmap']['offset'];
		$count = $this->u16( $cmap + 2 );
		$best  = null;
		$rank  = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$platform = $this->u16( $cmap + 4 + 8 * $i );
			$encoding = $this->u16( $cmap + 6 + 8 * $i );
			$offset   = $cmap + $this->u32( $cmap + 8 + 8 * $i );
			$format   = $this->u16( $offset );
			$score    = match ( true ) {
				$format === 12 && ( $platform === 3 || $platform === 0 ) => 3,
				$format === 4 && $platform === 3 && in_array( $encoding, [ 1, 10 ], true ) => 2,
				$format === 4 && $platform === 0 => 1,
				default => 0,
			};
			if ( $score > $rank ) {
				$best = $offset;
				$rank = $score;
			}
		}
		if ( $best === null ) {
			throw new \InvalidArgumentException( 'The font has no Unicode cmap.' );
		}
		$this->u16( $best ) === 12 ? $this->read_format12( $best ) : $this->read_format4( $best );
	}

	private function read_format4( int $at ): void {
		$segments = intdiv( $this->u16( $at + 6 ), 2 );
		$ends     = $at + 14;
		$starts   = $ends + 2 * $segments + 2;
		$deltas   = $starts + 2 * $segments;
		$ranges   = $deltas + 2 * $segments;
		for ( $s = 0; $s < $segments; $s++ ) {
			$end   = $this->u16( $ends + 2 * $s );
			$start = $this->u16( $starts + 2 * $s );
			$delta = $this->u16( $deltas + 2 * $s );
			$range = $this->u16( $ranges + 2 * $s );
			for ( $code = $start; $code <= $end && $code !== 0xFFFF; $code++ ) {
				if ( $range === 0 ) {
					$glyph = ( $code + $delta ) & 0xFFFF;
				} else {
					$glyph = $this->u16( $ranges + 2 * $s + $range + 2 * ( $code - $start ) );
					$glyph = $glyph === 0 ? 0 : ( $glyph + $delta ) & 0xFFFF;
				}
				if ( $glyph !== 0 ) {
					$this->cmap[ $code ] = $glyph;
				}
			}
		}
	}

	private function read_format12( int $at ): void {
		$groups = $this->u32( $at + 12 );
		for ( $g = 0; $g < $groups; $g++ ) {
			$row   = $at + 16 + 12 * $g;
			$start = $this->u32( $row );
			$end   = min( $this->u32( $row + 4 ), $start + 0xFFFF );
			$glyph = $this->u32( $row + 8 );
			for ( $code = $start; $code <= $end; $code++ ) {
				$this->cmap[ $code ] = $glyph + $code - $start;
			}
		}
	}

	private function postscript_name(): string {
		if ( isset( $this->tables['name'] ) ) {
			$at      = $this->tables['name']['offset'];
			$count   = $this->u16( $at + 2 );
			$strings = $at + $this->u16( $at + 4 );
			for ( $i = 0; $i < $count; $i++ ) {
				$row = $at + 6 + 12 * $i;
				if ( $this->u16( $row + 6 ) !== 6 ) {
					continue;
				}
				$raw  = substr( $this->data, $strings + $this->u16( $row + 10 ), $this->u16( $row + 8 ) );
				$text = $this->u16( $row ) === 1 ? $raw : (string) mb_convert_encoding( $raw, 'UTF-8', 'UTF-16BE' );
				$text = (string) preg_replace( '/[^A-Za-z0-9-]/', '', $text );
				if ( $text !== '' ) {
					return $text;
				}
			}
		}
		return 'Font';
	}

	/** @return list<int> */
	private static function code_points( string $text ): array {
		if ( class_exists( \Normalizer::class ) ) {
			$text = (string) \Normalizer::normalize( $text, \Normalizer::FORM_C );
		}
		$codes = mb_str_split( $text, 1, 'UTF-8' );
		return array_map( static fn ( string $char ): int => (int) mb_ord( $char, 'UTF-8' ), $codes );
	}

	private function table( string $tag ): string {
		return substr( $this->data, $this->tables[ $tag ]['offset'], $this->tables[ $tag ]['length'] );
	}

	private function u16( int $at ): int {
		return self::n16( $this->data, $at );
	}

	private function i16( int $at ): int {
		return self::s16( $this->data, $at );
	}

	private function u32( int $at ): int {
		return (int) unpack( 'N', substr( $this->data, $at, 4 ) . "\0\0\0\0" )[1];
	}

	private static function n16( string $data, int $at ): int {
		return ( ord( $data[ $at ] ?? "\0" ) << 8 ) | ord( $data[ $at + 1 ] ?? "\0" );
	}

	private static function s16( string $data, int $at ): int {
		$value = self::n16( $data, $at );
		return $value >= 0x8000 ? $value - 0x10000 : $value;
	}
}
