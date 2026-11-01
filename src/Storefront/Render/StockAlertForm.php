<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\StockAlerts\StockAlertSettings;
use Flexa\Commerce\Storefront\FormHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The back-in-stock signup on a product page (ADR-065). A product with no
 * stock at all gets a plain form that posts to admin-post.php; a variation
 * that runs out inside the add-to-cart form gets a block the store script
 * shows and sends to `store/stock-alerts`.
 */
final class StockAlertForm {
	/** The form under "Out of stock". `$variant_id` 0 means any variation. */
	public static function standalone( Product $product, int $variant_id, int $user_id ): string {
		$settings = StockAlertSettings::get();
		if ( ! $settings['enabled'] ) {
			return '';
		}
		$html  = '<form class="fxc-stock-alert" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<p class="fxc-stock-alert__title">' . esc_html__( 'Email me when it is back in stock', 'flexacommerce' ) . '</p>';
		if ( $user_id === 0 && ! $settings['guests'] ) {
			return $html . self::login( $product ) . '</form>';
		}
		$html .= FormHandler::hidden_fields( 'stock_alert' );
		$html .= sprintf( '<input type="hidden" name="product_id" value="%d"><input type="hidden" name="variant_id" value="%d">', $product->id, $variant_id );
		$html .= '<div class="fxc-stock-alert__row">';
		if ( $user_id === 0 ) {
			$id    = wp_unique_id( 'fxc-alert-' );
			$html .= sprintf(
				'<label class="screen-reader-text" for="%1$s">%2$s</label><input class="fxc-stock-alert__email" id="%1$s" type="email" name="email" required autocomplete="email" placeholder="%3$s">',
				esc_attr( $id ),
				esc_html__( 'Email address', 'flexacommerce' ),
				esc_attr__( 'Your email address', 'flexacommerce' )
			);
		}
		$html .= '<button type="submit" class="wp-element-button fxc-stock-alert__button">' . esc_html__( 'Notify me', 'flexacommerce' ) . '</button>';
		$html .= '</div>';
		$html .= FormHandler::notice_for( $product->id );
		return $html . '</form>';
	}

	/** The block inside the add-to-cart form, shown while the picked variation is out of stock. */
	public static function inline( Product $product, int $user_id ): string {
		$settings = StockAlertSettings::get();
		if ( ! $settings['enabled'] ) {
			return '';
		}
		$html  = '<div class="fxc-stock-alert fxc-stock-alert--inline" data-wp-bind--hidden="!context.alertId" hidden>';
		$html .= '<p class="fxc-stock-alert__title">' . esc_html__( 'Email me when it is back in stock', 'flexacommerce' ) . '</p>';
		if ( $user_id === 0 && ! $settings['guests'] ) {
			return $html . self::login( $product ) . '</div>';
		}
		$html .= '<div class="fxc-stock-alert__row">';
		if ( $user_id === 0 ) {
			$id    = wp_unique_id( 'fxc-alert-' );
			$html .= sprintf(
				'<label class="screen-reader-text" for="%1$s">%2$s</label><input class="fxc-stock-alert__email" id="%1$s" type="email" autocomplete="email" placeholder="%3$s" data-wp-bind--value="context.alertEmail" data-wp-on--input="actions.setAlertEmail">',
				esc_attr( $id ),
				esc_html__( 'Email address', 'flexacommerce' ),
				esc_attr__( 'Your email address', 'flexacommerce' )
			);
		}
		$html .= '<button type="button" class="wp-element-button fxc-stock-alert__button" data-wp-on--click="actions.notify" data-wp-class--is-busy="context.busy">' . esc_html__( 'Notify me', 'flexacommerce' ) . '</button>';
		$html .= '</div>';
		$html .= '<p class="fxc-stock-alert__message" role="status" aria-live="polite" data-wp-text="context.alertMessage" data-wp-class--is-error="context.alertFailed"></p>';
		return $html . '</div>';
	}

	private static function login( Product $product ): string {
		return sprintf(
			'<p class="fxc-stock-alert__login"><a href="%1$s">%2$s</a></p>',
			esc_url( wp_login_url( (string) get_permalink( $product->id ) ) ),
			esc_html__( 'Log in to get an email when it is back in stock.', 'flexacommerce' )
		);
	}
}
