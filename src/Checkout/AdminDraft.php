<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\Cart;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Options\ProductOptions;

defined( 'ABSPATH' ) || exit;

/**
 * An order the admin is making or editing (ADR-061), as the editor posts
 * it: product lines with their choices, the customer, the addresses, the
 * coupons, the rate and the payment method. Values are cleaned here; what
 * they mean is checked when the draft is priced.
 *
 * @phpstan-type DraftLine array{product_id:int,variant_id:int,quantity:int,attributes:array<string,string>,options:array<string,string|list<string>>}
 */
final class AdminDraft {
	public const ADDRESS_FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];

	/**
	 * @param list<DraftLine> $lines
	 * @param list<string>    $coupons
	 */
	public function __construct(
		public readonly array $lines,
		public readonly int $customer_id,
		public readonly AddressView $billing,
		public readonly ?AddressView $shipping,
		public readonly array $coupons = [],
		public readonly string $shipping_rate = '',
		public readonly string $payment_method = '',
		public readonly string $customer_note = '',
	) {}

	/** @param array<array-key,mixed> $data */
	public static function from_array( array $data ): self {
		$lines = [];
		foreach ( array_slice( is_array( $data['lines'] ?? null ) ? $data['lines'] : [], 0, Cart::MAX_LINES ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$attributes = [];
			foreach ( is_array( $entry['attributes'] ?? null ) ? $entry['attributes'] : [] as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) && (string) $value !== '' ) {
					$attributes[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
				}
			}
			$lines[] = [
				'product_id' => absint( $entry['product_id'] ?? 0 ),
				'variant_id' => absint( $entry['variant_id'] ?? 0 ),
				'quantity'   => absint( $entry['quantity'] ?? 1 ),
				'attributes' => $attributes,
				'options'    => ProductOptions::posted( $entry['options'] ?? [] ),
			];
		}
		$codes = [];
		foreach ( is_array( $data['coupons'] ?? null ) ? $data['coupons'] : [] as $code ) {
			$code = is_string( $code ) ? Coupon::normalize( sanitize_text_field( $code ) ) : '';
			if ( $code !== '' && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}
		$shipping = is_array( $data['shipping'] ?? null ) ? self::address( 'shipping', $data['shipping'] ) : null;
		return new self(
			lines: $lines,
			customer_id: absint( $data['customer_id'] ?? 0 ),
			billing: self::address( 'billing', is_array( $data['billing'] ?? null ) ? $data['billing'] : [] ),
			shipping: $shipping !== null && $shipping->address_1 . $shipping->city . $shipping->country !== '' ? $shipping : null,
			coupons: array_slice( $codes, 0, CouponService::MAX_CODES ),
			shipping_rate: sanitize_text_field( (string) ( is_scalar( $data['shipping_rate'] ?? null ) ? $data['shipping_rate'] : '' ) ),
			payment_method: sanitize_key( (string) ( is_scalar( $data['payment_method'] ?? null ) ? $data['payment_method'] : '' ) ),
			customer_note: sanitize_textarea_field( (string) ( is_scalar( $data['customer_note'] ?? null ) ? $data['customer_note'] : '' ) ),
		);
	}

	/**
	 * An address as typed, cleaned but not required to be complete: the
	 * admin may take an order over the phone with only a name and an email.
	 *
	 * @param array<array-key,mixed> $input
	 */
	public static function address( string $type, array $input ): AddressView {
		$values = [];
		foreach ( self::ADDRESS_FIELDS as $field ) {
			$raw              = $input[ $field ] ?? '';
			$values[ $field ] = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
		}
		$values['country'] = strtoupper( substr( $values['country'], 0, 2 ) );
		$values['email']   = sanitize_email( $values['email'] );
		return new AddressView( $type, ...$values );
	}
}
