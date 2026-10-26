<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Pricing\Rules\PriceRule;

defined( 'ABSPATH' ) || exit;

/**
 * The quantity tiers of a bulk pricing rule, under the add-to-cart button
 * (ADR-024). A product with one price lists the unit price at each tier,
 * worked out like the cart does; a product with variations lists what each
 * tier takes off, since the price depends on the variation.
 */
final class QuantityTiers {
	public static function html( PricingService $pricing, Product $product, int $user_id ): string {
		$rule = $pricing->bulk_rule( $product, $user_id );
		if ( $rule === null ) {
			return '';
		}
		$rows = array_values( array_filter( $product->sellable_rows(), static fn ( $row ): bool => $row->enabled ) );
		$one  = count( $rows ) === 1 ? $rows[0] : null;

		$body = '';
		foreach ( $rule->tiers as $tier ) {
			$text = null;
			if ( $one !== null ) {
				$unit = $pricing->unit_price( $product, $one, $tier['min'], $user_id );
				$text = $unit !== null
					/* translators: %s: unit price */
					? sprintf( __( '%s each', 'flexacommerce' ), $pricing->format( $pricing->for_display( $product, $one, $unit )->active ) )
					: null;
			}
			$body .= sprintf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				/* translators: %d: smallest quantity of the tier */
				esc_html( sprintf( __( '%d or more', 'flexacommerce' ), $tier['min'] ) ),
				esc_html( $text ?? self::change( $pricing, $tier['mode'], $tier['amount'] ) )
			);
		}
		return sprintf(
			'<table class="fxc-tiers"><caption>%1$s</caption><thead><tr><th scope="col">%2$s</th><th scope="col">%3$s</th></tr></thead><tbody>%4$s</tbody></table>',
			esc_html__( 'Buy more, pay less', 'flexacommerce' ),
			esc_html__( 'Quantity', 'flexacommerce' ),
			esc_html__( 'Price', 'flexacommerce' ),
			$body
		);
	}

	/** What a tier does, as plain text. */
	public static function change( PricingService $pricing, string $mode, int $amount ): string {
		return match ( $mode ) {
			/* translators: %s: percent off, for example 12.5 */
			PriceRule::PERCENT => sprintf( __( '%s%% off', 'flexacommerce' ), self::percent( $amount ) ),
			/* translators: %s: amount off each unit */
			PriceRule::FIXED   => sprintf( __( '%s off each', 'flexacommerce' ), $pricing->format( $pricing->money( $amount ) ) ),
			/* translators: %s: unit price */
			default            => sprintf( __( '%s each', 'flexacommerce' ), $pricing->format( $pricing->money( $amount ) ) ),
		};
	}

	/** Hundredths of a percent as text: 1250 is "12.5", 1000 is "10". */
	private static function percent( int $hundredths ): string {
		return rtrim( rtrim( number_format_i18n( $hundredths / 100, 2 ), '0' ), '.,' );
	}
}
