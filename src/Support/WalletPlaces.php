<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Where wallet buttons may show (ADR-053), written when a gateway's
 * settings are saved, so a catalog page asks one autoloaded option
 * instead of building the gateways (O7). `[gateway id => list of places]`;
 * the Activator seeds it empty, so a store without wallets pays no query.
 */
final class WalletPlaces {
	public const OPTION = 'flexacommerce_wallet_places';

	/** @param list<string> $places */
	public static function put( string $gateway, array $places ): void {
		$all = self::all();
		if ( $places === [] ) {
			unset( $all[ $gateway ] );
		} else {
			$all[ $gateway ] = $places;
		}
		update_option( self::OPTION, $all, true );
	}

	public static function on( string $place ): bool {
		foreach ( self::all() as $places ) {
			if ( in_array( $place, $places, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string,list<string>> */
	private static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$all    = [];
		foreach ( is_array( $stored ) ? $stored : [] as $id => $places ) {
			if ( is_string( $id ) && is_array( $places ) ) {
				$all[ $id ] = array_values( array_filter( $places, 'is_string' ) );
			}
		}
		return $all;
	}
}
