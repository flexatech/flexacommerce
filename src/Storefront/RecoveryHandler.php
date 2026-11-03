<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Recovery\RecoveryService;

defined( 'ABSPATH' ) || exit;

/**
 * The two links of a cart reminder (ADR-063). `?fxcm_recover=<key>` puts the
 * left cart back and goes to the checkout. `?fxcm_recover_stop=<key>` asks
 * first and stops the reminders on the posted answer, because mail
 * scanners open every link in a message.
 */
final class RecoveryHandler {
	use HasInstance;

	/** The stop form's nonce action. */
	public const NONCE = 'fxcm_recover_stop';

	public function handle(): void {
		nocache_headers();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the key is the credential; links come from the reminder email.
		$recover = isset( $_GET[ RecoveryService::RECOVER_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ RecoveryService::RECOVER_PARAM ] ) ) : '';
		$stop    = isset( $_GET[ RecoveryService::STOP_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ RecoveryService::STOP_PARAM ] ) ) : '';
		// phpcs:enable
		if ( $stop !== '' ) {
			$this->stop( $stop );
			return;
		}
		$service = Plugin::instance()->recovery();
		$ok      = false;
		try {
			$ok = $service->recover( $recover, get_current_user_id() );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		$url = $ok ? Plugin::instance()->checkout()->pages()->checkout_url() : Assets::cart_url();
		wp_safe_redirect( $url, 302, 'FlexaCommerce' );
		exit;
	}

	private function stop( string $key ): void {
		$service = Plugin::instance()->recovery();
		$title   = __( 'Cart reminders', 'flexacommerce' );
		if ( $service->verify( $key ) === null ) {
			wp_die( esc_html__( 'This link is not valid.', 'flexacommerce' ), esc_html( $title ), [ 'response' => 404 ] );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( $method === 'POST' ) {
			check_admin_referer( self::NONCE );
			$service->unsubscribe( $key );
			wp_die(
				'<p>' . esc_html__( 'You will get no more reminders about carts you leave in this shop.', 'flexacommerce' ) . '</p>'
				. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the shop', 'flexacommerce' ) . '</a></p>',
				esc_html( $title ),
				[ 'response' => 200 ]
			);
		}
		wp_die(
			'<form method="post" action="' . esc_url( add_query_arg( RecoveryService::STOP_PARAM, $key, home_url( '/' ) ) ) . '">'
			. '<p>' . esc_html__( 'Stop the emails we send when you leave items in your cart?', 'flexacommerce' ) . '</p>'
			. wp_nonce_field( self::NONCE, '_wpnonce', false, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core returns escaped markup.
			. '<p><button type="submit" class="button">' . esc_html__( 'Stop cart reminders', 'flexacommerce' ) . '</button></p>'
			. '</form>',
			esc_html( $title ),
			[ 'response' => 200 ]
		);
	}
}
