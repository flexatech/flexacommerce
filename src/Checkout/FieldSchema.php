<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The fields of one address form for one country (doc 04 §6). Core fields
 * map to order address columns; any other field an extension adds through
 * `flexacommerce.checkout.fields` is stored in order meta as
 * `_{type}_{id}`.
 *
 * @api
 */
final class FieldSchema {
	public const CORE = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];

	/** @param array<string,CheckoutField> $fields keyed by id */
	private function __construct(
		public readonly string $type,
		public readonly string $country,
		private array $fields,
	) {}

	/** The filtered schema for a billing or shipping form. */
	public static function for_address( string $type, string $country ): self {
		$schema   = self::defaults( $type, $country );
		$filtered = apply_filters( 'flexacommerce.checkout.fields', $schema, $country, $type );
		return $filtered instanceof self && $filtered->type === $type ? $filtered : $schema;
	}

	/** @return list<CheckoutField> in display order */
	public function fields(): array {
		$fields = array_values( $this->fields );
		usort( $fields, static fn ( CheckoutField $a, CheckoutField $b ): int => $a->priority <=> $b->priority );
		return $fields;
	}

	public function field( string $id ): ?CheckoutField {
		return $this->fields[ $id ] ?? null;
	}

	public function with( CheckoutField $field ): self {
		$fields               = $this->fields;
		$fields[ $field->id ] = $field;
		return new self( $this->type, $this->country, $fields );
	}

	/** Core fields `country` and, for billing, `email` cannot be removed. */
	public function without( string $id ): self {
		if ( $id === 'country' || ( $id === 'email' && $this->type === 'billing' ) ) {
			return $this;
		}
		$fields = $this->fields;
		unset( $fields[ $id ] );
		return new self( $this->type, $this->country, $fields );
	}

	/** @return list<array<string,mixed>> */
	public function to_array(): array {
		return array_map( static fn ( CheckoutField $f ): array => $f->to_array(), $this->fields() );
	}

	private static function defaults( string $type, string $country ): self {
		$states   = Countries::states( $country );
		$postcode = Countries::uses_postcode( $country );
		$prefix   = $type === 'shipping' ? 'shipping ' : 'billing ';
		$list     = [
			new CheckoutField( 'email', __( 'Email address', 'flexacommerce' ), 'email', true, 'email', [], 254, 5 ),
			new CheckoutField( 'first_name', __( 'First name', 'flexacommerce' ), 'text', true, $prefix . 'given-name', [], 100, 10 ),
			new CheckoutField( 'last_name', __( 'Last name', 'flexacommerce' ), 'text', true, $prefix . 'family-name', [], 100, 20 ),
			new CheckoutField( 'company', __( 'Company', 'flexacommerce' ), 'text', Settings::string( 'checkout_company' ) === 'required', $prefix . 'organization', [], 100, 30 ),
			new CheckoutField( 'country', __( 'Country / Region', 'flexacommerce' ), 'country', true, $prefix . 'country', [], 2, 40 ),
			new CheckoutField( 'address_1', __( 'Street address', 'flexacommerce' ), 'text', true, $prefix . 'address-line1', [], 200, 50 ),
			new CheckoutField( 'address_2', __( 'Apartment, suite, unit (optional)', 'flexacommerce' ), 'text', false, $prefix . 'address-line2', [], 200, 60 ),
			new CheckoutField( 'city', __( 'Town / City', 'flexacommerce' ), 'text', true, $prefix . 'address-level2', [], 100, 70 ),
			new CheckoutField( 'state', $country === 'US' ? __( 'State', 'flexacommerce' ) : __( 'State / County', 'flexacommerce' ), 'state', $states !== [], $prefix . 'address-level1', $states, 100, 80 ),
			new CheckoutField( 'postcode', $country === 'US' ? __( 'ZIP Code', 'flexacommerce' ) : __( 'Postcode', 'flexacommerce' ), 'text', true, $prefix . 'postal-code', [], 16, 90 ),
			new CheckoutField( 'phone', __( 'Phone', 'flexacommerce' ), 'tel', Settings::string( 'checkout_phone' ) === 'required', $prefix . 'tel', [], 32, 100 ),
		];
		$fields   = [];
		foreach ( $list as $field ) {
			$fields[ $field->id ] = $field;
		}
		if ( $type === 'shipping' ) {
			unset( $fields['email'] );
		}
		if ( ! $postcode ) {
			unset( $fields['postcode'] );
		}
		if ( Settings::string( 'checkout_company' ) === 'hidden' ) {
			unset( $fields['company'] );
		}
		if ( Settings::string( 'checkout_phone' ) === 'hidden' ) {
			unset( $fields['phone'] );
		}
		if ( Settings::string( 'checkout_address_2' ) === 'hidden' ) {
			unset( $fields['address_2'] );
		}
		return new self( $type, $country, $fields );
	}
}
