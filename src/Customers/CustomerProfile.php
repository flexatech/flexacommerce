<?php

declare(strict_types=1);

namespace Flexa\Commerce\Customers;

use Flexa\Commerce\Contracts\Orders\AddressView;

defined( 'ABSPATH' ) || exit;

/**
 * The saved addresses of an account holder (doc 03 §7): user options
 * `fxc_billing` and `fxc_shipping`, JSON with the fields of an order
 * address. User options carry the site's table prefix, so on a network
 * each store keeps its own addresses for the same user. Checkout fills them in for a logged-in customer and saves the
 * ones used; the account page edits them.
 */
final class CustomerProfile {
	public const TYPES = [ 'billing', 'shipping' ];

	public const META = [
		'billing'  => 'fxc_billing',
		'shipping' => 'fxc_shipping',
	];

	/** User option set to '1' for an account the store does not charge tax (doc 02 §7.12). */
	public const TAX_EXEMPT_META = 'fxc_tax_exempt';

	public function address( int $user_id, string $type ): ?AddressView {
		if ( $user_id <= 0 || ! isset( self::META[ $type ] ) ) {
			return null;
		}
		$raw  = get_user_option( self::META[ $type ], $user_id );
		$data = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}
		$field = static fn ( string $key ): string => is_scalar( $data[ $key ] ?? null ) ? (string) $data[ $key ] : '';
		return new AddressView(
			type: $type,
			first_name: $field( 'first_name' ),
			last_name: $field( 'last_name' ),
			company: $field( 'company' ),
			address_1: $field( 'address_1' ),
			address_2: $field( 'address_2' ),
			city: $field( 'city' ),
			state: $field( 'state' ),
			postcode: $field( 'postcode' ),
			country: $field( 'country' ),
			email: $type === 'billing' ? $field( 'email' ) : '',
			phone: $field( 'phone' ),
		);
	}

	/** @return array<string,AddressView> type => address, saved ones only */
	public function addresses( int $user_id ): array {
		$out = [];
		foreach ( self::TYPES as $type ) {
			$address = $this->address( $user_id, $type );
			if ( $address !== null ) {
				$out[ $type ] = $address;
			}
		}
		return $out;
	}

	public function save( int $user_id, AddressView $address ): void {
		if ( $user_id <= 0 || ! isset( self::META[ $address->type ] ) ) {
			return;
		}
		$data = $address->to_array();
		if ( $address->type === 'shipping' ) {
			unset( $data['email'] );
		}
		$json = wp_json_encode( $data );
		if ( ! is_string( $json ) ) {
			return;
		}
		update_user_option( $user_id, self::META[ $address->type ], wp_slash( $json ) );
		do_action( 'flexacommerce.customer.address_saved', $user_id, $address );
	}

	public function tax_exempt( int $user_id ): bool {
		return $user_id > 0 && get_user_option( self::TAX_EXEMPT_META, $user_id ) === '1';
	}

	public function set_tax_exempt( int $user_id, bool $exempt ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		if ( $exempt ) {
			update_user_option( $user_id, self::TAX_EXEMPT_META, '1' );
		} else {
			delete_user_option( $user_id, self::TAX_EXEMPT_META );
		}
		do_action( 'flexacommerce.customer.tax_exempt_changed', $user_id, $exempt );
	}

	/** Every saved address and tax-exempt flag of this site, for the eraser. */
	public static function delete_all(): void {
		global $wpdb;
		foreach ( [ ...array_values( self::META ), self::TAX_EXEMPT_META ] as $key ) {
			delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . $key, '', true );
		}
	}

	public function delete( int $user_id ): void {
		foreach ( self::META as $key ) {
			delete_user_option( $user_id, $key );
		}
	}
}
