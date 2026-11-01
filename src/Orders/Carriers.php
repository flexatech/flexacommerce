<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * The carriers the tracking form offers, each with the link that opens a
 * tracking number on its site (ADR-067). `%s` in the link is the number.
 * `flexacommerce.shipment.carriers` adds or replaces carriers; a carrier
 * not in the list is typed in by hand with its own link.
 */
final class Carriers {
	private const BUILT_IN = [
		'ups'            => [ 'UPS', 'https://www.ups.com/track?tracknum=%s' ],
		'usps'           => [ 'USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s' ],
		'fedex'          => [ 'FedEx', 'https://www.fedex.com/fedextrack/?trknbr=%s' ],
		'dhl'            => [ 'DHL Express', 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id=%s' ],
		'dhl_ecommerce'  => [ 'DHL eCommerce', 'https://webtrack.dhlecs.com/?trackingnumber=%s' ],
		'canada_post'    => [ 'Canada Post', 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=%s' ],
		'royal_mail'     => [ 'Royal Mail', 'https://www.royalmail.com/track-your-item#/tracking-results/%s' ],
		'australia_post' => [ 'Australia Post', 'https://auspost.com.au/mypost/track/details/%s' ],
	];

	/** @var array<string,array{name:string,url:string}>|null */
	private static ?array $all = null;

	/** @return array<string,array{name:string,url:string}> key => name and link pattern */
	public static function all(): array {
		if ( self::$all !== null ) {
			return self::$all;
		}
		$carriers = [];
		foreach ( self::BUILT_IN as $key => [ $name, $url ] ) {
			$carriers[ $key ] = [
				'name' => $name,
				'url'  => $url,
			];
		}
		/**
		 * The carriers of the tracking form (ADR-067): key => {name, url},
		 * where `%s` in url is the tracking number.
		 *
		 * @param array<mixed> $carriers
		 */
		$filtered = (array) apply_filters( 'flexacommerce.shipment.carriers', $carriers );
		$out      = [];
		foreach ( $filtered as $key => $carrier ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : '';
			if ( $key === '' || strlen( $key ) > 40 || ! is_array( $carrier ) || ! is_string( $carrier['name'] ?? null ) || trim( $carrier['name'] ) === '' ) {
				continue;
			}
			$out[ $key ] = [
				'name' => mb_substr( trim( $carrier['name'] ), 0, 100 ),
				'url'  => is_string( $carrier['url'] ?? null ) ? $carrier['url'] : '',
			];
		}
		self::$all = $out;
		return $out;
	}

	/** @return array{name:string,url:string}|null */
	public static function get( string $key ): ?array {
		return $key === '' ? null : ( self::all()[ $key ] ?? null );
	}

	/** The tracking link of a number at a known carrier; '' without a number or a link pattern. */
	public static function url( string $key, string $number ): string {
		$pattern = self::get( $key )['url'] ?? '';
		if ( $number === '' || $pattern === '' ) {
			return '';
		}
		return str_contains( $pattern, '%s' ) ? str_replace( '%s', rawurlencode( $number ), $pattern ) : $pattern;
	}

	/** Forget the filtered list; tests change the filter. */
	public static function reset(): void {
		self::$all = null;
	}
}
