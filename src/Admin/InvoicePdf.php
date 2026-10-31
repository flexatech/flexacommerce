<?php

declare(strict_types=1);

namespace Flexa\Commerce\Admin;

use Flexa\Commerce\Invoices\InvoiceRenderer;
use Flexa\Commerce\Invoices\PdfResponse;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=fxc_invoice&orders=1,2&document=invoice` (ADR-060):
 * the invoices or packing slips of up to 100 orders in one PDF, for staff
 * who may view orders. The admin app signs the link with its REST nonce.
 */
final class InvoicePdf {
	public const ACTION = 'fxc_invoice';

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( wp_verify_nonce( $nonce, 'wp_rest' ) === false || ! Capabilities::can_view_orders() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'flexacommerce' ), '', [ 'response' => 403 ] );
		}
		$ids      = isset( $_GET['orders'] ) ? array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( (string) $_GET['orders'] ) ) ) ) : [];
		$document = isset( $_GET['document'] ) && $_GET['document'] === InvoiceRenderer::PACKING_SLIP ? InvoiceRenderer::PACKING_SLIP : InvoiceRenderer::INVOICE;
		// phpcs:enable
		$service = Plugin::instance()->invoices();
		$orders  = $service->orders( $ids );
		if ( $orders === [] ) {
			wp_die( esc_html__( 'No orders were found.', 'flexacommerce' ), '', [ 'response' => 404 ] );
		}
		try {
			$bytes = $service->pdf( $orders, $document );
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ), '', [ 'response' => 500 ] );
		}
		PdfResponse::send( $bytes, $service->filename( $orders, $document ) );
		exit;
	}
}
