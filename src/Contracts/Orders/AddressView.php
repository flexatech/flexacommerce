<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * A billing or shipping address as stored on the order (doc 03 §4.4).
 *
 * @api
 */
final class AddressView {
	public function __construct(
		public readonly string $type,
		public readonly string $first_name = '',
		public readonly string $last_name = '',
		public readonly string $company = '',
		public readonly string $address_1 = '',
		public readonly string $address_2 = '',
		public readonly string $city = '',
		public readonly string $state = '',
		public readonly string $postcode = '',
		public readonly string $country = '',
		public readonly string $email = '',
		public readonly string $phone = '',
	) {}

	public function name(): string {
		return trim( $this->first_name . ' ' . $this->last_name );
	}

	/** @return array<string,string> */
	public function to_array(): array {
		return [
			'first_name' => $this->first_name,
			'last_name'  => $this->last_name,
			'company'    => $this->company,
			'address_1'  => $this->address_1,
			'address_2'  => $this->address_2,
			'city'       => $this->city,
			'state'      => $this->state,
			'postcode'   => $this->postcode,
			'country'    => $this->country,
			'email'      => $this->email,
			'phone'      => $this->phone,
		];
	}
}
