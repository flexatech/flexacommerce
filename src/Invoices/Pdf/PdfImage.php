<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * An image ready for a PDF (ADR-060). JPEG data goes in as it is; an 8-bit
 * PNG without interlacing goes in with its compressed rows, the alpha
 * channel split into a soft mask. Other images (GIF, WebP, 16-bit or
 * interlaced PNG) are turned into PNG with GD first; without GD they are
 * not supported.
 */
final class PdfImage {
	/**
	 * @param array<string,string> $dict  extra entries of the image dictionary
	 */
	private function __construct(
		public readonly int $width,
		public readonly int $height,
		public readonly string $data,
		public readonly array $dict,
		public readonly ?string $mask = null,
	) {}

	public static function from_string( string $bytes ): ?self {
		if ( str_starts_with( $bytes, "\xFF\xD8" ) ) {
			return self::jpeg( $bytes );
		}
		if ( str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			$image = self::png( $bytes );
			if ( $image !== null ) {
				return $image;
			}
		}
		return self::through_gd( $bytes );
	}

	public static function from_file( string $path ): ?self {
		$bytes = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local image file.
		return is_string( $bytes ) && $bytes !== '' ? self::from_string( $bytes ) : null;
	}

	private static function jpeg( string $bytes ): ?self {
		$at  = 2;
		$len = strlen( $bytes );
		while ( $at + 9 < $len ) {
			if ( $bytes[ $at ] !== "\xFF" ) {
				return null;
			}
			$marker = ord( $bytes[ $at + 1 ] );
			$size   = ( ord( $bytes[ $at + 2 ] ) << 8 ) | ord( $bytes[ $at + 3 ] );
			// SOF0..SOF15 except DHT (C4), JPG (C8) and DAC (CC).
			if ( $marker >= 0xC0 && $marker <= 0xCF && ! in_array( $marker, [ 0xC4, 0xC8, 0xCC ], true ) ) {
				$height     = ( ord( $bytes[ $at + 5 ] ) << 8 ) | ord( $bytes[ $at + 6 ] );
				$width      = ( ord( $bytes[ $at + 7 ] ) << 8 ) | ord( $bytes[ $at + 8 ] );
				$components = ord( $bytes[ $at + 9 ] );
				$space      = match ( $components ) {
					1 => '/DeviceGray',
					4 => '/DeviceCMYK',
					default => '/DeviceRGB',
				};
				$dict = [
					'ColorSpace'       => $space,
					'BitsPerComponent' => '8',
					'Filter'           => '/DCTDecode',
				];
				if ( $components === 4 ) {
					// Adobe writes CMYK JPEGs inverted.
					$dict['Decode'] = '[1 0 1 0 1 0 1 0]';
				}
				return $width > 0 && $height > 0 ? new self( $width, $height, $bytes, $dict ) : null;
			}
			$at += 2 + $size;
		}
		return null;
	}

	private static function png( string $bytes ): ?self {
		$at      = 8;
		$len     = strlen( $bytes );
		$header  = null;
		$palette = '';
		$idat    = '';
		while ( $at + 8 <= $len ) {
			$size = (int) unpack( 'N', substr( $bytes, $at, 4 ) )[1];
			$type = substr( $bytes, $at + 4, 4 );
			$data = substr( $bytes, $at + 8, $size );
			if ( $type === 'IHDR' ) {
				$header = unpack( 'Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data );
			} elseif ( $type === 'PLTE' ) {
				$palette = $data;
			} elseif ( $type === 'tRNS' ) {
				return null; // Transparency by colour key: GD turns it into alpha.
			} elseif ( $type === 'IDAT' ) {
				$idat .= $data;
			} elseif ( $type === 'IEND' ) {
				break;
			}
			$at += 12 + $size;
		}
		if ( ! is_array( $header ) || $header['depth'] !== 8 || $header['interlace'] !== 0 || $idat === '' ) {
			return null;
		}
		$width  = (int) $header['width'];
		$height = (int) $header['height'];
		$color  = (int) $header['color'];
		$colors = match ( $color ) {
			0, 4 => 1,
			2, 6 => 3,
			3 => 1,
			default => 0,
		};
		if ( $colors === 0 || ( $color === 3 && $palette === '' ) ) {
			return null;
		}
		$space = match ( $color ) {
			0, 4 => '/DeviceGray',
			3 => '[/Indexed /DeviceRGB ' . ( intdiv( strlen( $palette ), 3 ) - 1 ) . ' <' . bin2hex( $palette ) . '>]',
			default => '/DeviceRGB',
		};
		$params = static fn ( int $count ): string => '<< /Predictor 15 /Colors ' . $count . ' /BitsPerComponent 8 /Columns ' . $width . ' >>';
		$dict   = [
			'ColorSpace'       => $space,
			'BitsPerComponent' => '8',
			'Filter'           => '/FlateDecode',
			'DecodeParms'      => $params( $colors ),
		];
		if ( $color !== 4 && $color !== 6 ) {
			return new self( $width, $height, $idat, $dict );
		}

		// PNG filters work per channel one pixel back, so the channels can be
		// split after filtering and each keeps the row's filter byte.
		$raw = @gzuncompress( $idat ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken image is skipped.
		if ( $raw === false ) {
			return null;
		}
		$pixel = $colors + 1;
		$row   = $width * $pixel;
		$rgb   = '';
		$alpha = '';
		for ( $y = 0; $y < $height; $y++ ) {
			$line   = substr( $raw, $y * ( $row + 1 ), $row + 1 );
			$rgb   .= $line[0] ?? "\0";
			$alpha .= $line[0] ?? "\0";
			$data   = substr( $line, 1 );
			$rgb   .= (string) preg_replace( '/(.{' . $colors . '})./s', '$1', $data );
			$alpha .= (string) preg_replace( '/.{' . $colors . '}(.)/s', '$1', $data );
		}
		$mask = (string) gzcompress( $alpha );
		return new self( $width, $height, (string) gzcompress( $rgb ), $dict, $mask );
	}

	private static function through_gd( string $bytes ): ?self {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}
		$image = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable image is skipped.
		if ( $image === false ) {
			return null;
		}
		if ( ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
		}
		imagesavealpha( $image, true );
		ob_start();
		imagepng( $image );
		$png = (string) ob_get_clean();
		return str_starts_with( $png, "\x89PNG" ) ? self::png( $png ) : null;
	}
}
