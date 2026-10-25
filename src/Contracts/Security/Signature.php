<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Constant-time signature checks for gateway webhooks (doc 04 §3.4).
 *
 * @api
 */
final class Signature {
	/** @param string $expected_hex the signature the sender put in the header, hex encoded */
	public static function hmac_sha256_equals( string $payload, string $secret, string $expected_hex ): bool {
		if ( $secret === '' || $expected_hex === '' ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $payload, $secret ), strtolower( trim( $expected_hex ) ) );
	}
}
