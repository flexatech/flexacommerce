<?php

declare(strict_types=1);

namespace Flexa\Commerce\Admin;

use Flexa\Commerce\Invoices\InvoiceRenderer;
use Flexa\Commerce\Invoices\PdfResponse;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=fxcm_invoice&orders=1,2&document=invoice` (ADR-060):
 * the invoices or packing slips of up to 100 orders in one PDF, for staff
 * who may view orders. The admin app signs the link with its REST nonce.
 * `document=credit_note&refund=<id>` gives the credit note of one refund
 * of the first order (ADR-064).
 */
final class InvoicePdf {
	public const ACTION = 'fxcm_invoice';

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( wp_verify_nonce( $nonce, 'wp_rest' ) === false || ! Capabilities::can_view_orders() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'flexacommerce' ), '', [ 'response' => 403 ] );
		}
		$ids      = isset( $_GET['orders'] ) ? array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( (string) $_GET['orders'] ) ) ) ) : [];
		$document = isset( $_GET['document'] ) ? sanitize_key( wp_unslash( (string) $_GET['document'] ) ) : '';
		$refund   = isset( $_GET['refund'] ) ? absint( wp_unslash( $_GET['refund'] ) ) : 0;
		// phpcs:enable
		$service = Plugin::instance()->invoices();
		$orders  = $service->orders( $ids );
		if ( $orders === [] ) {
			wp_die( esc_html__( 'No orders were found.', 'flexacommerce' ), '', [ 'response' => 404 ] );
		}
		if ( $document === InvoiceRenderer::CREDIT_NOTE ) {
			$txn = $service->refund( $orders[0], $refund );
			if ( $txn === null ) {
				wp_die( esc_html__( 'This refund was not found.', 'flexacommerce' ), '', [ 'response' => 404 ] );
			}
			try {
				$bytes = $service->credit_note_pdf( $orders[0], $txn );
			} catch ( \Throwable $e ) {
				wp_die( esc_html( $e->getMessage() ), '', [ 'response' => 500 ] );
			}
			PdfResponse::send( $bytes, $service->credit_filename( $service->create_credit_note( $orders[0], $txn ) ) );
			exit;
		}
		$document = $document === InvoiceRenderer::PACKING_SLIP ? InvoiceRenderer::PACKING_SLIP : InvoiceRenderer::INVOICE;
		try {
			$bytes = $service->pdf( $orders, $document );
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ), '', [ 'response' => 500 ] );
		}
		PdfResponse::send( $bytes, $service->filename( $orders, $document ) );
		exit;
	}
}
