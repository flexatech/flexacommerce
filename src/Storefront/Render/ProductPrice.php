<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Contracts\Pricing\PriceResolution;
use Flexa\Commerce\Pricing\PricingService;

defined( 'ABSPATH' ) || exit;

/**
 * The price of a product: one price (with the regular price struck through
 * on sale) or a range over the purchasable variations. Reads only rows that
 * are already loaded, so a primed archive costs no query here.
 */
final class ProductPrice {
	public function __construct(
		private readonly PricingService $pricing,
		private readonly CartService $cart,
	) {}

	public function html( Product $product, int $user_id = 0 ): string {
		return Component::wrap(
			'product_price',
			fn (): string => $this->markup( $product, $user_id ),
			[ 'product_id' => $product->id ]
		);
	}

	private function markup( Product $product, int $user_id ): string {
		$prices = [];
		foreach ( $product->sellable_rows() as $row ) {
			if ( ! $this->cart->is_purchasable( $product, $row ) ) {
				continue;
			}
			$unit = $this->pricing->unit_price( $product, $row, 1, $user_id );
			if ( $unit !== null ) {
				$prices[] = $unit;
			}
		}
		if ( $prices === [] ) {
			return '';
		}
		usort( $prices, static fn ( PriceResolution $a, PriceResolution $b ): int => $a->active->amount <=> $b->active->amount );
		$low  = $prices[0];
		$high = $prices[ count( $prices ) - 1 ];

		if ( $low->active->amount !== $high->active->amount ) {
			$text = sprintf(
				/* translators: 1: lowest price, 2: highest price */
				__( '%1$s to %2$s', 'flexacommerce' ),
				'<span class="fxc-price__amount">' . esc_html( $this->pricing->format( $low->active ) ) . '</span>',
				'<span class="fxc-price__amount">' . esc_html( $this->pricing->format( $high->active ) ) . '</span>'
			);
			return '<p class="fxc-price fxc-price--range">' . $text . '</p>';
		}
		return self::single( $this->pricing, $low );
	}

	public static function single( PricingService $pricing, PriceResolution $unit ): string {
		$active = '<span class="fxc-price__amount">' . esc_html( $pricing->format( $unit->active ) ) . '</span>';
		if ( ! $unit->is_reduced() ) {
			return '<p class="fxc-price">' . $active . '</p>';
		}
		return sprintf(
			'<p class="fxc-price fxc-price--sale"><del aria-hidden="true">%1$s</del> <span class="screen-reader-text">%2$s</span><ins aria-hidden="true">%3$s</ins></p>',
			esc_html( $pricing->format( $unit->regular ) ),
			esc_html(
				sprintf(
					/* translators: 1: regular price, 2: sale price */
					__( 'Was %1$s, now %2$s.', 'flexacommerce' ),
					$pricing->format( $unit->regular ),
					$pricing->format( $unit->active )
				)
			),
			$active
		);
	}
}
