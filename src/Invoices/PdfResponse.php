<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices;

defined( 'ABSPATH' ) || exit;

/** Sends a made PDF to the browser, shown inline so it opens in a tab. */
final class PdfResponse {
	public static function send( string $bytes, string $filename ): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes.
	}
}
