<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\ProductVideo;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;

/** The gallery video (ADR-048): the links it reads, how it is kept, and where it sits in the gallery. */
final class GalleryVideoTest extends CartTestCase {
	public function test_the_links_it_reads(): void {
		$cases = [
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => [ ProductVideo::YOUTUBE, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0&playsinline=1' ],
			'youtu.be/dQw4w9WgXcQ?t=42'                   => [ ProductVideo::YOUTUBE, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0&playsinline=1' ],
			'https://m.youtube.com/watch?feature=share&v=dQw4w9WgXcQ' => [ ProductVideo::YOUTUBE, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0&playsinline=1' ],
			'https://www.youtube.com/shorts/dQw4w9WgXcQ'  => [ ProductVideo::YOUTUBE, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0&playsinline=1' ],
			'https://vimeo.com/76979871'                  => [ ProductVideo::VIMEO, 'https://player.vimeo.com/video/76979871?autoplay=1&dnt=1' ],
			'https://vimeo.com/76979871/8272103f6e'       => [ ProductVideo::VIMEO, 'https://player.vimeo.com/video/76979871?autoplay=1&dnt=1&h=8272103f6e' ],
			'https://player.vimeo.com/video/76979871?h=8272103f6e' => [ ProductVideo::VIMEO, 'https://player.vimeo.com/video/76979871?autoplay=1&dnt=1&h=8272103f6e' ],
			'https://cdn.example.com/clips/shoe.MP4?v=2'  => [ ProductVideo::FILE, 'https://cdn.example.com/clips/shoe.MP4?v=2' ],
		];
		foreach ( $cases as $url => [ $provider, $embed ] ) {
			$video = ProductVideo::parse( $url );
			self::assertSame( [ $provider, $embed ], [ $video->provider, $video->embed_url() ], $url );
		}
		self::assertSame( 'https://vimeo.com/76979871/8272103f6e', ProductVideo::parse( 'https://vimeo.com/76979871/8272103f6e' )->link() );
		self::assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', ProductVideo::parse( 'youtu.be/dQw4w9WgXcQ' )->link() );

		foreach ( [ 'https://example.com/page', 'https://www.youtube.com/watch?v=short', 'javascript:alert(1)', 'https://example.com/clip.mov' ] as $url ) {
			try {
				ProductVideo::parse( $url );
				self::fail( $url . ' was read as a video' );
			} catch ( InvalidProduct $e ) {
				self::assertSame( 'video.url', $e->field, $url );
			}
		}
		$this->expectException( InvalidProduct::class );
		ProductVideo::parse( 'https://youtu.be/dQw4w9WgXcQ', 0, 'middle' );
	}

	public function test_it_is_kept_cleared_and_copied(): void {
		$id = $this->simple();
		$this->save(
			$id,
			[
				'video' => [
					'url'      => 'https://youtu.be/dQw4w9WgXcQ',
					'position' => 'last',
				],
			]
		);
		$video = $this->service()->get( $id )->video;
		self::assertNotNull( $video );
		self::assertSame( [ ProductVideo::YOUTUBE, 'dQw4w9WgXcQ', 'last' ], [ $video->provider, $video->key, $video->position ] );

		$this->save( $id, [ 'base' => [ 'regular_price' => 1500 ] ] );
		self::assertNotNull( $this->service()->get( $id )->video, 'a save without the key keeps it' );

		$copy = $this->service()->duplicate( $id );
		$this->track_post( $copy );
		self::assertSame( 'last', $this->service()->get( $copy )->video?->position );

		$this->save( $id, [ 'video' => null ] );
		self::assertNull( $this->service()->get( $id )->video );
		self::assertSame( '', get_post_meta( $id, ProductRepository::META_VIDEO, true ) );

		$file = self::factory()->attachment->create_object(
			[
				'file'           => 'clip.mp4',
				'post_parent'    => $id,
				'post_mime_type' => 'video/mp4',
			]
		);
		$this->track_post( $file );
		$this->save( $id, [ 'video' => [ 'attachment_id' => $file ] ] );
		$video = $this->service()->get( $id )->video;
		self::assertSame( [ ProductVideo::FILE, $file, 'second' ], [ $video?->provider, $video?->attachment_id, $video?->position ] );
		self::assertStringEndsWith( '/clip.mp4', (string) $video?->url );
		self::assertStringNotContainsString( 'clip.mp4', (string) get_post_meta( $id, ProductRepository::META_VIDEO, true ), 'the URL is read from the attachment' );

		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		$this->track_post( $image );
		try {
			$this->save( $id, [ 'video' => [ 'attachment_id' => $image ] ] );
			self::fail( 'an image was taken as the video' );
		} catch ( InvalidProduct $e ) {
			self::assertSame( 'video.attachment_id', $e->field );
		}
	}

	public function test_the_rest_shape(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$id    = $this->simple();
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		$this->track_post( $image );

		$request = new \WP_REST_Request( 'PUT', '/flexacommerce/v1/admin/products/' . $id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'gallery_ids' => [ $image, 999999 ],
					'video'       => [ 'url' => 'https://vimeo.com/76979871' ],
				]
			)
		);
		$data = rest_get_server()->dispatch( $request )->get_data();
		self::assertSame( [ $image ], array_column( $data['gallery'], 'id' ), 'images that are gone are left out' );
		self::assertSame( 'vimeo', $data['video']['provider'] );
		self::assertSame( 'https://vimeo.com/76979871', $data['video']['link'] );

		$request->set_body( (string) wp_json_encode( [ 'video' => [ 'url' => 'https://example.com/page' ] ] ) );
		$response = rest_get_server()->dispatch( $request );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'video.url', $response->get_data()['data']['field'] ?? null );
	}

	public function test_the_gallery_places_the_video(): void {
		$id     = $this->simple();
		$images = [
			self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id ),
			self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id ),
		];
		array_map( [ $this, 'track_post' ], $images );
		set_post_thumbnail( $id, $images[0] );
		$this->save(
			$id,
			[
				'gallery_ids' => [ $images[1] ],
				'video'       => [ 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ],
			]
		);

		$html = do_blocks( '<!-- wp:flexacommerce/product-gallery {"productId":' . $id . '} /-->' );
		self::assertMatchesRegularExpression( '#data-image="' . $images[0] . '".*data-image="0".*data-image="' . $images[1] . '"#s', $html, 'right after the product image' );
		self::assertStringContainsString( 'href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" data-image="0"', $html, 'the thumbnail links to the video without scripts' );
		self::assertStringContainsString( 'data-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&#038;rel=0&#038;playsinline=1"', $html );
		self::assertDoesNotMatchRegularExpression( '#<iframe[^>]* src=#', $html, 'nothing loads from YouTube before play' );
		self::assertStringContainsString( '<div class="fxc-gallery__video" hidden', $html );

		$this->save(
			$id,
			[
				'video' => [
					'url'      => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
					'position' => 'first',
				],
			]
		);
		$html    = do_blocks( '<!-- wp:flexacommerce/product-gallery {"productId":' . $id . '} /-->' );
		$context = self::context( $html );
		self::assertSame( [ 0, $images[0], $images[1] ], $context['order'] );
		self::assertSame( 0, $context['main'] );
		self::assertSame( $context['images'][ $images[0] ], $context['images'][0], 'the product image is the cover' );

		// Only the product image and a video: still a gallery to move through.
		$this->save( $id, [ 'gallery_ids' => [] ] );
		$context = self::context( do_blocks( '<!-- wp:flexacommerce/product-gallery {"productId":' . $id . '} /-->' ) );
		self::assertSame( [ 0, $images[0] ], $context['order'] );
	}

	/** @return array<string,mixed> */
	private static function context( string $html ): array {
		self::assertSame( 1, preg_match( '/data-wp-context="([^"]+)"/', $html, $m ) );
		return (array) json_decode( html_entity_decode( $m[1] ), true );
	}
}
