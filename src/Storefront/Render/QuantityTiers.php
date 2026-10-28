<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Contracts\Pricing\PriceResolution;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRules;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The quantity tiers of a bulk pricing rule, under the add-to-cart button
 * (ADR-024, ADR-030). Each row is a range of quantities, starting with
 * the price below the first tier. A product with one price lists the unit
 * price and the saving at each range, worked out like the cart does; a
 * product with variations lists what each range takes off, since the price
 * depends on the variation. The row for the quantity in the form is marked
 * `is-current`, and cart.js moves the mark as the quantity changes. A
 * graduated rule (ADR-042) prices only the units in each range, which a
 * note under the table says.
 */
final class QuantityTiers {
	/** @param ?string $shown_end the end the offer countdown already shows, so the table does not repeat it */
	public static function html( PricingService $pricing, Product $product, int $user_id, ?string $shown_end = null ): string {
		return Settings::bool( 'tier_table' ) ? self::table( $pricing, $product, $user_id, $shown_end ) : '';
	}

	/** The table whatever the setting says: the Quantity prices block shows it where the merchant placed it (ADR-050). */
	public static function table( PricingService $pricing, Product $product, int $user_id, ?string $shown_end = null ): string {
		$rule = $pricing->tier_rule( $product, $user_id );
		if ( $rule === null || $rule->tiers === [] ) {
			return '';
		}
		$graduated = $rule->type === PriceRule::GRADUATED;
		$rows      = array_values( array_filter( $product->sellable_rows(), static fn ( $row ): bool => $row->enabled ) );
		$one       = count( $rows ) === 1 ? $rows[0] : null;
		$base      = $one !== null ? $pricing->unit_price( $product, $one, 1, $user_id ) : null;

		$ranges = [];
		if ( $rule->tiers[0]['min'] > 1 ) {
			$ranges[] = [ 1, $rule->tiers[0]['min'] - 1, null ];
		}
		foreach ( $rule->tiers as $i => $tier ) {
			$next     = $rule->tiers[ $i + 1 ]['min'] ?? 0;
			$ranges[] = [ $tier['min'], $next > 0 ? $next - 1 : 0, $tier ];
		}

		$cells = [];
		$saves = false;
		foreach ( $ranges as [ $min, $max, $tier ] ) {
			if ( $one === null ) {
				$cells[] = [ $tier === null ? __( 'Full price', 'flexacommerce' ) : self::change( $pricing, $tier['mode'], $tier['amount'] ), '' ];
				continue;
			}
			$unit = match ( true ) {
				$tier === null => $base,
				// Graduated: what each unit in the range costs, as the cart works it out.
				$graduated     => $base !== null ? new PriceResolution( $base->regular, $pricing->money( PriceRules::changed( $base->active->amount, $tier['mode'], $tier['amount'] ) ), $base->on_sale ) : null,
				default        => $pricing->unit_price( $product, $one, $min, $user_id ),
			};
			if ( $unit === null ) {
				$cells[] = [ $tier === null ? '' : self::change( $pricing, $tier['mode'], $tier['amount'] ), '' ];
				continue;
			}
			$saving = '';
			if ( $base !== null && $base->active->amount > 0 && $unit->active->amount < $base->active->amount ) {
				$percent = (int) round( ( $base->active->amount - $unit->active->amount ) * 100 / $base->active->amount );
				/* translators: %d: percent saved on each unit */
				$saving = $percent > 0 ? sprintf( __( 'Save %d%%', 'flexacommerce' ), $percent ) : '';
				$saves  = $saves || $saving !== '';
			}
			$cells[] = [
				/* translators: %s: unit price */
				sprintf( __( '%s each', 'flexacommerce' ), $pricing->format( $pricing->for_display( $product, $one, $unit )->active ) ),
				$saving,
			];
		}

		$body = '';
		foreach ( $ranges as $i => [ $min, $max ] ) {
			$body .= sprintf(
				'<tr data-min="%1$d" data-max="%2$d"%3$s><th scope="row">%4$s</th><td>%5$s</td>%6$s</tr>',
				$min,
				$max,
				$min === 1 ? ' class="is-current"' : '',
				esc_html( self::range( $min, $max ) ),
				esc_html( $cells[ $i ][0] ),
				$saves ? '<td>' . esc_html( $cells[ $i ][1] ) . '</td>' : ''
			);
		}
		$title = Settings::string( 'tier_table_title' );
		$html  = sprintf(
			'<table class="fxc-tiers"><caption>%1$s</caption><thead><tr><th scope="col">%2$s</th><th scope="col">%3$s</th>%4$s</tr></thead><tbody>%5$s</tbody>%6$s</table>',
			esc_html( $title !== '' ? $title : __( 'Buy more, pay less', 'flexacommerce' ) ),
			esc_html__( 'Quantity', 'flexacommerce' ),
			esc_html( $one !== null ? __( 'Price', 'flexacommerce' ) : __( 'Discount', 'flexacommerce' ) ),
			$saves ? '<th scope="col">' . esc_html__( 'You save', 'flexacommerce' ) . '</th>' : '',
			$body,
			$graduated ? sprintf( '<tfoot><tr><td colspan="%1$d">%2$s</td></tr></tfoot>', $saves ? 3 : 2, esc_html__( 'Each price applies only to the units in its range.', 'flexacommerce' ) ) : ''
		);
		if ( Settings::int( 'countdown_days' ) > 0 && $rule->ends_at !== $shown_end && $pricing->ends_within( $rule->ends_at, Settings::int( 'countdown_days' ) ) ) {
			$html .= Countdown::html( (string) $rule->ends_at, Countdown::TIERS );
		}
		return $html;
	}

	/** A range of quantities as text: "1", "2 to 4", "10 or more". */
	private static function range( int $min, int $max ): string {
		if ( $max === 0 ) {
			/* translators: %d: smallest quantity of the tier */
			return sprintf( __( '%d or more', 'flexacommerce' ), $min );
		}
		if ( $max === $min ) {
			return (string) $min;
		}
		/* translators: 1: smallest quantity, 2: largest quantity */
		return sprintf( __( '%1$d to %2$d', 'flexacommerce' ), $min, $max );
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
