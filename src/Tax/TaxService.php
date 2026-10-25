<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Exceptions\TaxUnavailable;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Tax\TaxCalculator;
use Flexa\Commerce\Contracts\Tax\TaxLine;
use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRate;
use Flexa\Commerce\Contracts\Tax\TaxRateProvider;
use Flexa\Commerce\Contracts\Tax\TaxRequest;
use Flexa\Commerce\Contracts\Tax\TaxResult;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the tax module (doc 02 §7.12). Picks the tax location,
 * applies market overrides, resolves the rate provider and calculator
 * add-ons and guards them, and keeps the compiled base rates that catalog
 * pages use without a query.
 *
 * With `tax_enabled` off only enabled() runs: nothing else is loaded.
 */
final class TaxService {
	/** Seconds a provider add-on's answer is cached per location and class. */
	public const PROVIDER_TTL = HOUR_IN_SECONDS;

	/** Request line key of the shipping cost. */
	public const SHIPPING_KEY = 'shipping';

	/** Settings keys whose change means the compiled base rates are stale. */
	private const BASE_KEYS = [ 'store_country', 'store_state', 'store_postcode', 'store_city', 'tax_classes', 'tax_enabled' ];

	private ?TableRateProvider $table = null;

	private ?TaxRateProvider $provider = null;

	private ?TaxCalculator $calculator = null;

	private bool $resolved = false;

	/**
	 * @param \Closure():TaxRateRepository $repository
	 * @param \Closure():Logger            $logger
	 */
	public function __construct(
		private readonly \Closure $repository,
		private readonly \Closure $logger,
	) {}

	public function enabled(): bool {
		return Settings::bool( 'tax_enabled' );
	}

	public function repository(): TaxRateRepository {
		return ( $this->repository )();
	}

	public function table(): TableRateProvider {
		return $this->table ??= new TableRateProvider( $this->repository() );
	}

	/**
	 * Global tax settings with the overrides of the market that lists the
	 * country (doc 02 §7.12.6). Catalog pages pass the store base country.
	 *
	 * @return array<string,mixed>
	 */
	public function settings( string $country = '' ): array {
		$all     = Settings::all();
		$out     = [
			'market'                => '',
			'prices_include_tax'    => (bool) $all['prices_include_tax'],
			'tax_based_on'          => $all['tax_based_on'],
			'tax_display_shop'      => $all['tax_display_shop'],
			'tax_display_cart'      => $all['tax_display_cart'],
			'price_display_suffix'  => $all['price_display_suffix'],
			'tax_total_display'     => $all['tax_total_display'],
			'tax_round_at_subtotal' => (bool) $all['tax_round_at_subtotal'],
			'intrastate_sourcing'   => 'destination',
			'origin_states'         => [],
		];
		$country = strtoupper( $country );
		foreach ( is_array( $all['tax_markets'] ) ? $all['tax_markets'] : [] as $market ) {
			if ( $country !== '' && in_array( $country, $market['countries'], true ) ) {
				$out['market'] = $market['id'];
				return array_merge( $out, $market['overrides'] );
			}
		}
		return $out;
	}

	/** The store base address, or null when the store country is not set. */
	public function base_location(): ?TaxLocation {
		$country = Settings::string( 'store_country' );
		if ( $country === '' ) {
			return null;
		}
		return new TaxLocation( $country, Settings::string( 'store_state' ), Settings::string( 'store_postcode' ), Settings::string( 'store_city' ) );
	}

	/**
	 * Where tax is charged: the address `tax_based_on` names, the default
	 * location before an address exists, and the store base for in-state
	 * orders in an origin-sourcing state.
	 */
	public function location( ?TaxLocation $shipping, ?TaxLocation $billing ): ?TaxLocation {
		$shipping = $shipping !== null && $shipping->country !== '' ? $shipping : null;
		$billing  = $billing !== null && $billing->country !== '' ? $billing : null;
		$base     = $this->base_location();
		$settings = $this->settings( $shipping->country ?? $billing->country ?? $base->country ?? '' );

		$chosen = match ( $settings['tax_based_on'] ) {
			'base'    => $base,
			'billing' => $billing ?? $shipping,
			default   => $shipping ?? $billing,
		};
		if ( $chosen === null ) {
			return Settings::string( 'tax_default_location' ) === 'base' ? $base : null;
		}
		if (
			$base !== null
			&& $settings['intrastate_sourcing'] === 'origin'
			&& $chosen->country === $base->country
			&& $chosen->state !== ''
			&& $chosen->state === $base->state
			&& in_array( $chosen->state, (array) $settings['origin_states'], true )
		) {
			return $base;
		}
		return $chosen;
	}

	/** @return list<string> slugs of the extra classes, in settings order (Standard '' not included) */
	public function classes(): array {
		$classes = Settings::get( 'tax_classes' );
		return array_values( array_map( static fn ( array $c ): string => (string) $c['slug'], is_array( $classes ) ? $classes : [] ) );
	}

	/** A product's class, or Standard when the class was deleted. */
	public function known_class( string $slug ): string {
		return $slug !== '' && in_array( $slug, $this->classes(), true ) ? $slug : '';
	}

	/**
	 * Price a request: the calculator add-on if one is set, else the core
	 * engine with the rate provider add-on or the table. A failing add-on
	 * falls back to the table unless it forbids that.
	 *
	 * @throws TaxUnavailable the add-on failed and does not allow a fallback
	 */
	public function calculate( TaxRequest $request ): TaxOutcome {
		if ( ! $this->enabled() || $request->location === null ) {
			return new TaxOutcome( TaxResult::none( $request ) );
		}
		$exempt = apply_filters( 'flexacommerce.tax.exempt', $request->exempt, $request );
		if ( is_bool( $exempt ) && $exempt !== $request->exempt ) {
			$request = new TaxRequest( $request->currency, $request->prices_include_tax, $request->location, $request->lines, $request->user_id, $request->email, $request->context, $exempt );
		}
		$this->resolve();

		$failure = null;
		if ( $this->calculator !== null ) {
			try {
				$result = $this->calculator->calculate( $request );
				$error  = self::validate( $request, $result );
				if ( $error === null ) {
					return new TaxOutcome( $result );
				}
				$failure = $this->fail( $this->calculator::class, $error, $request );
			} catch ( \Throwable $e ) {
				$failure = $this->fail( $this->calculator::class, $e->getMessage(), $request, $e );
			}
		}

		$provider = $this->provider ?? $this->table();
		if ( $provider !== $this->table() && $failure === null ) {
			try {
				$result = $this->engine( $request, $provider )->calculate( $request );
				$error  = self::validate( $request, $result );
				if ( $error === null ) {
					return new TaxOutcome( $result );
				}
				$failure = $this->fail( $provider::class, $error, $request );
			} catch ( \Throwable $e ) {
				$failure = $this->fail( $provider::class, $e->getMessage(), $request, $e );
			}
			if ( ! $provider->fallback_allowed() ) {
				throw new TaxUnavailable( __( 'Tax could not be calculated, please try again.', 'flexacommerce' ) );
			}
		}

		$this->prefetch( $request );
		return new TaxOutcome( $this->engine( $request, $this->table() )->calculate( $request ), $failure );
	}

	/**
	 * A catalog price for display (doc 02 §7.12.5): the stored price with or
	 * without tax at the store base location, from the compiled base rates,
	 * so no query runs on catalog pages (PT-cat).
	 */
	public function display_price( Money $price, string $tax_class, string $display ): Money {
		$base = $this->base_location();
		if ( ! $this->enabled() || $base === null || $price->amount <= 0 ) {
			return $price;
		}
		$inclusive = Settings::bool( 'prices_include_tax' );
		if ( ( $display === 'incl' ) === $inclusive ) {
			return $price;
		}
		$compiled = $this->compiled();
		$engine   = new TaxEngine( static fn ( TaxLocation $l, string $class ): array => $compiled[ $class ] ?? [] );
		$line     = new TaxLine( 'p', TaxLine::PRODUCT, $price, 1, $this->known_class( $tax_class ) );
		$result   = $engine->calculate( new TaxRequest( $price->currency, $inclusive, $base, [ $line ], context: TaxRequest::CART ) );
		$tax      = $result->total();
		return $inclusive ? $price->subtract( $tax ) : $price->add( $tax );
	}

	/**
	 * Match the rate table for the store base address and every class, and
	 * store the result in the settings option (`tax_base_rates`).
	 */
	public function compile_base_rates(): void {
		$base     = $this->base_location();
		$compiled = [];
		if ( $base !== null && $this->enabled() ) {
			foreach ( $this->repository()->matching( $base, [ '', ...$this->classes() ] ) as $class => $rates ) {
				$compiled[ (string) $class ] = array_map( static fn ( TaxRate $rate ): array => $rate->to_array(), $rates );
			}
		}
		if ( Settings::get( 'tax_base_rates' ) !== $compiled ) {
			Settings::update( [ 'tax_base_rates' => $compiled ], true );
		}
		$this->table?->forget();
	}

	/**
	 * Hooked on `flexacommerce.settings.updated`.
	 *
	 * @param array<string,mixed> $new
	 * @param array<string,mixed> $old
	 */
	public function on_settings_updated( array $new, array $old ): void {
		// A removed class takes its rates with it; products that still name it fall back to Standard (known_class()).
		$slugs = static fn ( mixed $classes ): array => array_column( is_array( $classes ) ? $classes : [], 'slug' );
		foreach ( array_diff( $slugs( $old['tax_classes'] ?? [] ), $slugs( $new['tax_classes'] ?? [] ) ) as $removed ) {
			$this->repository()->delete_class( (string) $removed );
		}
		foreach ( self::BASE_KEYS as $key ) {
			if ( ( $new[ $key ] ?? null ) !== ( $old[ $key ] ?? null ) ) {
				$this->compile_base_rates();
				return;
			}
		}
	}

	/** @return array<string,list<TaxRate>> */
	private function compiled(): array {
		$out = [];
		$raw = Settings::get( 'tax_base_rates' );
		foreach ( is_array( $raw ) ? $raw : [] as $class => $rates ) {
			$out[ (string) $class ] = array_map( [ TaxRate::class, 'from_array' ], $rates );
		}
		return $out;
	}

	private function resolve(): void {
		if ( $this->resolved ) {
			return;
		}
		$this->resolved = true;
		$provider       = apply_filters( 'flexacommerce.tax.rate_provider', null );
		if ( $provider !== null && ! $provider instanceof TaxRateProvider ) {
			_doing_it_wrong( 'flexacommerce.tax.rate_provider', 'The filter must return a TaxRateProvider.', '1.0.0' );
			$provider = null;
		}
		$this->provider = $provider;
		$calculator     = apply_filters( 'flexacommerce.tax.calculator', null );
		if ( $calculator !== null && ! $calculator instanceof TaxCalculator ) {
			_doing_it_wrong( 'flexacommerce.tax.calculator', 'The filter must return a TaxCalculator.', '1.0.0' );
			$calculator = null;
		}
		$this->calculator = $calculator;
	}

	/** One query for every class the request can touch. */
	private function prefetch( TaxRequest $request ): void {
		if ( $request->location === null ) {
			return;
		}
		$classes = [ '' ];
		foreach ( $request->lines as $line ) {
			$classes[] = $line->tax_class;
		}
		$shipping = Settings::string( 'shipping_tax_class' );
		if ( $shipping !== 'inherit' ) {
			$classes[] = $shipping;
		}
		$this->table()->prefetch( $request->location, array_values( array_unique( $classes ) ) );
	}

	private function engine( TaxRequest $request, TaxRateProvider $provider ): TaxEngine {
		$location = $request->location;
		$settings = $this->settings( $location->country ?? '' );
		$base     = $this->base_location();
		$compiled = null;
		$table    = $provider === $this->table();
		$rates    = function ( TaxLocation $at, string $class ) use ( $provider, $base, $table, &$compiled ): array {
			$found = null;
			if ( $table && $base !== null && $at->equals( $base ) ) {
				$compiled ??= $this->compiled();
				$found      = $compiled[ $class ] ?? null;
			}
			$found  ??= $table ? $provider->rates( $at, $class ) : $this->cached_rates( $provider, $at, $class );
			$filtered = self::rate_list( apply_filters( 'flexacommerce.tax.rates', $found, $at, $class ) );
			if ( $filtered === null ) {
				_doing_it_wrong( 'flexacommerce.tax.rates', 'The filter must return a list of TaxRate.', '1.0.0' );
				return $found;
			}
			return $filtered;
		};

		return new TaxEngine(
			$rates,
			Settings::string( 'shipping_tax_class' ),
			$this->classes(),
			(bool) $settings['tax_round_at_subtotal'],
			$base,
			(bool) apply_filters( 'flexacommerce.tax.adjust_non_base_prices', true ),
		);
	}

	/** @return list<TaxRate> */
	private function cached_rates( TaxRateProvider $provider, TaxLocation $location, string $class ): array {
		$key    = 'p:' . md5( $provider::class . '|' . $location->key() . '|' . $class );
		$cached = wp_cache_get( $key, TaxRateRepository::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_map( [ TaxRate::class, 'from_array' ], $cached );
		}
		$rates = self::rate_list( $provider->rates( $location, $class ) );
		if ( $rates === null ) {
			throw new \UnexpectedValueException( 'The rate provider did not return a list of TaxRate.' );
		}
		wp_cache_set( $key, array_map( static fn ( TaxRate $r ): array => $r->to_array(), $rates ), TaxRateRepository::CACHE_GROUP, self::PROVIDER_TTL );
		return $rates;
	}

	/**
	 * Add-on output checked at run time: PHPDoc types are not enforced.
	 *
	 * @return list<TaxRate>|null null when it is not a list of TaxRate
	 */
	private static function rate_list( mixed $rates ): ?array {
		if ( ! is_array( $rates ) ) {
			return null;
		}
		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof TaxRate ) {
				return null;
			}
		}
		return array_values( $rates );
	}

	private function fail( string $component, string $error, TaxRequest $request, ?\Throwable $e = null ): string {
		( $this->logger )()->error(
			'Tax add-on {component} failed, the local rate table was used: {error}',
			[
				'component' => $component,
				'error'     => $error,
				'context'   => $request->context,
				'exception' => $e,
			]
		);
		return $component;
	}

	/**
	 * Why an add-on's result cannot be used, or null when it adds up.
	 */
	public static function validate( TaxRequest $request, TaxResult $result ): ?string {
		if ( $result->currency !== $request->currency ) {
			return 'the result is in another currency';
		}
		$keys = array_map( static fn ( TaxLine $l ): string => $l->key, $request->lines );
		if ( array_diff( $keys, array_keys( $result->lines ) ) !== [] || count( $keys ) !== count( $result->lines ) ) {
			return 'the result lines do not match the request';
		}
		$sums = [];
		foreach ( $request->lines as $line ) {
			$res = $result->lines[ $line->key ];
			if ( $res->net->currency !== $request->currency || $res->net->is_negative() ) {
				return 'a line net amount is negative or in another currency';
			}
			foreach ( $res->taxes as $tax ) {
				if ( $tax->amount->currency !== $request->currency || $tax->amount->is_negative() ) {
					return 'a tax amount is negative or in another currency';
				}
				$slot                                = $line->type === TaxLine::SHIPPING ? 1 : 0;
				$sums[ $tax->rate->key() ]         ??= [ 0, 0 ];
				$sums[ $tax->rate->key() ][ $slot ] += $tax->amount->amount;
			}
		}
		$totals = [];
		foreach ( $result->rates as $rate ) {
			if ( $rate->items->is_negative() || $rate->shipping->is_negative() ) {
				return 'a rate total is negative';
			}
			$totals[ $rate->rate->key() ] = [ $rate->items->amount, $rate->shipping->amount ];
		}
		foreach ( $sums + $totals as $key => $unused ) {
			unset( $unused );
			if ( ( $sums[ $key ] ?? [ 0, 0 ] ) !== ( $totals[ $key ] ?? [ 0, 0 ] ) ) {
				return sprintf( 'the line taxes of rate %s do not add up to its total', $key );
			}
		}
		return null;
	}
}
