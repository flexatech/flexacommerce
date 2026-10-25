<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping;

use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;
use Flexa\Commerce\Support\FieldValues;

defined( 'ABSPATH' ) || exit;

/**
 * Zone matching and rates for a package (doc 02 §6, doc 04 §4). Rates are
 * memoized per package hash for the request: the cart summary and checkout
 * validation ask for the same package. A method that throws loses its
 * rates and is logged; the others still show.
 */
final class ShippingService {
	/** @var array<string,list<ShippingRate>> */
	private array $memo = [];

	public function __construct(
		private readonly ZoneRepository $zones,
		private readonly MethodRegistry $methods,
		private readonly Logger $log,
	) {}

	public function zones(): ZoneRepository {
		return $this->zones;
	}

	public function methods(): MethodRegistry {
		return $this->methods;
	}

	/**
	 * A store with no enabled method anywhere does not ship: checkout then
	 * asks for no shipping address and adds no shipping line.
	 */
	public function is_configured(): bool {
		foreach ( $this->zones->all() as $zone ) {
			foreach ( $zone->methods as $instance ) {
				if ( $instance->enabled ) {
					return true;
				}
			}
		}
		return false;
	}

	/** The first zone in order that matches, else "everywhere else". */
	public function zone_for( Destination $to ): Zone {
		foreach ( $this->zones->all() as $zone ) {
			if ( $zone->matches( $to ) ) {
				return $zone;
			}
		}
		return new Zone( Zone::REST_OF_WORLD, '' );
	}

	/** @return list<ShippingRate> */
	public function rates( ShippingPackage $package ): array {
		$key = $package->hash();
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		$rates = [];
		if ( ! $package->destination->is_empty() ) {
			foreach ( $this->zone_for( $package->destination )->methods as $instance ) {
				if ( ! $instance->enabled ) {
					continue;
				}
				$method = $this->methods->has( $instance->type ) ? $this->methods->get( $instance->type ) : null;
				if ( $method === null ) {
					continue;
				}
				foreach ( $this->method_rates( $method, $instance, $package ) as $rate ) {
					$rates[] = $rate;
				}
			}
		}
		$filtered = apply_filters( 'flexacommerce.shipping.rates', $rates, $package );
		if ( is_array( $filtered ) ) {
			$rates = array_values( array_filter( $filtered, static fn ( mixed $r ): bool => $r instanceof ShippingRate && $r->cost->currency === $package->currency() && ! $r->cost->is_negative() ) );
		}
		$this->memo[ $key ] = $rates;

		return $rates;
	}

	/** A rate id the customer picked, if it is still offered for this package. */
	public function find_rate( ShippingPackage $package, string $rate_id ): ?ShippingRate {
		foreach ( $this->rates( $package ) as $rate ) {
			if ( $rate->id === $rate_id ) {
				return $rate;
			}
		}
		return null;
	}

	/** @return array<string,mixed> instance settings over the method's defaults */
	public function settings_of( ShippingMethod $method, MethodInstance $instance ): array {
		return FieldValues::with_defaults( $method->settings_fields(), $instance->settings );
	}

	/** @return list<ShippingRate> */
	private function method_rates( ShippingMethod $method, MethodInstance $instance, ShippingPackage $package ): array {
		try {
			$out = [];
			foreach ( $method->rates( $package, $this->settings_of( $method, $instance ), $instance->instance_id ) as $rate ) {
				if ( $rate->type() === $method->type() && $rate->instance_id() === $instance->instance_id ) {
					$out[] = $rate;
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			$this->log->error(
				'Shipping method {type} failed: {message}',
				[
					'type'      => $method->type(),
					'message'   => $e->getMessage(),
					'exception' => $e,
				]
			);
			return [];
		}
	}
}
