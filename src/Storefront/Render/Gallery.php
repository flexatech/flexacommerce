<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Storefront\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The product images: the featured image large, the gallery as thumbnail
 * links to the full size. Without scripts a thumbnail opens its image;
 * with the `flexacommerce/cart` store it replaces the large image, and so
 * does the image of the variation the add-to-cart form picks.
 */
final class Gallery {
	public const MAIN  = 'large';
	public const THUMB = 'thumbnail';

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
		$main        = $shown[0];
		$interactive = count( $images ) > 1;

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
						]
					)
				)
			);
		}

		$img   = $images[ $main ];
		$html  = '<div class="fxc-gallery"' . $attrs . '>';
		$html .= sprintf(
			'<figure class="fxc-gallery__main"><a href="%1$s"%6$s><img src="%2$s" srcset="%3$s" sizes="(max-width: 782px) 100vw, 50vw" alt="%4$s" width="%7$d" height="%8$d" fetchpriority="high" decoding="async"%5$s></a></figure>',
			esc_url( $img['full'] ),
			esc_url( $img['src'] ),
			esc_attr( $img['srcset'] ),
			esc_attr( $img['alt'] !== '' ? $img['alt'] : $product->title ),
			$interactive ? ' data-wp-bind--src="state.galleryImage.src" data-wp-bind--srcset="state.galleryImage.srcset" data-wp-bind--alt="state.galleryImage.alt" data-wp-bind--width="state.galleryImage.width" data-wp-bind--height="state.galleryImage.height"' : '',
			$interactive ? ' data-wp-bind--href="state.galleryImage.full"' : '',
			$img['width'],
			$img['height']
		);
		if ( count( $shown ) > 1 ) {
			$html .= '<ul class="fxc-gallery__thumbs" aria-label="' . esc_attr__( 'Product images', 'flexacommerce' ) . '">';
			foreach ( $shown as $index => $id ) {
				$thumb = wp_get_attachment_image_src( $id, self::THUMB );
				if ( ! is_array( $thumb ) ) {
					continue;
				}
				$html .= sprintf(
					'<li><a href="%1$s" data-image="%2$d" data-wp-on--click="actions.showImage" data-wp-bind--aria-current="state.galleryCurrent"%6$s><img src="%3$s" width="%4$d" height="%5$d" alt="%7$s" loading="lazy" decoding="async"></a></li>',
					esc_url( $images[ $id ]['full'] ),
					$id,
					esc_url( (string) $thumb[0] ),
					(int) $thumb[1],
					(int) $thumb[2],
					$index === 0 ? ' aria-current="true"' : '',
					esc_attr(
						sprintf(
							/* translators: 1: image number, 2: number of images */
							__( 'Show image %1$d of %2$d', 'flexacommerce' ),
							$index + 1,
							count( $shown )
						)
					)
				);
			}
			$html .= '</ul>';
		}
		return $html . '</div>';
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
