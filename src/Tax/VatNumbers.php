<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\TaxSettings;

defined( 'ABSPATH' ) || exit;

/**
 * EU VAT numbers at checkout (ADR-086): normalize, check the pattern, ask
 * VIES and decide the reverse charge. Answers are cached in transients
 * (a week when valid, a day when not registered, ten minutes when VIES
 * did not answer), so a requote while typing calls VIES once per number.
 */
final class VatNumbers {
	public const VIES_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

	private const CACHE   = 'fxcm_vat_';
	private const TIMEOUT = 6;
	private const TTL     = [
		VatCheck::VALID      => WEEK_IN_SECONDS,
		VatCheck::INVALID    => DAY_IN_SECONDS,
		VatCheck::UNVERIFIED => 10 * MINUTE_IN_SECONDS,
	];

	/** ISO country => VIES prefix and the number after the prefix. */
	private const PATTERNS = [
		'AT' => [ 'AT', 'U\d{8}' ],
		'BE' => [ 'BE', '[01]\d{9}' ],
		'BG' => [ 'BG', '\d{9,10}' ],
		'CY' => [ 'CY', '\d{8}[A-Z]' ],
		'CZ' => [ 'CZ', '\d{8,10}' ],
		'DE' => [ 'DE', '\d{9}' ],
		'DK' => [ 'DK', '\d{8}' ],
		'EE' => [ 'EE', '\d{9}' ],
		'GR' => [ 'EL', '\d{9}' ],
		'ES' => [ 'ES', '[A-Z0-9]\d{7}[A-Z0-9]' ],
		'FI' => [ 'FI', '\d{8}' ],
		'FR' => [ 'FR', '[A-HJ-NP-Z0-9]{2}\d{9}' ],
		'HR' => [ 'HR', '\d{11}' ],
		'HU' => [ 'HU', '\d{8}' ],
		'IE' => [ 'IE', '(\d{7}[A-W][A-IW]?|\d[A-Z+*]\d{5}[A-W])' ],
		'IT' => [ 'IT', '\d{11}' ],
		'LT' => [ 'LT', '(\d{9}|\d{12})' ],
		'LU' => [ 'LU', '\d{8}' ],
		'LV' => [ 'LV', '\d{11}' ],
		'MT' => [ 'MT', '\d{8}' ],
		'NL' => [ 'NL', '\d{9}B\d{2}' ],
		'PL' => [ 'PL', '\d{10}' ],
		'PT' => [ 'PT', '\d{9}' ],
		'RO' => [ 'RO', '[1-9]\d{1,9}' ],
		'SE' => [ 'SE', '\d{10}01' ],
		'SI' => [ 'SI', '\d{8}' ],
		'SK' => [ 'SK', '\d{10}' ],
	];

	public function __construct(
		private readonly RateLimiter $limiter,
		private readonly Clock $clock = new Clock(),
	) {}

	/** @return list<string> ISO codes of the EU member states */
	public static function countries(): array {
		return array_keys( self::PATTERNS );
	}

	public static function eu( string $country ): bool {
		return isset( self::PATTERNS[ strtoupper( $country ) ] );
	}

	/** True when checkout asks for a VAT number. */
	public static function enabled(): bool {
		return TaxSettings::vat_numbers();
	}

	/** Upper case, without spaces, dots and dashes. */
	public static function normalize( string $number ): string {
		return strtoupper( (string) preg_replace( '/[\s.\-]+/', '', $number ) );
	}

	/**
	 * Check a number for a billing country. Null when there is nothing to
	 * check: no number, or a billing country outside the EU.
	 *
	 * @param string $subject who asks, for the `vat_ip` limit; '' skips the limit
	 * @throws RateLimited when the subject asked VIES too often
	 */
	public function check( string $number, string $country, string $subject = '' ): ?VatCheck {
		$country = strtoupper( $country );
		$number  = self::normalize( $number );
		if ( $number === '' || ! self::eu( $country ) ) {
			return null;
		}
		[ $prefix, $pattern ] = self::PATTERNS[ $country ];
		if ( $country === 'GR' && str_starts_with( $number, 'GR' ) ) {
			$number = 'EL' . substr( $number, 2 );
		}
		// Another member state's prefix, unless the body itself starts with letters that look like one (FR, ES).
		$lead = substr( $number, 0, 2 );
		if ( $lead !== $prefix && in_array( $lead, array_column( self::PATTERNS, 0 ), true ) && preg_match( '/^' . $pattern . '$/', $number ) !== 1 ) {
			return $this->result( $number, $country, VatCheck::MISMATCH );
		}
		$body = str_starts_with( $number, $prefix ) ? substr( $number, 2 ) : $number;
		if ( preg_match( '/^' . $pattern . '$/', $body ) !== 1 ) {
			return $this->result( $prefix . $body, $country, VatCheck::MALFORMED );
		}
		$full = $prefix . $body;
		if ( Settings::string( 'vat_check' ) !== 'vies' ) {
			return $this->result( $full, $country, VatCheck::WELL_FORMED );
		}
		$cached = get_transient( self::CACHE . md5( $full ) );
		$cached = is_array( $cached ) ? VatCheck::from_array( $cached ) : null;
		if ( $cached !== null ) {
			return $cached;
		}
		$this->limiter->hit( 'vat_ip', $subject );
		$check = $this->vies( $prefix, $body, $country );
		set_transient( self::CACHE . md5( $full ), $check->to_array(), self::TTL[ $check->status ] ?? MINUTE_IN_SECONDS );
		return $check;
	}

	/**
	 * VAT comes off when the buyer is a business registered in another
	 * member state than the store.
	 */
	public function reverse_charge( ?VatCheck $check ): bool {
		if ( $check === null || ! self::enabled() ) {
			return false;
		}
		$accepted = match ( $check->status ) {
			VatCheck::VALID, VatCheck::WELL_FORMED => true,
			VatCheck::UNVERIFIED                   => Settings::string( 'vat_unverified' ) === 'accept',
			default                                => false,
		};
		return $accepted && $check->country !== Settings::string( 'store_country' );
	}

	private function vies( string $prefix, string $body, string $country ): VatCheck {
		$response = wp_remote_get(
			sprintf( self::VIES_URL, $prefix, rawurlencode( $body ) ),
			[
				'timeout' => self::TIMEOUT,
				'headers' => [ 'Accept' => 'application/json' ],
			]
		);
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $this->result( $prefix . $body, $country, VatCheck::UNVERIFIED );
		}
		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$data  = is_array( $data ) ? $data : [];
		$error = is_string( $data['userError'] ?? null ) ? $data['userError'] : '';
		if ( ( $data['isValid'] ?? null ) === true || $error === 'VALID' ) {
			return $this->result( $prefix . $body, $country, VatCheck::VALID, self::text( $data['name'] ?? '' ), self::text( $data['address'] ?? '' ) );
		}
		if ( ( $data['isValid'] ?? null ) === false && ( $error === 'INVALID' || $error === '' ) ) {
			return $this->result( $prefix . $body, $country, VatCheck::INVALID );
		}
		return $this->result( $prefix . $body, $country, VatCheck::UNVERIFIED );
	}

	private function result( string $number, string $country, string $status, string $name = '', string $address = '' ): VatCheck {
		return new VatCheck( $number, $country, $status, $name, $address, $this->clock->now()->getTimestamp() );
	}

	/** VIES answers "---" when a member state does not share the name. */
	private static function text( mixed $value ): string {
		$text = is_string( $value ) ? trim( sanitize_textarea_field( $value ) ) : '';
		return $text === '---' ? '' : mb_substr( $text, 0, 300 );
	}
}
