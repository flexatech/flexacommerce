<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Payments\CheckoutContext;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\ValidationResult;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a submission against the field schema of each address's country
 * and the choices computed on the server (doc 02 §7.5 step 3). Errors are
 * keyed by field path: `billing.postcode`, `shipping_rate`, `payment_method`.
 */
final class CheckoutValidator {
	public const NOTE_MAX = 1000;

	/** Where the order ships, from raw input; used to quote rates before the addresses are checked. */
	public function destination( CheckoutRequest $request ): Destination {
		$address = $request->shipping ?? $request->billing;
		return new Destination(
			strtoupper( trim( $address['country'] ?? '' ) ),
			strtoupper( trim( $address['state'] ?? '' ) ),
			Countries::normalize_postcode( $address['postcode'] ?? '' ),
			trim( $address['city'] ?? '' ),
		);
	}

	/**
	 * @return array{billing:AddressView,shipping:?AddressView,meta:array<string,string>,errors:array<string,string>}
	 */
	public function addresses( CheckoutRequest $request, bool $needs_shipping_address ): array {
		$errors = [];
		$meta   = [];

		[ $billing, $billing_meta ] = $this->address( 'billing', $request->billing, $errors );
		$meta                       = $billing_meta;

		$shipping = null;
		if ( $needs_shipping_address ) {
			if ( $request->shipping === null ) {
				$shipping = new AddressView( 'shipping', ...array_diff_key( $billing->to_array(), [ 'email' => true ] ) );
			} else {
				[ $shipping, $shipping_meta ] = $this->address( 'shipping', $request->shipping, $errors );
				$meta                         = array_merge( $meta, $shipping_meta );
			}
		}

		return [
			'billing'  => $billing,
			'shipping' => $shipping,
			'meta'     => $meta,
			'errors'   => $errors,
		];
	}

	/**
	 * Terms, shipping rate, gateway and its own validation, then the
	 * `flexacommerce.checkout.validate` filter.
	 *
	 * @param array<string,PaymentGateway> $gateways the gateways available for this checkout
	 * @return array<string,string>
	 */
	public function choices( CheckoutRequest $request, Quote $quote, CheckoutContext $context, array $gateways ): array {
		$errors = [];
		if ( Settings::int( 'terms_page_id' ) > 0 && ! $request->terms ) {
			$errors['terms'] = __( 'Please read and accept the terms and conditions to place your order.', 'flexacommerce' );
		}
		if ( mb_strlen( $request->customer_note ) > self::NOTE_MAX ) {
			/* translators: %d: maximum number of characters. */
			$errors['customer_note'] = sprintf( __( 'Order notes can be at most %d characters.', 'flexacommerce' ), self::NOTE_MAX );
		}
		if ( $quote->needs_shipping ) {
			if ( $quote->rates === [] ) {
				$errors['shipping_rate'] = __( 'There are no shipping options for this address. Check the address or contact us.', 'flexacommerce' );
			} elseif ( $quote->rate === null || $quote->rate->id !== $request->shipping_rate ) {
				$errors['shipping_rate'] = __( 'Choose a shipping option.', 'flexacommerce' );
			}
		}

		$result = new ValidationResult();
		if ( $quote->total->amount > 0 ) {
			$gateway = $gateways[ $request->payment_method ] ?? null;
			if ( $gateway === null ) {
				$errors['payment_method'] = __( 'Choose a payment method.', 'flexacommerce' );
			} else {
				$result = $gateway->validate( $context, $request->gateway_input );
			}
		}

		$filtered = apply_filters( 'flexacommerce.checkout.validate', $result, $context, $request->extensions );
		if ( $filtered instanceof ValidationResult ) {
			$result = $filtered;
		}
		foreach ( $result->errors as $error ) {
			$errors[ 'gateway.' . $error['code'] ] = $error['message'];
		}
		return $errors;
	}

	public function note( CheckoutRequest $request ): string {
		return mb_substr( sanitize_textarea_field( $request->customer_note ), 0, self::NOTE_MAX );
	}

	/**
	 * @param array<string,string> $input
	 * @param array<string,string> $errors
	 * @return array{0:AddressView,1:array<string,string>} address and extra field meta
	 */
	private function address( string $type, array $input, array &$errors ): array {
		$country = strtoupper( trim( $input['country'] ?? '' ) );
		$allowed = Countries::allowed();
		if ( ! isset( $allowed[ $country ] ) ) {
			$errors[ $type . '.country' ] = $country === ''
				? __( 'Choose a country.', 'flexacommerce' )
				: __( 'We do not sell to this country.', 'flexacommerce' );
		}

		$schema = FieldSchema::for_address( $type, $country );
		$values = [];
		$meta   = [];
		foreach ( $schema->fields() as $field ) {
			$value = $this->clean( $field, $input[ $field->id ] ?? '' );
			$error = $this->check( $field, $value, $country );
			if ( $error !== null && ! isset( $errors[ $type . '.' . $field->id ] ) ) {
				$errors[ $type . '.' . $field->id ] = $error;
			}
			if ( in_array( $field->id, FieldSchema::CORE, true ) ) {
				$values[ $field->id ] = $value;
			} elseif ( $value !== '' ) {
				$meta[ '_' . $type . '_' . $field->id ] = $value;
			}
		}
		$values['country'] = $country;

		return [
			new AddressView(
				type: $type,
				first_name: $values['first_name'] ?? '',
				last_name: $values['last_name'] ?? '',
				company: $values['company'] ?? '',
				address_1: $values['address_1'] ?? '',
				address_2: $values['address_2'] ?? '',
				city: $values['city'] ?? '',
				state: $values['state'] ?? '',
				postcode: $values['postcode'] ?? '',
				country: $country,
				email: $values['email'] ?? '',
				phone: $values['phone'] ?? '',
			),
			$meta,
		];
	}

	private function clean( CheckoutField $field, string $value ): string {
		if ( $field->type === 'checkbox' ) {
			return in_array( $value, [ '1', 'true', 'on', 'yes' ], true ) ? '1' : '';
		}
		$value = trim( sanitize_text_field( $value ) );
		if ( $field->type === 'email' ) {
			return strtolower( $value );
		}
		if ( $field->id === 'postcode' ) {
			return Countries::normalize_postcode( $value );
		}
		if ( $field->type === 'state' && $field->options !== [] ) {
			return strtoupper( $value );
		}
		return $value;
	}

	private function check( CheckoutField $field, string $value, string $country ): ?string {
		if ( $value === '' ) {
			/* translators: %s: field label. */
			return $field->required ? sprintf( __( '%s is required.', 'flexacommerce' ), $field->label ) : null;
		}
		if ( mb_strlen( $value ) > $field->max_length ) {
			/* translators: 1: field label, 2: maximum number of characters. */
			return sprintf( __( '%1$s can be at most %2$d characters.', 'flexacommerce' ), $field->label, $field->max_length );
		}
		if ( $field->type === 'email' && ! is_email( $value ) ) {
			return __( 'Enter a valid email address.', 'flexacommerce' );
		}
		if ( $field->type === 'tel' && preg_match( '/^[0-9+()\-.\s]{4,32}$/', $value ) !== 1 ) {
			return __( 'Enter a valid phone number.', 'flexacommerce' );
		}
		if ( ( $field->type === 'select' || $field->type === 'state' ) && $field->options !== [] && ! isset( $field->options[ $value ] ) ) {
			/* translators: %s: field label. */
			return sprintf( __( 'Choose a valid %s.', 'flexacommerce' ), $field->label );
		}
		if ( $field->id === 'postcode' && ! Countries::valid_postcode( $country, $value ) ) {
			/* translators: %s: field label, such as "ZIP Code". */
			return sprintf( __( '%s is not valid for this country.', 'flexacommerce' ), $field->label );
		}
		return null;
	}
}
