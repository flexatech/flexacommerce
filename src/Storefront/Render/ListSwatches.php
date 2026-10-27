<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Cart\PurchaseOptions;
use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The swatches of a variable product in a list (ADR-038): the first
 * attribute drawn as swatches, each value a link to the product page with
 * that value picked. A value no variation in stock carries is crossed out
 * or left out, as on the product page; past `swatch_list` values a "+N"
 * link opens the product.
 *
 * Plain links keep the card working without JavaScript and keep the
 * list's markup free of form fields.
 *
 * @phpstan-import-type AttributeChoice from PurchaseOptions
 * @phpstan-import-type VariantOption from PurchaseOptions
 */
final class ListSwatches {
	/** The query argument the product page reads its first choices from. */
	public const PICK = 'fxc_pick';

	/**
	 * @param list<AttributeChoice> $attributes
	 * @param list<VariantOption> $variants
	 */
	public static function html( Product $product, array $attributes, array $variants ): string {
		$limit = Settings::int( 'swatch_list' );
		if ( $limit === 0 ) {
			return '';
		}
		$attribute = null;
		foreach ( $attributes as $candidate ) {
			if ( $candidate['swatch'] !== AttributeRepository::DROPDOWN ) {
				$attribute = $candidate;
				break;
			}
		}
		if ( $attribute === null ) {
			return '';
		}

		$hide   = Settings::string( 'swatch_unavailable' ) === 'hide';
		$link   = (string) get_permalink( $product->id );
		$values = [];
		foreach ( $attribute['options'] as $option ) {
			$stocked = self::in_stock( $variants, $attribute['key'], $option['key'] );
			if ( $stocked || ! $hide ) {
				$values[] = [ $option, $stocked ];
			}
		}
		if ( $values === [] ) {
			return '';
		}

		$html = sprintf(
			'<div class="fxc-swatches fxc-swatches--list fxc-swatches--%1$s" style="--fxc-swatch-size:%2$dpx" role="group" aria-label="%3$s"><div class="fxc-swatches__list">',
			esc_attr( Settings::string( 'swatch_shape' ) ),
			max( 16, intdiv( Settings::int( 'swatch_size' ) * 3, 4 ) ),
			/* translators: 1: attribute name, 2: product name */
			esc_attr( sprintf( __( '%1$s of %2$s', 'flexacommerce' ), $attribute['name'], $product->title ) )
		);
		foreach ( array_slice( $values, 0, $limit ) as [ $option, $stocked ] ) {
			$html .= self::swatch( $link, $attribute['key'], $option, $stocked );
		}
		$more = count( $values ) - $limit;
		if ( $more > 0 ) {
			$html .= sprintf(
				'<a class="fxc-swatches__more" href="%1$s" aria-label="%2$s">+%3$d</a>',
				esc_url( $link ),
				/* translators: %d: number of values not shown */
				esc_attr( sprintf( _n( '%d more choice', '%d more choices', $more, 'flexacommerce' ), $more ) ),
				$more
			);
		}
		return $html . '</div></div>';
	}

	/**
	 * The first choices of a product page, from the link of a swatch:
	 * values the product has, by attribute key.
	 *
	 * @param list<AttributeChoice> $attributes
	 * @return array<string,string>
	 */
	public static function picked( array $attributes ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; values are matched against the product's own.
		$raw = isset( $_GET[ self::PICK ] ) && is_array( $_GET[ self::PICK ] ) ? map_deep( wp_unslash( $_GET[ self::PICK ] ), 'sanitize_text_field' ) : [];
		$out = [];
		foreach ( $attributes as $attribute ) {
			$value = $raw[ $attribute['key'] ] ?? null;
			if ( ! is_string( $value ) ) {
				continue;
			}
			foreach ( $attribute['options'] as $option ) {
				if ( $option['key'] === $value ) {
					$out[ $attribute['key'] ] = $value;
				}
			}
		}
		return $out;
	}

	/** @param list<VariantOption> $variants */
	private static function in_stock( array $variants, string $key, string $value ): bool {
		foreach ( $variants as $variant ) {
			$own = ( (array) $variant['attributes'] )[ $key ] ?? Combination::WILDCARD;
			if ( $variant['in_stock'] && ( $own === $value || $own === Combination::WILDCARD ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array{key:string,label:string,color:string,image:string} $option */
	private static function swatch( string $link, string $key, array $option, bool $stocked ): string {
		if ( $option['image'] !== '' ) {
			$face = sprintf( '<img src="%s" alt="" loading="lazy" decoding="async">', esc_url( $option['image'] ) );
			$kind = 'image';
		} elseif ( $option['color'] !== '' ) {
			$face = '';
			$kind = 'color';
		} else {
			$face = esc_html( $option['label'] );
			$kind = 'button';
		}
		$label = $stocked
			? $option['label']
			/* translators: %s: attribute value, such as Red */
			: sprintf( __( '%s (out of stock)', 'flexacommerce' ), $option['label'] );
		return sprintf(
			'<a class="fxc-swatch fxc-swatch--%1$s%2$s" href="%3$s" rel="nofollow"%4$s><span class="fxc-swatch__face"%5$s>%6$s</span>%7$s</a>',
			esc_attr( $kind ),
			$stocked ? '' : ' is-unavailable',
			esc_url( add_query_arg( self::PICK . '[' . rawurlencode( $key ) . ']', rawurlencode( $option['key'] ), $link ) ),
			$kind !== 'button' ? ' title="' . esc_attr( $label ) . '"' : '',
			$kind === 'color' ? ' style="--fxc-swatch-color:' . esc_attr( $option['color'] ) . '"' : '',
			$face,
			$kind !== 'button' || ! $stocked ? '<span class="screen-reader-text">' . esc_html( $kind === 'button' ? ' ' . __( '(out of stock)', 'flexacommerce' ) : $label ) . '</span>' : ''
		);
	}
}
