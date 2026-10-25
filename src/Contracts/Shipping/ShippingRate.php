<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Shipping;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * One option the customer can pick. `id` is `{type}:{instance_id}` with an
 * optional `:{suffix}` when a method returns several rates.
 *
 * @api
 */
final class ShippingRate {
	/** @param list<string> $meta display strings shown under the label */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly Money $cost,
		public readonly array $meta = [],
		public readonly bool $taxable = true,
		public readonly bool $needs_address = true,
	) {
		if ( preg_match( '/^[a-z0-9_]{1,64}:\d+(:[a-z0-9_-]{1,64})?$/', $id ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid shipping rate id "%s".', $id ) );
		}
	}

	public function type(): string {
		return explode( ':', $this->id )[0];
	}

	public function instance_id(): int {
		return (int) explode( ':', $this->id )[1];
	}

	/** @return array<string,mixed> */
	public function to_data(): array {
		return [
			'id'    => $this->id,
			'label' => $this->label,
			'cost'  => $this->cost->amount,
			'meta'  => $this->meta,
		];
	}
}
