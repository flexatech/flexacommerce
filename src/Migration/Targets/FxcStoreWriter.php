<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tax\RateRow;
use Flexa\Commerce\Tax\TaxRateRepository;

defined( 'ABSPATH' ) || exit;

/** Settings, tax rates and customer addresses into FlexaCommerce (doc 05 §5.3). */
final class FxcStoreWriter {
	/** Canonical settings copied as they are. */
	private const SETTINGS = [ 'currency', 'weight_unit', 'dimension_unit', 'tax_enabled', 'prices_include_tax', 'tax_based_on', 'tax_default_location', 'shipping_tax_class', 'tax_round_at_subtotal', 'tax_display_shop', 'tax_display_cart', 'price_display_suffix', 'tax_total_display', 'reviews', 'tax_classes' ];

	public function __construct(
		private readonly TaxRateRepository $rates,
		private readonly CustomerProfile $profiles,
	) {}

	public function settings( Record $record, Mapping $map ): WriteResult {
		$data    = $record->data;
		$partial = array_intersect_key( $data, array_flip( self::SETTINGS ) );
		$store   = is_array( $data['store'] ?? null ) ? $data['store'] : [];
		foreach ( [ 'country', 'state', 'city', 'postcode' ] as $key ) {
			if ( isset( $store[ $key ] ) ) {
				$partial[ 'store_' . $key ] = (string) $store[ $key ];
			}
		}
		$problems = [];
		if ( isset( $partial['currency'] ) && Settings::string( 'currency' ) !== $partial['currency'] ) {
			$problems[] = Problem::info( 'currency_changed', sprintf( 'Store currency changed from %1$s to %2$s.', Settings::string( 'currency' ), (string) $partial['currency'] ) );
		}
		$saved = Settings::update( $partial );
		foreach ( $partial as $key => $value ) {
			if ( ! array_key_exists( $key, $saved ) || ( $key !== 'tax_classes' && $saved[ $key ] !== $value ) ) {
				$problems[] = Problem::warning(
					'setting_not_applied',
					sprintf( 'The setting %s could not be applied.', $key ),
					[
						'key'   => $key,
						'value' => $value,
					]
				);
			}
		}
		$action = $map->row( Record::SETTINGS, $record->source_id() ) === null ? WriteResult::CREATED : WriteResult::UPDATED;
		$map->remember( Record::SETTINGS, $record->source_id(), 1, $record->checksum );
		return new WriteResult( $action, 1, $problems );
	}

	public function tax_rate( Record $record, Mapping $map ): WriteResult {
		$data   = $record->data;
		$target = $map->target_id( Record::TAX_RATE, $record->source_id() );
		if ( $target > 0 && $this->rates->find( $target ) === null ) {
			$target = 0;
		}
		try {
			$row = RateRow::from_input(
				[
					'tax_class'  => (string) ( $data['tax_class'] ?? '' ),
					'country'    => (string) ( $data['country'] ?? '' ),
					'state'      => (string) ( $data['state'] ?? '' ),
					'rate'       => (string) ( $data['rate'] ?? '' ),
					'name'       => (string) ( $data['name'] ?? '' ),
					'priority'   => (int) ( $data['priority'] ?? 1 ),
					'compound'   => (bool) ( $data['compound'] ?? false ),
					'shipping'   => (bool) ( $data['shipping'] ?? true ),
					// Canonical order starts at 0 like WooCommerce; 0 means "append" to FXC.
					'rate_order' => 1 + max( 0, (int) ( $data['order'] ?? 0 ) ),
					'postcodes'  => (array) ( $data['postcodes'] ?? [] ),
					'cities'     => (array) ( $data['cities'] ?? [] ),
					'source'     => 'migration',
				],
				$target
			);
		} catch ( \InvalidArgumentException $e ) {
			throw new Rejected( 'invalid_tax_rate', $e->getMessage() );
		}
		$saved = $this->rates->save( $row );
		$map->remember( Record::TAX_RATE, $record->source_id(), $saved->id, $record->checksum );
		return new WriteResult( $target > 0 ? WriteResult::UPDATED : WriteResult::CREATED, $saved->id );
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
		$action = $map->row( Record::CUSTOMER, $record->source_id() ) === null ? WriteResult::CREATED : WriteResult::UPDATED;
		foreach ( CustomerProfile::TYPES as $type ) {
			$address = self::address( $type, is_array( $record->data[ $type ] ?? null ) ? $record->data[ $type ] : [] );
			if ( $address !== null ) {
				$this->profiles->save( $user_id, $address );
			}
		}
		$map->remember( Record::CUSTOMER, $record->source_id(), $user_id, $record->checksum );
		return new WriteResult( $action, $user_id );
	}

	/**
	 * Null when every field is empty.
	 *
	 * @param array<array-key,mixed> $data
	 */
	public static function address( string $type, array $data ): ?AddressView {
		$field = static fn ( string $key ): string => is_scalar( $data[ $key ] ?? null ) ? trim( (string) $data[ $key ] ) : '';
		$view  = new AddressView(
			type: $type,
			first_name: $field( 'first_name' ),
			last_name: $field( 'last_name' ),
			company: $field( 'company' ),
			address_1: $field( 'address_1' ),
			address_2: $field( 'address_2' ),
			city: $field( 'city' ),
			state: $field( 'state' ),
			postcode: $field( 'postcode' ),
			country: strtoupper( $field( 'country' ) ),
			email: $type === 'billing' ? $field( 'email' ) : '',
			phone: $field( 'phone' ),
		);
		return implode( '', $view->to_array() ) === '' ? null : $view;
	}
}
