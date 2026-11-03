<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\StockAlerts\StockAlertService;

defined( 'ABSPATH' ) || exit;

/**
 * The stop link of a back-in-stock email (ADR-065). `?fxcm_stock_stop=<key>`
 * asks first and cancels the address's waiting alerts on the posted
 * answer, because mail scanners open every link in a message.
 */
final class StockAlertHandler {
	use HasInstance;

	/** The stop form's nonce action. */
	public const NONCE = 'fxcm_stock_stop';

	public function handle(): void {
		nocache_headers();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key is the credential; the link comes from the email.
		$key     = isset( $_GET[ StockAlertService::STOP_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ StockAlertService::STOP_PARAM ] ) ) : '';
		$service = Plugin::instance()->stock_alerts();
		$title   = __( 'Back-in-stock emails', 'flexacommerce' );
		if ( $service->verify( $key ) === null ) {
			wp_die( esc_html__( 'This link is not valid.', 'flexacommerce' ), esc_html( $title ), [ 'response' => 404 ] );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( $method === 'POST' ) {
			check_admin_referer( self::NONCE );
			$service->unsubscribe( $key );
			wp_die(
				'<p>' . esc_html__( 'You will get no more back-in-stock emails for the products you asked about.', 'flexacommerce' ) . '</p>'
				. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the shop', 'flexacommerce' ) . '</a></p>',
				esc_html( $title ),
				[ 'response' => 200 ]
			);
		}
		wp_die(
			'<form method="post" action="' . esc_url( add_query_arg( StockAlertService::STOP_PARAM, $key, home_url( '/' ) ) ) . '">'
			. '<p>' . esc_html__( 'Stop the emails about products you asked to hear about when they are back in stock?', 'flexacommerce' ) . '</p>'
			. wp_nonce_field( self::NONCE, '_wpnonce', false, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core returns escaped markup.
			. '<p><button type="submit" class="button">' . esc_html__( 'Stop back-in-stock emails', 'flexacommerce' ) . '</button></p>'
			. '</form>',
			esc_html( $title ),
			[ 'response' => 200 ]
		);
	}
}
