<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Invoices\InvoiceRenderer;
use Flexa\Commerce\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce invoice <order-id>... [--file=<path>] [--packing-slip] [--credit-note=<refund-id>]
 *
 * The same PDF as the order screen (ADR-060). An order without an invoice
 * gets its number now, as it would from the admin; so does a refund
 * without a credit note (ADR-064).
 */
final class InvoiceCommand {
	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		WP_CLI::add_command( 'flexacommerce invoice', [ new self(), 'invoice' ] );
	}

	/**
	 * Writes the invoices or packing slips of orders to one PDF file.
	 *
	 * ## OPTIONS
	 *
	 * <order-id>...
	 * : One or more order ids (up to 100).
	 *
	 * [--file=<path>]
	 * : Where to write the PDF. Defaults to the invoice name in the current folder.
	 *
	 * [--packing-slip]
	 * : Packing slips (no prices) instead of invoices.
	 *
	 * [--credit-note=<refund-id>]
	 * : The credit note of this refund of the order instead (one order only).
	 *
	 * ## EXAMPLES
	 *
	 *     wp flexacommerce invoice 1042
	 *     wp flexacommerce invoice 1042 1043 --packing-slip --file=/tmp/slips.pdf
	 *     wp flexacommerce invoice 1042 --credit-note=87
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function invoice( array $args, array $assoc_args ): void {
		$service  = Plugin::instance()->invoices();
		$document = ! empty( $assoc_args['packing-slip'] ) ? InvoiceRenderer::PACKING_SLIP : InvoiceRenderer::INVOICE;
		$ids      = array_map( 'intval', $args );
		$orders   = $service->orders( $ids );
		if ( count( $orders ) !== count( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) ) ) {
			$found = array_map( static fn ( $order ): int => $order->id, $orders );
			WP_CLI::error( sprintf( 'Order not found: %s.', implode( ', ', array_diff( $ids, $found ) ) ) );
		}
		$name = $service->filename( $orders, $document );
		if ( isset( $assoc_args['credit-note'] ) ) {
			if ( count( $orders ) !== 1 ) {
				WP_CLI::error( 'A credit note takes one order id.' );
			}
			$refund = $service->refund( $orders[0], (int) $assoc_args['credit-note'] );
			if ( $refund === null ) {
				WP_CLI::error( sprintf( 'Order %d has no succeeded refund %d.', $orders[0]->id, (int) $assoc_args['credit-note'] ) );
			}
			$bytes = $service->credit_note_pdf( $orders[0], $refund );
			$name  = $service->credit_filename( $service->create_credit_note( $orders[0], $refund ) );
		} else {
			$bytes = $service->pdf( $orders, $document );
		}
		$file = isset( $assoc_args['file'] ) && is_string( $assoc_args['file'] ) && $assoc_args['file'] !== '' ? $assoc_args['file'] : getcwd() . '/' . $name;
		if ( file_put_contents( $file, $bytes ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a file the user asked for.
			WP_CLI::error( sprintf( 'Could not write %s.', $file ) );
		}
		WP_CLI::success( sprintf( 'Wrote %s (%s).', $file, size_format( strlen( $bytes ) ) ) );
	}
}
