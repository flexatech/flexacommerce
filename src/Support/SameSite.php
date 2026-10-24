<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Same-site check for state-changing requests (doc 06 §2), shared by the
 * store REST routes and the no-JS cart forms.
 */
final class SameSite {
	/**
	 * Origin, else Referer, must name this site. A request with neither
	 * cannot come from a browser page on another site, so it passes; the
	 * nonce still applies. `Origin: null` (sandboxed frames, some redirects)
	 * is refused.
	 */
	public static function check( string $origin, string $referer ): bool {
		if ( $origin === 'null' ) {
			return false;
		}
		$source = $origin !== '' ? $origin : $referer;
		if ( $source === '' ) {
			return true;
		}
		$host = wp_parse_url( $source, PHP_URL_HOST );
		if ( ! is_string( $host ) || $host === '' ) {
			return false;
		}
		$allowed = array_filter(
			[
				wp_parse_url( home_url(), PHP_URL_HOST ),
				wp_parse_url( site_url(), PHP_URL_HOST ),
			]
		);
		/** Hosts allowed to send store writes, for headless front ends. */
		$allowed = (array) apply_filters( 'flexacommerce.rest.store.allowed_hosts', array_values( array_unique( $allowed ) ) );
		return in_array( strtolower( $host ), array_map( 'strtolower', array_filter( $allowed, 'is_string' ) ), true );
	}

	/** The same check on the current request's headers. */
	public static function current(): bool {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the host is parsed and compared.
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		// phpcs:enable
		return self::check( $origin, $referer );
	}
}
