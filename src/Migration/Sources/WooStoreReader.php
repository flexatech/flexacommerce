<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;

defined( 'ABSPATH' ) || exit;

/** Store settings, tax rates and customer addresses from WooCommerce (doc 05 §5.3, §5.4 tax rows). */
final class WooStoreReader {
	/** Canonical address keys, in the order of the WooCommerce getters. */
	public const ADDRESS_KEYS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];

	public function __construct( private readonly WooEnumerationRepository $enumeration ) {}

	public function settings(): Record {
		[ $country, $state ] = array_pad( explode( ':', (string) get_option( 'woocommerce_default_country', 'US' ), 2 ), 2, '' );
		$weight              = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		$classes             = [];
		foreach ( class_exists( \WC_Tax::class ) ? \WC_Tax::get_tax_rate_classes() : [] as $class ) {
			$classes[] = [
				'slug' => (string) $class->slug,
				'name' => (string) $class->name,
			];
		}
		$location = (string) get_option( 'woocommerce_default_customer_address', 'base' );
		return new Record(
			Record::SETTINGS,
			Ref::make( 'wc', Record::SETTINGS, 1 ),
			[
				'currency'              => (string) get_option( 'woocommerce_currency', 'USD' ),
				'weight_unit'           => $weight === 'lbs' ? 'lb' : $weight,
				'dimension_unit'        => (string) get_option( 'woocommerce_dimension_unit', 'cm' ),
				'tax_enabled'           => get_option( 'woocommerce_calc_taxes' ) === 'yes',
				'prices_include_tax'    => get_option( 'woocommerce_prices_include_tax' ) === 'yes',
				'tax_based_on'          => (string) get_option( 'woocommerce_tax_based_on', 'shipping' ),
				'tax_default_location'  => $location === '' ? 'none' : 'base',
				'shipping_tax_class'    => (string) get_option( 'woocommerce_shipping_tax_class', 'inherit' ),
				'tax_round_at_subtotal' => get_option( 'woocommerce_tax_round_at_subtotal' ) === 'yes',
				'tax_display_shop'      => (string) get_option( 'woocommerce_tax_display_shop', 'excl' ),
				'tax_display_cart'      => (string) get_option( 'woocommerce_tax_display_cart', 'excl' ),
				'price_display_suffix'  => (string) get_option( 'woocommerce_price_display_suffix', '' ),
				'tax_total_display'     => (string) get_option( 'woocommerce_tax_total_display', 'itemized' ),
				'tax_classes'           => $classes,
				'store'                 => [
					'country'  => $country,
					'state'    => $state,
					'city'     => (string) get_option( 'woocommerce_store_city', '' ),
					'postcode' => (string) get_option( 'woocommerce_store_postcode', '' ),
				],
			]
		);
	}

	public function tax_rate( int $id ): ?Record {
		if ( ! class_exists( \WC_Tax::class ) ) {
			return null;
		}
		$row = \WC_Tax::_get_tax_rate( $id, ARRAY_A );
		if ( ! is_array( $row ) || $row === [] ) {
			return null;
		}
		$rate = Decimal::clean( $row['tax_rate'] ?? null );
		if ( $rate === null || str_starts_with( $rate, '-' ) ) {
			throw new Rejected( 'invalid_rate', sprintf( 'Tax rate %d has the rate "%s".', $id, (string) ( $row['tax_rate'] ?? '' ) ) );
		}
		$postcodes = [];
		$cities    = [];
		foreach ( $this->enumeration->tax_rate_locations( $id ) as $location ) {
			if ( $location['type'] === 'city' ) {
				$cities[] = $location['code'];
			} else {
				$postcodes[] = $location['code'];
			}
		}
		return new Record(
			Record::TAX_RATE,
			Ref::make( 'wc', Record::TAX_RATE, $id ),
			[
				'tax_class' => (string) ( $row['tax_rate_class'] ?? '' ),
				'country'   => (string) ( $row['tax_rate_country'] ?? '' ),
				'state'     => (string) ( $row['tax_rate_state'] ?? '' ),
				'rate'      => $rate,
				'name'      => (string) ( $row['tax_rate_name'] ?? '' ),
				'priority'  => (int) ( $row['tax_rate_priority'] ?? 1 ),
				'compound'  => (bool) (int) ( $row['tax_rate_compound'] ?? 0 ),
				'shipping'  => (bool) (int) ( $row['tax_rate_shipping'] ?? 1 ),
				'order'     => (int) ( $row['tax_rate_order'] ?? 0 ),
				'postcodes' => $postcodes,
				'cities'    => $cities,
			]
		);
	}

	public function customer( int $user_id ): ?Record {
		if ( get_userdata( $user_id ) === false ) {
			return null;
		}
		try {
			$customer = new \WC_Customer( $user_id );
		} catch ( \Exception ) {
			return null;
		}
		$modified = $customer->get_date_modified( 'edit' );
		return new Record(
			Record::CUSTOMER,
			Ref::make( 'wp', 'user', $user_id ),
			[
				'modified_at' => $modified !== null ? gmdate( 'Y-m-d\TH:i:s\Z', $modified->getTimestamp() ) : null,
				'billing'     => self::address( $customer, 'billing' ),
				'shipping'    => self::address( $customer, 'shipping' ),
			]
		);
	}

	/** @return array<string,string> */
	public static function address( \WC_Customer|\WC_Order $object, string $type ): array {
		$out = [];
		foreach ( self::ADDRESS_KEYS as $key ) {
			$getter = 'get_' . $type . '_' . $key;
			if ( is_callable( [ $object, $getter ] ) ) {
				$out[ $key ] = (string) $object->{$getter}( 'edit' );
			}
		}
		return $out;
	}
}
