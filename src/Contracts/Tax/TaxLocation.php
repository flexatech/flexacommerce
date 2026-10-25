<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * Where tax is charged, normalized as doc 02 §7.12.3 says: country and state
 * upper-case, postcode upper-case without spaces or dashes (US ZIP+4 cut to
 * five digits), city upper-case.
 *
 * @api
 */
final class TaxLocation {
	public readonly string $country;
	public readonly string $state;
	public readonly string $postcode;
	public readonly string $city;

	public function __construct( string $country, string $state = '', string $postcode = '', string $city = '' ) {
		$this->country  = strtoupper( trim( $country ) );
		$this->state    = strtoupper( trim( $state ) );
		$this->postcode = self::postcode( $this->country, $postcode );
		$this->city     = mb_strtoupper( trim( $city ) );
	}

	public static function postcode( string $country, string $postcode ): string {
		$code = strtoupper( (string) preg_replace( '/[\s\-]/', '', $postcode ) );
		if ( $country === 'US' && preg_match( '/^(\d{5})\d{4}$/', $code, $m ) === 1 ) {
			return $m[1];
		}
		return $code;
	}

	/** Stable key for caches. */
	public function key(): string {
		return implode( '|', [ $this->country, $this->state, $this->postcode, $this->city ] );
	}

	public function equals( self $other ): bool {
		return $this->key() === $other->key();
	}

	/** @return array{country:string,state:string,postcode:string,city:string} */
	public function to_array(): array {
		return [
			'country'  => $this->country,
			'state'    => $this->state,
			'postcode' => $this->postcode,
			'city'     => $this->city,
		];
	}
}
