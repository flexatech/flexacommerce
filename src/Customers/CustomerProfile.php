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

	/** Every saved address of this site, for the eraser. */
	public static function delete_all(): void {
		global $wpdb;
		foreach ( self::META as $key ) {
			delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . $key, '', true );
		}
	}

	public function delete( int $user_id ): void {
		foreach ( self::META as $key ) {
			delete_user_option( $user_id, $key );
		}
	}
}
