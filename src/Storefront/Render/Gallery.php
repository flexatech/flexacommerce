<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Badges\Badge;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The product images: the featured image large, the gallery as thumbnail
 * (or dot) links to the full size. Without scripts a link opens its image;
 * with the `flexacommerce/cart` store it replaces the large image, and so
 * does the image of the variation the add-to-cart form picks. The store
 * also adds arrows, swipe, hover zoom and a full-size view (ADR-023).
 */
final class Gallery {
	public const MAIN  = 'large';
	public const THUMB = 'thumbnail';

	public function __construct( private readonly ?BadgeOverlay $badges = null ) {}

	public function html( Product $product ): string {
		return Component::wrap(
			'product_gallery',
			fn (): string => $this->markup( $product ),
			[ 'product_id' => $product->id ]
		);
	}

	private function markup( Product $product ): string {
		$featured = (int) get_post_thumbnail_id( $product->id );
		$shown    = array_values( array_unique( array_filter( array_merge( [ $featured ], $product->gallery_ids ) ) ) );
		$variants = [];
		foreach ( $product->variations as $variation ) {
			if ( $variation->enabled && (int) $variation->image_id > 0 ) {
				$variants[] = (int) $variation->image_id;
			}
		}
		$all = array_values( array_unique( array_merge( $shown, $variants ) ) );
		if ( $all === [] ) {
			return '';
		}
		_prime_post_caches( $all, false, true );

		$images = [];
		foreach ( $all as $id ) {
			$image = self::image( $id );
			if ( $image !== null ) {
				$images[ $id ] = $image;
			}
		}
		$shown = array_values( array_filter( $shown, static fn ( int $id ): bool => isset( $images[ $id ] ) ) );
		if ( $shown === [] ) {
			// Only variation images: start from the first one.
			$first = array_key_first( $images );
			if ( $first === null ) {
				return '';
			}
			$shown = [ $first ];
		}
		$main     = $shown[0];
		$thumbs   = Settings::string( 'gallery_thumbs' );
		$lightbox = Settings::bool( 'gallery_lightbox' );
		$zoom     = Settings::bool( 'gallery_zoom' );
		$several  = count( $shown ) > 1;
		$arrows   = $several && Settings::bool( 'gallery_arrows' );
		// One image and nothing to open or zoom: plain markup, no script.
		$interactive = count( $images ) > 1 || $lightbox || $zoom;

		$attrs = '';
		if ( $interactive ) {
			Assets::use_store();
			$attrs = sprintf(
				' data-wp-interactive="flexacommerce/cart" data-wp-context="%s"',
				esc_attr(
					(string) wp_json_encode(
						[
							'productId' => $product->id,
							'main'      => $main,
							'title'     => $product->title,
							'images'    => (object) $images,
							'order'     => $shown,
							'zoomed'    => false,
							'origin'    => '50% 50%',
							'touchX'    => null,
						]
					)
				)
			);
		}

		$classes = [ 'fxc-gallery', 'fxc-gallery--thumbs-' . $thumbs, 'fxc-gallery--aspect-' . Settings::string( 'gallery_aspect' ) ];
		if ( $zoom ) {
			$classes[] = 'fxc-gallery--zoom';
		}
		$img   = $images[ $main ];
		$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $attrs . '>';
		$html .= sprintf(
			'<figure class="fxc-gallery__main"%1$s><a href="%2$s"%3$s><img src="%4$s" srcset="%5$s" sizes="(max-width: 782px) 100vw, 50vw" alt="%6$s" width="%7$d" height="%8$d" fetchpriority="high" decoding="async"%9$s></a>',
			$interactive ? ' data-wp-on-async--touchstart="actions.swipeStart" data-wp-on-async--touchend="actions.swipeEnd"' . ( $zoom ? ' data-wp-on-async--mousemove="actions.zoom" data-wp-on-async--mouseleave="actions.unzoom" data-wp-class--is-zoomed="context.zoomed"' : '' ) : '',
			esc_url( $img['full'] ),
			$interactive ? ' data-wp-bind--href="state.galleryImage.full"' . ( $lightbox ? ' data-wp-on--click="actions.openLightbox"' : '' ) : '',
			esc_url( $img['src'] ),
			esc_attr( $img['srcset'] ),
			esc_attr( $img['alt'] !== '' ? $img['alt'] : $product->title ),
			$img['width'],
			$img['height'],
			$interactive ? ' data-wp-bind--src="state.galleryImage.src" data-wp-bind--srcset="state.galleryImage.srcset" data-wp-bind--alt="state.galleryImage.alt" data-wp-bind--width="state.galleryImage.width" data-wp-bind--height="state.galleryImage.height"' . ( $zoom ? ' data-wp-style--transform-origin="context.origin"' : '' ) : ''
		);
		if ( $this->badges !== null ) {
			$html .= $this->badges->html( $product, Badge::SINGLE );
		}
		if ( $arrows ) {
			$html .= self::arrows( 'fxc-gallery__arrow' );
		}
		$html .= '</figure>';
		if ( $several ) {
			$html .= $this->thumbs( $shown, $images, $thumbs === 'dots' );
		}
		if ( $interactive && $lightbox ) {
			$html .= $this->lightbox( $several, $img['full'], $img['alt'] !== '' ? $img['alt'] : $product->title );
		}
		return $html . '</div>';
	}

	/**
	 * Links to each image: thumbnails, or dots that keep the label for
	 * screen readers. They work as plain links without scripts.
	 *
	 * @param list<int>                        $shown
	 * @param array<int,array{full:string}>    $images
	 */
	private function thumbs( array $shown, array $images, bool $dots ): string {
		$html = '<ul class="fxc-gallery__thumbs' . ( $dots ? ' fxc-gallery__thumbs--dots' : '' ) . '" aria-label="' . esc_attr__( 'Product images', 'flexacommerce' ) . '">';
		foreach ( $shown as $index => $id ) {
			$label = sprintf(
				/* translators: 1: image number, 2: number of images */
				__( 'Show image %1$d of %2$d', 'flexacommerce' ),
				$index + 1,
				count( $shown )
			);
			if ( $dots ) {
				$inner = '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
			} else {
				$thumb = wp_get_attachment_image_src( $id, self::THUMB );
				if ( ! is_array( $thumb ) ) {
					continue;
				}
				$inner = sprintf( '<img src="%1$s" width="%2$d" height="%3$d" alt="%4$s" loading="lazy" decoding="async">', esc_url( (string) $thumb[0] ), (int) $thumb[1], (int) $thumb[2], esc_attr( $label ) );
			}
			$html .= sprintf(
				'<li><a href="%1$s" data-image="%2$d" data-wp-on--click="actions.showImage" data-wp-bind--aria-current="state.galleryCurrent"%3$s>%4$s</a></li>',
				esc_url( $images[ $id ]['full'] ),
				$id,
				$index === 0 ? ' aria-current="true"' : '',
				$inner
			);
		}
		return $html . '</ul>';
	}

	/**
	 * Previous and next buttons. They do nothing without scripts, so they
	 * stay hidden until the store binds them.
	 */
	private static function arrows( string $class ): string {
		$html = '';
		foreach (
			[
				'prev' => [ __( 'Previous image', 'flexacommerce' ), 'actions.galleryPrev', '&#8249;' ],
				'next' => [ __( 'Next image', 'flexacommerce' ), 'actions.galleryNext', '&#8250;' ],
			] as $side => [ $label, $action, $glyph ]
		) {
			$html .= sprintf(
				'<button type="button" class="%1$s %1$s--%2$s" aria-label="%3$s" data-wp-on--click="%4$s" hidden data-wp-bind--hidden="state.galleryStatic"><span aria-hidden="true">%5$s</span></button>',
				esc_attr( $class ),
				$side,
				esc_attr( $label ),
				$action,
				$glyph
			);
		}
		return $html;
	}

	/**
	 * The full-size view, a native modal dialog (Escape closes it). Its image
	 * is lazy, so a closed dialog loads nothing.
	 */
	private function lightbox( bool $several, string $full, string $alt ): string {
		return sprintf(
			'<dialog class="fxc-lightbox" aria-label="%1$s" data-wp-on--keydown="actions.lightboxKey" data-wp-on--click="actions.lightboxBackdrop" data-wp-on-async--touchstart="actions.swipeStart" data-wp-on-async--touchend="actions.swipeEnd"><img class="fxc-lightbox__image" src="%4$s" alt="%5$s" loading="lazy" decoding="async" data-wp-bind--src="state.galleryImage.full" data-wp-bind--alt="state.galleryImage.alt"><button type="button" class="fxc-lightbox__close" aria-label="%2$s" data-wp-on--click="actions.closeLightbox"><span aria-hidden="true">&times;</span></button>%3$s</dialog>',
			esc_attr__( 'Product images', 'flexacommerce' ),
			esc_attr__( 'Close', 'flexacommerce' ),
			$several ? self::arrows( 'fxc-lightbox__arrow' ) . '<p class="fxc-lightbox__count" data-wp-text="state.galleryCount"></p>' : '',
			esc_url( $full ),
			esc_attr( $alt )
		);
	}

	/** @return array{src:string,srcset:string,alt:string,full:string,width:int,height:int}|null */
	private static function image( int $id ): ?array {
		$large = wp_get_attachment_image_src( $id, self::MAIN );
		if ( ! is_array( $large ) ) {
			return null;
		}
		$full   = wp_get_attachment_image_src( $id, 'full' );
		$srcset = wp_get_attachment_image_srcset( $id, self::MAIN );
		return [
			'src'    => (string) $large[0],
			'srcset' => is_string( $srcset ) ? $srcset : '',
			'alt'    => trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ),
			'full'   => is_array( $full ) ? (string) $full[0] : (string) $large[0],
			'width'  => (int) $large[1],
			'height' => (int) $large[2],
		];
	}
}
