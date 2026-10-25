<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * What a calculator prices. `location` is null before the customer gave an
 * address and the store has no default location; the result is then zero.
 *
 * @api
 */
final class TaxRequest {
	public const CART     = 'cart';
	public const CHECKOUT = 'checkout';
	public const ADMIN    = 'admin';

	/** @param list<TaxLine> $lines keys are unique */
	public function __construct(
		public readonly string $currency,
		public readonly bool $prices_include_tax,
		public readonly ?TaxLocation $location,
		public readonly array $lines,
		public readonly int $user_id = 0,
		public readonly string $email = '',
		public readonly string $context = self::CHECKOUT,
		public readonly bool $exempt = false,
	) {
		$seen = [];
		foreach ( $lines as $line ) {
			if ( isset( $seen[ $line->key ] ) || $line->amount->currency !== $currency ) {
				throw new \InvalidArgumentException( sprintf( 'Tax line "%s" is repeated or in another currency.', $line->key ) );
			}
			$seen[ $line->key ] = true;
		}
	}

	public function line( string $key ): ?TaxLine {
		foreach ( $this->lines as $line ) {
			if ( $line->key === $key ) {
				return $line;
			}
		}
		return null;
	}

	/** Stable hash, for caching a remote calculator's answer. */
	public function hash(): string {
		$lines = array_map(
			static fn ( TaxLine $l ): array => [ $l->key, $l->type, $l->amount->amount, $l->quantity, $l->tax_class, $l->tax_status, $l->variant_id ],
			$this->lines
		);
		return md5( (string) wp_json_encode( [ $this->currency, $this->prices_include_tax, $this->location?->key(), $lines, $this->user_id, $this->exempt ] ) );
	}
}
