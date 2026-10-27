<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Time left before a price goes up (ADR-030). The server prints the end
 * as a date sentence, which is what screen readers and pages without
 * JavaScript get; `callbacks.countdown` in cart.js hides the sentence
 * visually and ticks the days, hours, minutes and seconds every second.
 */
final class Countdown {
	/** The price of one unit. */
	public const OFFER = 'offer';
	/** The prices of the quantity tiers. */
	public const TIERS = 'tiers';

	/** @param string $ends UTC `Y-m-d H:i:s` */
	public static function html( string $ends, string $kind = self::OFFER ): string {
		$time = strtotime( $ends . ' UTC' );
		if ( $time === false ) {
			return '';
		}
		$date  = sprintf(
			'<time datetime="%1$s">%2$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) )
		);
		$tiers = $kind === self::TIERS;
		$text  = $tiers
			/* translators: %s: date and time */
			? __( 'These quantity prices end on %s.', 'flexacommerce' )
			/* translators: %s: date and time */
			: __( 'This price ends on %s.', 'flexacommerce' );

		$left  = max( 0, $time - time() );
		$units = '';
		foreach (
			[
				[ intdiv( $left, DAY_IN_SECONDS ), __( 'Days', 'flexacommerce' ) ],
				[ intdiv( $left, HOUR_IN_SECONDS ) % 24, __( 'Hours', 'flexacommerce' ) ],
				[ intdiv( $left, MINUTE_IN_SECONDS ) % 60, __( 'Minutes', 'flexacommerce' ) ],
				[ $left % 60, __( 'Seconds', 'flexacommerce' ) ],
			] as $i => [ $value, $name ]
		) {
			$units .= sprintf(
				'<span class="fxc-countdown__unit"><span class="fxc-countdown__value" data-unit>%1$s</span><span class="fxc-countdown__name">%2$s</span></span>',
				esc_html( $i > 0 ? sprintf( '%02d', $value ) : (string) $value ),
				esc_html( $name )
			);
		}

		return sprintf(
			'<div class="fxc-countdown fxc-countdown--%1$s" data-ends="%2$s" data-ended="%3$s" data-wp-init="callbacks.countdown"><p class="fxc-countdown__text">%4$s</p><p class="fxc-countdown__clock" aria-hidden="true" hidden><span class="fxc-countdown__title">%5$s</span>%6$s</p></div>',
			esc_attr( $kind ),
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr(
				$tiers
					? __( 'These quantity prices have ended. Reload the page to see the current prices.', 'flexacommerce' )
					: __( 'This offer has ended. Reload the page to see the current price.', 'flexacommerce' )
			),
			sprintf( esc_html( $text ), $date ),
			esc_html( $tiers ? __( 'Quantity prices end in', 'flexacommerce' ) : __( 'Offer ends in', 'flexacommerce' ) ),
			$units
		);
	}
}
