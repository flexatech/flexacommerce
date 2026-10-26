<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Badges\Badge;
use Flexa\Commerce\Badges\BadgeMatcher;
use Flexa\Commerce\Catalog\Product;

defined( 'ABSPATH' ) || exit;

/**
 * The badges over a product image (ADR-023): one box per corner, the
 * badges of a corner stacked in list order. The parent of the overlay is
 * positioned by the stylesheet; the overlay itself takes no pointer events,
 * so the image link under it still works.
 */
final class BadgeOverlay {
	public function __construct( private readonly BadgeMatcher $matcher ) {}

	/** @param string $place Badge::SINGLE or Badge::LOOP */
	public function html( Product $product, string $place ): string {
		return Component::wrap(
			'badges',
			function () use ( $product, $place ): string {
				$corners = [];
				foreach ( $this->matcher->for_product( $product, $place, gmdate( 'Y-m-d H:i:s' ) ) as [ 'badge' => $badge, 'label' => $label ] ) {
					$corners[ $badge->position ][] = self::badge( $badge, $label );
				}
				if ( $corners === [] ) {
					return '';
				}
				$html = '';
				foreach ( Badge::POSITIONS as $position ) {
					if ( isset( $corners[ $position ] ) ) {
						$html .= '<span class="fxc-badges__corner fxc-badges__corner--' . esc_attr( $position ) . '">' . implode( '', $corners[ $position ] ) . '</span>';
					}
				}
				return '<div class="fxc-badges">' . $html . '</div>';
			},
			[
				'product_id' => $product->id,
				'place'      => $place,
			]
		);
	}

	/**
	 * `$html` with the overlay put inside its last closing `</figure>` (a
	 * featured image block) or `</a>`, or unchanged when there is none.
	 */
	public function inside( string $html, Product $product, string $place ): string {
		$overlay = $this->html( $product, $place );
		if ( $overlay === '' ) {
			return $html;
		}
		foreach ( [ '</figure>', '</a>' ] as $close ) {
			$at = strrpos( $html, $close );
			if ( $at !== false ) {
				return substr( $html, 0, $at ) . $overlay . substr( $html, $at );
			}
		}
		return $html;
	}

	private static function badge( Badge $badge, string $label ): string {
		return sprintf(
			'<span class="fxc-badge fxc-badge--%1$s" style="--fxc-badge-bg:%2$s;--fxc-badge-fg:%3$s">%4$s</span>',
			esc_attr( $badge->shape ),
			esc_attr( $badge->bg_color ),
			esc_attr( $badge->text_color ),
			esc_html( $label )
		);
	}
}
