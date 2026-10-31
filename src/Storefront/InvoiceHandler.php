<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Invoices\PdfResponse;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderAccess;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Serves `?fxc_invoice=<order id>&key=<order key>` (ADR-060): the
 * customer's invoice, to the holder of the order key or the signed-in
 * owner, when the invoice settings let customers see it. Staff who may
 * view orders always get it.
 */
final class InvoiceHandler {
	use HasInstance;

	/** Literal in Plugin::boot(), which must not load this class on other requests. */
	public const PARAM = 'fxc_invoice';

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key is the credential; links come from emails and the order page.
		$order_id = isset( $_GET[ self::PARAM ] ) ? absint( wp_unslash( $_GET[ self::PARAM ] ) ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : '';
		// phpcs:enable
		$order = $this->allowed( $order_id, $key, get_current_user_id() );
		if ( $order === null ) {
			nocache_headers();
			wp_die( esc_html__( 'This invoice is not available.', 'flexacommerce' ), esc_html__( 'Invoice', 'flexacommerce' ), [ 'response' => 404 ] );
		}
		$service = Plugin::instance()->invoices();
		try {
			$bytes = $service->pdf( [ $order ] );
		} catch ( \Throwable $e ) {
			nocache_headers();
			wp_die( esc_html__( 'The invoice could not be made. Please try again.', 'flexacommerce' ), esc_html__( 'Invoice', 'flexacommerce' ), [ 'response' => 503 ] );
		}
		PdfResponse::send( $bytes, $service->filename( [ $order ] ) );
		exit;
	}

	/** The order when this visitor may see its invoice, so tests can check it. */
	public function allowed( int $order_id, string $key, int $user_id ): ?Order {
		$order = $order_id > 0 ? Plugin::instance()->orders()->orders()->find( $order_id ) : null;
		if ( $order === null ) {
			return null;
		}
		if ( $user_id > 0 && Capabilities::can_view_orders() ) {
			return $order;
		}
		if ( ! OrderAccess::can_view( $order, $key, $user_id ) ) {
			return null;
		}
		return Plugin::instance()->invoices()->customer_can( $order ) ? $order : null;
	}
}
