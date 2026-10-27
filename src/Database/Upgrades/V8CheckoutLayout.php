<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database\Upgrades;

use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * DB_VERSION 8 (ADR-031): the settings `checkout_company`, `checkout_phone`
 * and `checkout_address_2` move into the checkout layout option, where the
 * builder shows them on the fields themselves. The layout lists every
 * field in today's order; CheckoutLayout fills in the rest of each entry.
 */
final class V8CheckoutLayout implements UpgradeStep {
	private const LAYOUT = 'flexacommerce_checkout_layout';

	/** setting => [ field, answer before the builder ] */
	private const KEYS = [
		'checkout_company'   => [ 'company', 'hidden' ],
		'checkout_phone'     => [ 'phone', 'optional' ],
		'checkout_address_2' => [ 'address_2', 'optional' ],
	];

	private const ORDER = [ 'email', 'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' ];

	public function run(): void {
		$stored = get_option( Settings::OPTION, [] );
		if ( ! is_array( $stored ) || array_intersect_key( $stored, self::KEYS ) === [] ) {
			return;
		}
		$answers = [];
		foreach ( self::KEYS as $key => [ $field, $before ] ) {
			$value = $stored[ $key ] ?? null;
			if ( is_string( $value ) && $value !== $before && in_array( $value, [ 'hidden', 'optional', 'required' ], true ) ) {
				$answers[ $field ] = $value;
			}
		}
		if ( $answers !== [] && get_option( self::LAYOUT, null ) === null ) {
			$fields = [];
			foreach ( [ 'billing', 'shipping' ] as $section ) {
				foreach ( self::ORDER as $id ) {
					if ( $id === 'email' && $section === 'shipping' ) {
						continue;
					}
					$fields[ $section ][] = isset( $answers[ $id ] )
						? [
							'id'     => $id,
							'answer' => $answers[ $id ],
						]
						: [ 'id' => $id ];
				}
			}
			update_option( self::LAYOUT, [ 'fields' => $fields ], false );
		}
		update_option( Settings::OPTION, array_diff_key( $stored, self::KEYS ), true );
	}
}
