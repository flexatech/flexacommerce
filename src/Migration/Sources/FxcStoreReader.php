<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Tax\TaxRate;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tax\TaxRateRepository;

defined( 'ABSPATH' ) || exit;

/** Store settings, tax rates and customer addresses from FlexaCommerce, in the shapes WooStoreReader emits. */
final class FxcStoreReader {
	/** Settings copied as they are; the same keys WooStoreReader reads. */
	private const SETTINGS = [ 'currency', 'weight_unit', 'dimension_unit', 'tax_enabled', 'prices_include_tax', 'tax_based_on', 'tax_default_location', 'shipping_tax_class', 'tax_round_at_subtotal', 'tax_display_shop', 'tax_display_cart', 'price_display_suffix', 'tax_total_display' ];

	public function __construct(
		private readonly TaxRateRepository $rates,
		private readonly CustomerProfile $profiles,
	) {}

	public function settings(): Record {
		$data = [];
		foreach ( self::SETTINGS as $key ) {
			$data[ $key ] = Settings::get( $key );
		}
		$classes = [];
		foreach ( (array) Settings::get( 'tax_classes' ) as $class ) {
			if ( is_array( $class ) ) {
				$classes[] = [
					'slug' => (string) ( $class['slug'] ?? '' ),
					'name' => (string) ( $class['name'] ?? '' ),
				];
			}
		}
		$data['tax_classes'] = $classes;
		$data['store']       = [
			'country'  => Settings::string( 'store_country' ),
			'state'    => Settings::string( 'store_state' ),
			'city'     => Settings::string( 'store_city' ),
			'postcode' => Settings::string( 'store_postcode' ),
		];
		return new Record( Record::SETTINGS, Ref::make( 'fxc', Record::SETTINGS, 1 ), $data );
	}

	public function tax_rate( int $id ): ?Record {
		$row = $this->rates->find( $id );
		if ( $row === null ) {
			return null;
		}
		return new Record(
			Record::TAX_RATE,
			Ref::make( 'fxc', Record::TAX_RATE, $id ),
			[
				'tax_class' => $row->tax_class,
				'country'   => $row->country,
				'state'     => $row->state,
				'rate'      => Decimal::clean( TaxRate::to_percent( $row->rate ) ) ?? '0',
				'name'      => $row->name,
				'priority'  => $row->priority,
				'compound'  => $row->compound,
				'shipping'  => $row->shipping,
				'order'     => max( 0, $row->rate_order - 1 ),
				'postcodes' => array_map( 'strval', $row->postcodes ),
				'cities'    => array_map( 'strval', $row->cities ),
			]
		);
	}

	public function customer( int $user_id ): ?Record {
		if ( get_userdata( $user_id ) === false ) {
			return null;
		}
		$addresses = $this->profiles->addresses( $user_id );
		if ( $addresses === [] ) {
			return null;
		}
		return new Record(
			Record::CUSTOMER,
			Ref::make( 'wp', 'user', $user_id ),
			[
				'modified_at' => null,
				'billing'     => self::address( $addresses['billing'] ?? null, 'billing' ),
				'shipping'    => self::address( $addresses['shipping'] ?? null, 'shipping' ),
			]
		);
	}

	/**
	 * Canonical address keys; WooCommerce has no shipping email, so the key is left out there too.
	 *
	 * @return array<string,string>
	 */
	public static function address( ?AddressView $address, string $type ): array {
		$out = ( $address ?? new AddressView( $type ) )->to_array();
		if ( $type === 'shipping' ) {
			unset( $out['email'] );
		}
		return $out;
	}
}
