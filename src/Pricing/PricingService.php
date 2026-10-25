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
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tax\TaxService;

defined( 'ABSPATH' ) || exit;

/**
 * Active prices (doc 03 §3.3, doc 04 §3). The stored regular and sale prices
 * and the sale schedule give the base amount; `flexacommerce.price.unit` may
 * change it. Amounts are what the catalog stores; `for_display()` turns
 * them into the catalog's with or without tax price (doc 02 §7.12.5).
 */
final class PricingService {
	/** @var array<string,?PriceResolution> */
	private array $memo = [];

	/** @var array<string,mixed>|null */
	private ?array $shop = null;

	/**
	 * @param ?\Closure():TaxService $tax Resolves the tax service on first
	 *        use; null shows the stored prices as they are.
	 */
	public function __construct(
		private readonly MoneyFactory $money,
		private readonly Clock $clock,
		private readonly ?\Closure $tax = null,
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

	/**
	 * The price a catalog page shows: with or without tax per
	 * `tax_display_shop` of the store base country's market, at the store
	 * base address. Uses the compiled base rates, so it costs no query.
	 */
	public function for_display( Product $product, Variant $variant, PriceResolution $unit ): PriceResolution {
		$tax = $this->taxable( $product, $variant );
		if ( $tax === null ) {
			return $unit;
		}
		$display = (string) $this->shop( $tax )['tax_display_shop'];
		$class   = $product->tax_class_of( $variant );
		return new PriceResolution(
			$tax->display_price( $unit->regular, $class, $display ),
			$tax->display_price( $unit->active, $class, $display ),
			$unit->on_sale
		);
	}

	/**
	 * The text after a catalog price, with `{price_including_tax}` and
	 * `{price_excluding_tax}` replaced by the stored active price with and
	 * without tax. Plain text; empty when taxes are off, none is set or the
	 * row is not taxable (as WooCommerce's get_price_suffix).
	 */
	public function display_suffix( Product $product, Variant $variant, PriceResolution $unit ): string {
		$tax = $this->taxable( $product, $variant );
		if ( $tax === null ) {
			return '';
		}
		$suffix = trim( (string) $this->shop( $tax )['price_display_suffix'] );
		if ( $suffix === '' || ! str_contains( $suffix, '{' ) ) {
			return $suffix;
		}
		$class = $product->tax_class_of( $variant );
		return strtr(
			$suffix,
			[
				'{price_including_tax}' => $this->format( $tax->display_price( $unit->active, $class, 'incl' ) ),
				'{price_excluding_tax}' => $this->format( $tax->display_price( $unit->active, $class, 'excl' ) ),
			]
		);
	}

	/** The tax service when this row's catalog price can change with tax. */
	private function taxable( Product $product, Variant $variant ): ?TaxService {
		// Read the setting first so a catalog page with taxes off loads no tax class (PT-off).
		if ( $this->tax === null || ! Settings::bool( 'tax_enabled' ) || $product->tax_status_of( $variant ) !== Variant::TAXABLE ) {
			return null;
		}
		return ( $this->tax )();
	}

	/** @return array<string,mixed> */
	private function shop( TaxService $tax ): array {
		return $this->shop ??= $tax->settings( Settings::string( 'store_country' ) );
	}

	/** Forget memoised prices, for example after a catalog write in the same request. */
	public function reset(): void {
		$this->memo = [];
		$this->shop = null;
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
