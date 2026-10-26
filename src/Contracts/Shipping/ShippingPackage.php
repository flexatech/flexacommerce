<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Shipping;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * What a shipping method prices: where it goes and what is in it (doc 04 §4).
 *
 * @api
 */
final class ShippingPackage {
	/**
	 * @param list<PackageLine> $lines
	 * @param bool              $free_shipping whether the cart has a coupon that gives free shipping
	 */
	public function __construct(
		public readonly Destination $destination,
		public readonly array $lines,
		public readonly Money $contents_total,
		public readonly bool $free_shipping = false,
	) {}

	public function currency(): string {
		return $this->contents_total->currency;
	}

	public function item_count(): int {
		return array_sum( array_map( static fn ( PackageLine $l ): int => $l->qty, $this->lines ) );
	}

	/** Total weight in grams; lines without a weight count as 0. */
	public function weight_g(): int {
		return array_sum( array_map( static fn ( PackageLine $l ): int => ( $l->weight_g ?? 0 ) * $l->qty, $this->lines ) );
	}

	/** Stable hash for caching remote quotes. */
	public function hash(): string {
		$lines = array_map( static fn ( PackageLine $l ): array => [ $l->variant_id, $l->qty, $l->line_total->amount ], $this->lines );
		return md5( (string) wp_json_encode( [ $this->destination->to_array(), $lines, $this->contents_total->amount, $this->currency(), $this->free_shipping ] ) );
	}
}
