<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;

defined( 'ABSPATH' ) || exit;

/**
 * A product's gallery video (ADR-048): a YouTube or Vimeo link, or a video
 * file from the media library or a URL. The page loads nothing from the
 * video's host until the shopper presses play.
 */
final class ProductVideo {
	public const YOUTUBE   = 'youtube';
	public const VIMEO     = 'vimeo';
	public const FILE      = 'file';
	public const POSITIONS = [ 'first', 'second', 'last' ];

	private const YOUTUBE_ID = '/^(?:(?:www\.|m\.|music\.)?youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})(?:[?&#\/]|$)/';
	private const VIMEO_ID   = '/^(?:(?:www\.)?vimeo\.com\/(?:channels\/[^\/]+\/|groups\/[^\/]+\/videos\/)?|player\.vimeo\.com\/video\/)(\d{4,12})(?:\/([0-9a-f]{6,20}))?(?:[?&#\/]|$)/';
	private const FILE_EXT   = '/\.(mp4|m4v|webm)$/i';

	/** `key` is the YouTube id, or the Vimeo id with `:hash` for an unlisted video. */
	public function __construct(
		public readonly string $provider,
		public readonly string $url,
		public readonly string $key = '',
		public readonly int $attachment_id = 0,
		public readonly string $position = 'second',
	) {}

	/**
	 * Reads what the merchant entered: a link, or a media library video.
	 *
	 * @throws InvalidProduct
	 */
	public static function parse( string $url, int $attachment_id = 0, string $position = 'second' ): self {
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			throw new InvalidProduct( __( 'Unknown video position.', 'flexacommerce' ), 'video.position' );
		}
		if ( $attachment_id > 0 ) {
			$mime = (string) get_post_mime_type( $attachment_id );
			$file = (string) wp_get_attachment_url( $attachment_id );
			if ( ! str_starts_with( $mime, 'video/' ) || $file === '' ) {
				throw new InvalidProduct( __( 'The picked file is not a video.', 'flexacommerce' ), 'video.attachment_id' );
			}
			return new self( self::FILE, $file, '', $attachment_id, $position );
		}

		$url = trim( $url );
		if ( $url !== '' && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$clean = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( $clean === '' ) {
			throw new InvalidProduct( __( 'Enter a YouTube or Vimeo link, or a link to an MP4 or WebM file.', 'flexacommerce' ), 'video.url' );
		}
		$rest = (string) preg_replace( '#^https?://#i', '', $clean );
		if ( preg_match( self::YOUTUBE_ID, $rest, $m ) === 1 ) {
			return new self( self::YOUTUBE, $clean, $m[1], 0, $position );
		}
		if ( preg_match( self::VIMEO_ID, $rest, $m ) === 1 ) {
			$key = $m[1] . ( isset( $m[2] ) ? ':' . $m[2] : '' );
			// An unlisted link may carry its hash as ?h= instead of a path segment.
			if ( ! str_contains( $key, ':' ) && preg_match( '/[?&]h=([0-9a-f]{6,20})/', $rest, $h ) === 1 ) {
				$key .= ':' . $h[1];
			}
			return new self( self::VIMEO, $clean, $key, 0, $position );
		}
		$path = (string) wp_parse_url( $clean, PHP_URL_PATH );
		if ( preg_match( self::FILE_EXT, $path ) === 1 ) {
			return new self( self::FILE, $clean, '', 0, $position );
		}
		throw new InvalidProduct( __( 'Enter a YouTube or Vimeo link, or a link to an MP4 or WebM file.', 'flexacommerce' ), 'video.url' );
	}

	/** From stored meta; null when what is stored no longer reads as a video. */
	public static function from_meta( string $json ): ?self {
		if ( $json === '' ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$position = is_string( $data['position'] ?? null ) ? $data['position'] : 'second';
		try {
			return self::parse( is_string( $data['url'] ?? null ) ? $data['url'] : '', (int) ( $data['attachment_id'] ?? 0 ), $position );
		} catch ( InvalidProduct ) {
			return null;
		}
	}

	/** What is stored: the rest is worked out again on read, so a moved site gets the new file URL. */
	public function to_meta(): string {
		return (string) wp_json_encode(
			[
				'url'           => $this->attachment_id > 0 ? '' : $this->url,
				'attachment_id' => $this->attachment_id,
				'position'      => $this->position,
			]
		);
	}

	/** @return array{provider:string,url:string,attachment_id:int,position:string,embed:string,link:string} */
	public function to_array(): array {
		return [
			'provider'      => $this->provider,
			'url'           => $this->url,
			'attachment_id' => $this->attachment_id,
			'position'      => $this->position,
			'embed'         => $this->embed_url(),
			'link'          => $this->link(),
		];
	}

	public function is_file(): bool {
		return $this->provider === self::FILE;
	}

	/** The iframe address, set once the shopper presses play; the file itself for a file. */
	public function embed_url(): string {
		[ $id, $hash ] = array_pad( explode( ':', $this->key, 2 ), 2, '' );
		return match ( $this->provider ) {
			self::YOUTUBE => 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $id ) . '?autoplay=1&rel=0&playsinline=1',
			self::VIMEO   => 'https://player.vimeo.com/video/' . rawurlencode( $id ) . '?autoplay=1&dnt=1' . ( $hash !== '' ? '&h=' . rawurlencode( $hash ) : '' ),
			default       => $this->url,
		};
	}

	/** Where the video lives, for the thumbnail link when the page runs no JavaScript. */
	public function link(): string {
		[ $id, $hash ] = array_pad( explode( ':', $this->key, 2 ), 2, '' );
		return match ( $this->provider ) {
			self::YOUTUBE => 'https://www.youtube.com/watch?v=' . rawurlencode( $id ),
			self::VIMEO   => 'https://vimeo.com/' . rawurlencode( $id ) . ( $hash !== '' ? '/' . rawurlencode( $hash ) : '' ),
			default       => $this->url,
		};
	}
}
