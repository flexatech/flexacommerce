<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * A checkout submission as typed input (doc 06 §2.4: services never read
 * $_POST). Values are raw strings here; CheckoutValidator cleans them.
 * `expected_total` is only compared, never charged.
 */
final class CheckoutRequest {
	/**
	 * @param array<string,string>      $billing
	 * @param array<string,string>|null $shipping null ships to the billing address
	 * @param array<string,mixed>       $gateway_input
	 * @param array<string,mixed>       $extensions input of extensions, keyed by extension id
	 * @param array<string,string>      $additional answers to the Additional information fields, by field id
	 */
	public function __construct(
		public readonly string $checkout_token,
		public readonly array $billing,
		public readonly ?array $shipping,
		public readonly string $shipping_rate,
		public readonly string $payment_method,
		public readonly array $gateway_input = [],
		public readonly string $customer_note = '',
		public readonly bool $terms = false,
		public readonly ?int $expected_total = null,
		public readonly array $extensions = [],
		public readonly int $user_id = 0,
		public readonly string $customer_ip = '',
		public readonly array $additional = [],
	) {}

	/** @param array<string,mixed> $data the decoded JSON body or form post */
	public static function from_array( array $data, int $user_id, string $customer_ip = '' ): self {
		$ship_elsewhere = in_array( $data['ship_to_different_address'] ?? false, [ true, 1, '1', 'true', 'on', 'yes' ], true );
		return new self(
			checkout_token: self::text( $data['checkout_token'] ?? '' ),
			billing: self::address( $data['billing'] ?? [] ),
			shipping: $ship_elsewhere ? self::address( $data['shipping'] ?? [] ) : null,
			shipping_rate: self::text( $data['shipping_rate'] ?? '' ),
			payment_method: self::text( $data['payment_method'] ?? '' ),
			gateway_input: is_array( $data['gateway_input'] ?? null ) ? self::string_keys( $data['gateway_input'] ) : [],
			customer_note: is_string( $data['customer_note'] ?? null ) ? $data['customer_note'] : '',
			terms: in_array( $data['terms'] ?? false, [ true, 1, '1', 'true', 'on', 'yes' ], true ),
			expected_total: isset( $data['expected_total'] ) && is_numeric( $data['expected_total'] ) ? (int) $data['expected_total'] : null,
			extensions: is_array( $data['extensions'] ?? null ) ? self::string_keys( $data['extensions'] ) : [],
			user_id: $user_id,
			customer_ip: $customer_ip,
			additional: self::address( $data['additional'] ?? [] ),
		);
	}

	/** @return array<string,string> */
	private static function address( mixed $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $key => $field ) {
			if ( is_string( $key ) && is_scalar( $field ) ) {
				$out[ $key ] = (string) $field;
			}
		}
		return $out;
	}

	/**
	 * @param array<array-key,mixed> $value
	 * @return array<string,mixed>
	 */
	private static function string_keys( array $value ): array {
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $item;
			}
		}
		return $out;
	}

	private static function text( mixed $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}
}
