<?php

declare(strict_types=1);

namespace Flexa\Commerce\Pricing;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Pricing\PriceContext;
use Flexa\Commerce\Contracts\Pricing\PriceResolution;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Active prices (doc 03 §3.3, doc 04 §3). The stored regular and sale prices
 * and the sale schedule give the base amount; `flexacommerce.price.unit` may
 * change it. No tax yet: amounts are what the catalog stores.
 */
final class PricingService {
	/** @var array<string,?PriceResolution> */
	private array $memo = [];

	public function __construct(
		private readonly MoneyFactory $money,
		private readonly Clock $clock,
	) {}

	/**
	 * Unit price of a sellable row, or null when it has no price (and so
	 * cannot be bought). Memoised per request, keyed by every input.
	 */
	public function unit_price( Product $product, Variant $variant, int $quantity = 1, int $user_id = 0, ?\DateTimeImmutable $at = null ): ?PriceResolution {
		$at       = $at ?? $this->clock->now();
		$quantity = max( 1, $quantity );
		$key      = $variant->id . ':' . $quantity . ':' . $user_id . ':' . $at->getTimestamp();
		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$this->memo[ $key ] = $this->resolve( $product, $variant, $quantity, $user_id, $at );
		return $this->memo[ $key ];
	}

	public function line_total( PriceResolution $unit, int $quantity ): Money {
		return $unit->active->multiply( max( 0, $quantity ) );
	}

	/**
	 * Sum of line totals in the store currency.
	 *
	 * @param list<Money> $totals
	 */
	public function sum( array $totals ): Money {
		$sum = $this->money->from_minor( 0 );
		foreach ( $totals as $total ) {
			$sum = $sum->add( $total );
		}
		return $sum;
	}

	/**
	 * Split an amount (an order discount, a shipping fee) over lines in
	 * proportion to their totals. The parts always add up to the amount.
	 *
	 * @param list<Money> $line_totals
	 * @return list<Money>
	 */
	public function allocate( Money $amount, array $line_totals ): array {
		if ( $line_totals === [] ) {
			return [];
		}
		$ratios = array_map( static fn ( Money $m ): int => max( 0, $m->amount ), $line_totals );
		if ( array_sum( $ratios ) === 0 ) {
			$ratios = array_fill( 0, count( $line_totals ), 1 );
		}
		return $amount->allocate( $ratios );
	}

	public function format( Money $money ): string {
		return $this->money->format( $money );
	}

	/** Forget memoised prices, for example after a catalog write in the same request. */
	public function reset(): void {
		$this->memo = [];
	}

	private function resolve( Product $product, Variant $variant, int $quantity, int $user_id, \DateTimeImmutable $at ): ?PriceResolution {
		$now    = $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Clock::DB_FORMAT );
		$active = $variant->active_price( $now );
		if ( $active === null || $variant->regular_price === null ) {
			return null;
		}

		$filtered = has_filter( 'flexacommerce.price.unit' )
			? apply_filters( 'flexacommerce.price.unit', $active, $product->view_of( $variant ), new PriceContext( $user_id, $quantity, $at ) )
			: $active;
		$amount   = is_int( $filtered ) ? max( 0, $filtered ) : $active;

		return new PriceResolution(
			regular: $this->money->from_minor( $variant->regular_price ),
			active: $this->money->from_minor( $amount ),
			on_sale: $variant->is_on_sale( $now ),
		);
	}
}
