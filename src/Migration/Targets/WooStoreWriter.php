<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Migration\Sources\WooStoreReader;

defined( 'ABSPATH' ) || exit;

/** Settings, tax rates and customer addresses into WooCommerce, the reverse of WooStoreReader. */
final class WooStoreWriter {
	/** Canonical key => WooCommerce option, for the values that are plain strings. */
	private const STRING_OPTIONS = [
		'currency'             => 'woocommerce_currency',
		'dimension_unit'       => 'woocommerce_dimension_unit',
		'tax_based_on'         => 'woocommerce_tax_based_on',
		'shipping_tax_class'   => 'woocommerce_shipping_tax_class',
		'tax_display_shop'     => 'woocommerce_tax_display_shop',
		'tax_display_cart'     => 'woocommerce_tax_display_cart',
		'price_display_suffix' => 'woocommerce_price_display_suffix',
		'tax_total_display'    => 'woocommerce_tax_total_display',
	];

	/** Canonical key => WooCommerce yes/no option. */
	private const FLAG_OPTIONS = [
		'tax_enabled'           => 'woocommerce_calc_taxes',
		'prices_include_tax'    => 'woocommerce_prices_include_tax',
		'tax_round_at_subtotal' => 'woocommerce_tax_round_at_subtotal',
	];

	public function settings( Record $record, Mapping $map ): WriteResult {
		$data     = $record->data;
		$problems = [];
		foreach ( self::STRING_OPTIONS as $key => $option ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				update_option( $option, (string) $data[ $key ] );
			}
		}
		foreach ( self::FLAG_OPTIONS as $key => $option ) {
			if ( array_key_exists( $key, $data ) ) {
				update_option( $option, ! empty( $data[ $key ] ) ? 'yes' : 'no' );
			}
		}
		if ( isset( $data['weight_unit'] ) ) {
			update_option( 'woocommerce_weight_unit', $data['weight_unit'] === 'lb' ? 'lbs' : (string) $data['weight_unit'] );
		}
		if ( isset( $data['tax_default_location'] ) ) {
			$current = (string) get_option( 'woocommerce_default_customer_address', 'base' );
			// WooCommerce has more choices than "none" and "base"; a choice other than none is kept.
			if ( $data['tax_default_location'] === 'none' ) {
				update_option( 'woocommerce_default_customer_address', '' );
			} elseif ( $current === '' ) {
				update_option( 'woocommerce_default_customer_address', 'base' );
			}
		}
		if ( in_array( $data['reviews'] ?? null, [ 'off', 'all', 'buyers' ], true ) ) {
			update_option( 'woocommerce_enable_reviews', $data['reviews'] === 'off' ? 'no' : 'yes' );
			update_option( 'woocommerce_review_rating_verification_required', $data['reviews'] === 'buyers' ? 'yes' : 'no' );
		}
		if ( in_array( $data['download_method'] ?? null, [ 'force', 'xsendfile', 'redirect' ], true ) ) {
			update_option( 'woocommerce_file_download_method', $data['download_method'] );
		}
		$store = is_array( $data['store'] ?? null ) ? $data['store'] : [];
		if ( isset( $store['country'] ) ) {
			$country = strtoupper( (string) $store['country'] );
			$state   = (string) ( $store['state'] ?? '' );
			update_option( 'woocommerce_default_country', $state !== '' ? $country . ':' . $state : $country );
		}
		foreach ( [ 'city', 'postcode' ] as $key ) {
			if ( isset( $store[ $key ] ) ) {
				update_option( 'woocommerce_store_' . $key, (string) $store[ $key ] );
			}
		}
		$this->tax_classes( is_array( $data['tax_classes'] ?? null ) ? $data['tax_classes'] : [], $problems );

		$action = $map->row( Record::SETTINGS, $record->source_id() ) === null ? WriteResult::CREATED : WriteResult::UPDATED;
		$map->remember( Record::SETTINGS, $record->source_id(), 1, $record->checksum );
		return new WriteResult( $action, 1, $problems );
	}

	public function tax_rate( Record $record, Mapping $map ): WriteResult {
		$data   = $record->data;
		$target = $map->target_id( Record::TAX_RATE, $record->source_id() );
		if ( $target > 0 && ! is_array( \WC_Tax::_get_tax_rate( $target, ARRAY_A ) ) ) {
			$target = 0;
		}
		$class = (string) ( $data['tax_class'] ?? '' );
		$row   = [
			'tax_rate_country'  => strtoupper( (string) ( $data['country'] ?? '' ) ),
			'tax_rate_state'    => strtoupper( (string) ( $data['state'] ?? '' ) ),
			'tax_rate'          => (string) ( $data['rate'] ?? '0' ),
			'tax_rate_name'     => (string) ( $data['name'] ?? '' ),
			'tax_rate_priority' => max( 1, (int) ( $data['priority'] ?? 1 ) ),
			'tax_rate_compound' => ! empty( $data['compound'] ) ? 1 : 0,
			'tax_rate_shipping' => ( $data['shipping'] ?? true ) ? 1 : 0,
			'tax_rate_order'    => (int) ( $data['order'] ?? 0 ),
			'tax_rate_class'    => $class === 'standard' ? '' : $class,
		];
		if ( $target > 0 ) {
			\WC_Tax::_update_tax_rate( $target, $row );
			$action = WriteResult::UPDATED;
		} else {
			$target = (int) \WC_Tax::_insert_tax_rate( $row );
			$action = WriteResult::CREATED;
		}
		if ( $target <= 0 ) {
			throw new Rejected( 'tax_rate_failed', 'WooCommerce did not save the tax rate.' );
		}
		\WC_Tax::_update_tax_rate_postcodes( $target, implode( ';', array_map( 'strval', (array) ( $data['postcodes'] ?? [] ) ) ) );
		\WC_Tax::_update_tax_rate_cities( $target, implode( ';', array_map( 'strval', (array) ( $data['cities'] ?? [] ) ) ) );
		$map->remember( Record::TAX_RATE, $record->source_id(), $target, $record->checksum );
		return new WriteResult( $action, $target );
	}

	/**
	 * Addresses of a WordPress user. On one site users are shared and never
	 * created; from a file the user is matched by email or created.
	 */
	public function customer( Record $record, Mapping $map ): WriteResult {
		$user_id = $map->user_id( (int) $record->source_id(), true );
		if ( $user_id <= 0 ) {
			throw new Rejected( 'unknown_user', sprintf( 'User %s does not exist.', $record->source_id() ) );
		}
		$problems = [];
		try {
			$customer = new \WC_Customer( $user_id );
		} catch ( \Exception $e ) {
			throw new Rejected( 'customer_failed', $e->getMessage() );
		}
		foreach ( [ 'billing', 'shipping' ] as $type ) {
			self::set_address( $customer, $type, is_array( $record->data[ $type ] ?? null ) ? $record->data[ $type ] : [], $problems );
		}
		$customer->save();
		$action = $map->row( Record::CUSTOMER, $record->source_id() ) === null ? WriteResult::CREATED : WriteResult::UPDATED;
		$map->remember( Record::CUSTOMER, $record->source_id(), $user_id, $record->checksum );
		return new WriteResult( $action, $user_id, $problems );
	}

	/**
	 * Canonical address onto a customer or an order; a value WooCommerce
	 * refuses, such as a malformed email, is left out and reported.
	 *
	 * @param array<array-key,mixed> $address
	 * @param list<Problem>          $problems
	 */
	public static function set_address( \WC_Customer|\WC_Order $object, string $type, array $address, array &$problems ): void {
		foreach ( WooStoreReader::ADDRESS_KEYS as $key ) {
			$setter = 'set_' . $type . '_' . $key;
			if ( ! is_callable( [ $object, $setter ] ) || ! is_scalar( $address[ $key ] ?? null ) ) {
				continue;
			}
			try {
				$object->{$setter}( (string) $address[ $key ] );
			} catch ( \WC_Data_Exception $e ) {
				$problems[] = Problem::warning( 'address_field_dropped', sprintf( 'The %1$s %2$s was not accepted: %3$s', $type, $key, $e->getMessage() ) );
			}
		}
	}

	/**
	 * @param array<array-key,mixed> $classes
	 * @param list<Problem>          $problems
	 */
	private function tax_classes( array $classes, array &$problems ): void {
		$existing = [];
		foreach ( \WC_Tax::get_tax_rate_classes() as $class ) {
			$existing[ (string) $class->slug ] = true;
		}
		foreach ( $classes as $class ) {
			$slug = is_array( $class ) ? (string) ( $class['slug'] ?? '' ) : '';
			if ( $slug === '' || $slug === 'standard' || isset( $existing[ $slug ] ) ) {
				continue;
			}
			$result = \WC_Tax::create_tax_class( (string) ( $class['name'] ?? $slug ), $slug );
			if ( is_wp_error( $result ) ) {
				$problems[] = Problem::warning( 'tax_class_failed', sprintf( 'The tax class "%1$s" was not created: %2$s', $slug, $result->get_error_message() ) );
			}
		}
	}
}
