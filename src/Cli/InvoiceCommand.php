<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Invoices\InvoiceRenderer;
use Flexa\Commerce\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce invoice <order-id>... [--file=<path>] [--packing-slip]
 *
 * The same PDF as the order screen (ADR-060). An order without an invoice
 * gets its number now, as it would from the admin.
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
	 * ## EXAMPLES
	 *
	 *     wp flexacommerce invoice 1042
	 *     wp flexacommerce invoice 1042 1043 --packing-slip --file=/tmp/slips.pdf
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
		$bytes = $service->pdf( $orders, $document );
		$file  = isset( $assoc_args['file'] ) && is_string( $assoc_args['file'] ) && $assoc_args['file'] !== '' ? $assoc_args['file'] : getcwd() . '/' . $service->filename( $orders, $document );
		if ( file_put_contents( $file, $bytes ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a file the user asked for.
			WP_CLI::error( sprintf( 'Could not write %s.', $file ) );
		}
		WP_CLI::success( sprintf( 'Wrote %s (%s).', $file, size_format( strlen( $bytes ) ) ) );
	}
}
