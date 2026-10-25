<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Shipping;

defined( 'ABSPATH' ) || exit;

/** @api */
final class Destination {
	public function __construct(
		public readonly string $country,
		public readonly string $state = '',
		public readonly string $postcode = '',
		public readonly string $city = '',
	) {}

	public function is_empty(): bool {
		return $this->country === '';
	}

	/** @return array{country:string,state:string,postcode:string,city:string} */
	public function to_array(): array {
		return [
			'country'  => $this->country,
			'state'    => $this->state,
			'postcode' => $this->postcode,
			'city'     => $this->city,
		];
	}
}
