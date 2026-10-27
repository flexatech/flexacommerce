<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * The fields of one address form for one country (doc 04 §6), arranged by
 * the checkout builder (CheckoutLayout, ADR-031). Core fields map to order
 * address columns; any other field, from the builder or added by an
 * extension through `flexacommerce.checkout.fields`, is stored in order
 * meta as `_{type}_{id}`.
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

	/**
	 * The filtered schema for a billing or shipping form. With `$cart`, a
	 * field of the merchant's whose conditions the cart does not meet is left
	 * out; one for other countries stays, with its `countries`, since the
	 * customer can still change the country.
	 */
	public static function for_address( string $type, string $country, ?CartFacts $cart = null ): self {
		$schema   = self::defaults( $type, $country, $cart );
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

	/** Only the address columns: the account page saves no other field. */
	public function core(): self {
		return new self( $this->type, $this->country, array_intersect_key( $this->fields, array_flip( self::CORE ) ) );
	}

	/**
	 * The fields of the Additional information section: the order notes
	 * (`customer_note`) and the merchant's own fields. A separate filter, so
	 * extensions of the address forms never meet a third type.
	 */
	public static function additional( ?CartFacts $cart = null ): self {
		$fields = [];
		foreach ( CheckoutLayout::current()->section( 'additional' ) as $i => $entry ) {
			if ( $entry['answer'] === CheckoutLayout::HIDDEN || ! self::allowed( $entry, $cart ) ) {
				continue;
			}
			$required             = $entry['answer'] === CheckoutLayout::REQUIRED;
			$field                = $entry['custom']
				? self::custom( $entry, ( $i + 1 ) * 10 )
				: new CheckoutField(
					'customer_note',
					$entry['label'] !== '' ? $entry['label'] : ( $required ? __( 'Order notes', 'flexacommerce' ) : __( 'Order notes (optional)', 'flexacommerce' ) ),
					'textarea',
					$required,
					'',
					[],
					CheckoutValidator::NOTE_MAX,
					( $i + 1 ) * 10,
					$entry['placeholder'],
					$entry['help'],
				);
			$fields[ $field->id ] = $field;
		}
		$schema   = new self( 'additional', '', $fields );
		$filtered = apply_filters( 'flexacommerce.checkout.additional_fields', $schema );
		return $filtered instanceof self && $filtered->type === 'additional' ? $filtered : $schema;
	}

	/** @param array{when?:array{countries:list<string>,shipping:string,products:list<int>,categories:list<int>}} $entry */
	private static function allowed( array $entry, ?CartFacts $cart ): bool {
		return $cart === null || ! isset( $entry['when'] ) || $cart->allows( $entry['when'] );
	}

	private static function defaults( string $type, string $country, ?CartFacts $cart ): self {
		$states = Countries::states( $country );
		$prefix = $type === 'shipping' ? 'shipping ' : 'billing ';
		$list   = [
			new CheckoutField( 'email', __( 'Email address', 'flexacommerce' ), 'email', true, 'email', [], 254 ),
			new CheckoutField( 'first_name', __( 'First name', 'flexacommerce' ), 'text', true, $prefix . 'given-name', [], 100 ),
			new CheckoutField( 'last_name', __( 'Last name', 'flexacommerce' ), 'text', true, $prefix . 'family-name', [], 100 ),
			new CheckoutField( 'company', __( 'Company', 'flexacommerce' ), 'text', false, $prefix . 'organization', [], 100 ),
			new CheckoutField( 'country', __( 'Country / Region', 'flexacommerce' ), 'country', true, $prefix . 'country', [], 2 ),
			new CheckoutField( 'address_1', __( 'Street address', 'flexacommerce' ), 'text', true, $prefix . 'address-line1', [], 200 ),
			new CheckoutField( 'address_2', __( 'Apartment, suite, unit (optional)', 'flexacommerce' ), 'text', false, $prefix . 'address-line2', [], 200 ),
			new CheckoutField( 'city', __( 'Town / City', 'flexacommerce' ), 'text', true, $prefix . 'address-level2', [], 100 ),
			new CheckoutField( 'state', $country === 'US' ? __( 'State', 'flexacommerce' ) : __( 'State / County', 'flexacommerce' ), 'state', $states !== [], $prefix . 'address-level1', $states, 100 ),
			new CheckoutField( 'postcode', $country === 'US' ? __( 'ZIP Code', 'flexacommerce' ) : __( 'Postcode', 'flexacommerce' ), 'text', true, $prefix . 'postal-code', [], 16 ),
			new CheckoutField( 'phone', __( 'Phone', 'flexacommerce' ), 'tel', false, $prefix . 'tel', [], 32 ),
		];
		$core   = [];
		foreach ( $list as $field ) {
			$core[ $field->id ] = $field;
		}

		// Priorities keep today's steps of ten (email 5, first name 10, ..., phone 100),
		// so an extension field with priority 35 still lands after the company.
		$fields = [];
		$step   = $type === 'shipping' ? 1 : 0;
		foreach ( CheckoutLayout::current()->section( $type ) as $i => $entry ) {
			$priority = max( 5, ( $i + $step ) * 10 );
			if ( $entry['custom'] ) {
				if ( $entry['answer'] !== CheckoutLayout::HIDDEN && self::allowed( $entry, $cart ) ) {
					$fields[ $entry['id'] ] = self::custom( $entry, $priority );
				}
				continue;
			}
			$field = $core[ $entry['id'] ] ?? null;
			if ( $field === null || $entry['answer'] === CheckoutLayout::HIDDEN ) {
				continue;
			}
			if ( $field->id === 'postcode' && ! Countries::uses_postcode( $country ) ) {
				continue;
			}
			$changes = [
				'priority'    => $priority,
				'placeholder' => $entry['placeholder'],
				'help'        => $entry['help'],
				'width'       => $entry['width'],
			];
			if ( ! in_array( $field->id, CheckoutLayout::BY_COUNTRY, true ) ) {
				$changes['required'] = $entry['answer'] === CheckoutLayout::REQUIRED;
			}
			if ( $entry['label'] !== '' ) {
				$changes['label'] = $entry['label'];
			} elseif ( $field->id === 'address_2' && $entry['answer'] === CheckoutLayout::REQUIRED ) {
				$changes['label'] = __( 'Apartment, suite, unit', 'flexacommerce' );
			}
			$fields[ $field->id ] = $field->copy( $changes );
		}
		return new self( $type, $country, $fields );
	}

	/** @param array{id:string,label:string,placeholder:string,help:string,width:string,answer:string,type?:string,options?:list<array{value:string,label:string}>,when?:array{countries:list<string>,shipping:string,products:list<int>,categories:list<int>}} $entry */
	private static function custom( array $entry, int $priority ): CheckoutField {
		$type    = $entry['type'] ?? 'text';
		$options = [];
		foreach ( $entry['options'] ?? [] as $option ) {
			$options[ $option['value'] ] = $option['label'];
		}
		return new CheckoutField(
			$entry['id'],
			$entry['label'],
			$type,
			$entry['answer'] === CheckoutLayout::REQUIRED,
			'',
			$options,
			match ( $type ) {
				'textarea' => 1000,
				'email' => 254,
				'tel', 'number' => 32,
				'date' => 10,
				'checkbox' => 1,
				default => 200,
			},
			$priority,
			$entry['placeholder'],
			$entry['help'],
			$entry['width'],
			$entry['when']['countries'] ?? [],
		);
	}
}
