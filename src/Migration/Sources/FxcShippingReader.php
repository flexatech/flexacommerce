<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingService;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/** FlexaCommerce shipping zones in the shape WooShippingReader emits. */
final class FxcShippingReader {
	public function __construct( private readonly ShippingService $shipping ) {}

	public function shipping(): Record {
		$currency = Settings::string( 'currency' );
		$zones    = [];
		$rest     = [ 'methods' => [] ];
		$this->shipping->zones()->flush();
		foreach ( $this->shipping->zones()->all() as $zone ) {
			$methods = array_map( fn ( MethodInstance $method ): array => $this->method( $method, $currency ), $zone->methods );
			if ( $zone->id === Zone::REST_OF_WORLD ) {
				$rest = [ 'methods' => $methods ];
				continue;
			}
			$zones[] = [
				'id'        => (string) $zone->id,
				'name'      => $zone->name,
				'regions'   => $zone->regions,
				'postcodes' => $zone->postcodes,
				'methods'   => $methods,
			];
		}
		return new Record(
			Record::SHIPPING,
			Ref::make( 'fxc', Record::SHIPPING, 1 ),
			[
				'currency' => $currency,
				'zones'    => $zones,
				'rest'     => $rest,
			]
		);
	}

	/** @return array<string,mixed> */
	private function method( MethodInstance $method, string $currency ): array {
		$handler  = $this->shipping->methods()->get( $method->type );
		$settings = $handler === null ? $method->settings : $this->shipping->settings_of( $handler, $method );
		$exponent = Currency::exponent( $currency );
		$out      = [
			'id'        => (string) $method->instance_id,
			'type'      => $method->type,
			'enabled'   => $method->enabled,
			'title'     => trim( (string) ( $settings['title'] ?? '' ) ),
			'taxable'   => ( $settings['tax_status'] ?? 'taxable' ) !== 'none',
			'supported' => true,
			'notes'     => [],
		];
		foreach ( [ 'cost', 'cost_per_item', 'min_amount' ] as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$out[ $key ] = Decimal::trim( Decimal::from_minor( max( 0, (int) $settings[ $key ] ), $currency, $exponent ) );
			}
		}
		if ( $method->type === 'free_shipping' ) {
			$out['requires'] = (string) ( $settings['requires'] ?? '' );
		}
		if ( $method->type === 'local_pickup' ) {
			$out['location'] = trim( (string) ( $settings['location'] ?? '' ) );
		} elseif ( $handler === null || ! in_array( $method->type, [ 'flat_rate', 'free_shipping' ], true ) ) {
			$out['settings'] = $settings;
		}
		return $out;
	}
}
