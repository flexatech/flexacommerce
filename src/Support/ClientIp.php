<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The visitor's IP (doc 06 §2.9). `REMOTE_ADDR` unless the site names a
 * trusted proxy header with `flexacommerce.client_ip_header` (for example
 * `HTTP_X_FORWARDED_FOR` behind a load balancer); forwarded headers are
 * never trusted on their own. Empty when no valid address is found.
 *
 * Each proxy appends the address it saw, so the left end of a forwarded
 * list is whatever the client sent. The client is the right-most public
 * address; private and reserved hops (internal proxies) are skipped.
 */
final class ClientIp {
	public static function get(): string {
		$header = (string) apply_filters( 'flexacommerce.client_ip_header', '' );
		if ( $header !== '' && isset( $_SERVER[ $header ] ) ) {
			$valid = array_values(
				array_filter(
					array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER[ $header ] ) ) ) ),
					static fn ( string $candidate ): bool => filter_var( $candidate, FILTER_VALIDATE_IP ) !== false
				)
			);
			foreach ( array_reverse( $valid ) as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
					return $candidate;
				}
			}
			if ( $valid !== [] ) {
				return $valid[ count( $valid ) - 1 ];
			}
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) !== false ? $ip : '';
	}
}
