<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Mapping;

defined( 'ABSPATH' ) || exit;


/**
 * Shipping zones into WooCommerce, the reverse of FxcShippingWriter. Only
 * the three core method types have a WooCommerce equivalent.
 */
final class WooShippingWriter {
	private const TYPES = [ 'flat_rate', 'free_shipping', 'local_pickup' ];

	/** @var list<Problem> */
	private array $problems = [];

	public function __construct( private readonly WooShippingRepository $repository ) {}

	public function shipping( Record $record, Mapping $map ): WriteResult {
		$this->problems = [];
		$data           = $record->data;
		$source         = strtoupper( (string) ( $data['currency'] ?? '' ) );
		if ( $source !== '' && $source !== get_woocommerce_currency() ) {
			$this->problems[] = Problem::warning( 'shipping_currency', sprintf( 'Shipping amounts are in %1$s and WooCommerce uses %2$s. The numbers are copied as they are.', $source, get_woocommerce_currency() ) );
		}
		$order = 0;
		foreach ( \WC_Shipping_Zones::get_shipping_zones() as $zone ) {
			$order = max( $order, (int) $zone->get_zone_order( 'edit' ) );
		}
		foreach ( (array) ( $data['zones'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source_id = (string) ( $row['id'] ?? '' );
			$target    = $map->target_id( FxcShippingWriter::ZONE, $source_id );
			$zone      = $target > 0 ? \WC_Shipping_Zones::get_zone( $target ) : false;
			if ( ! $zone instanceof \WC_Shipping_Zone ) {
				$zone = new \WC_Shipping_Zone();
				$zone->set_zone_order( ++$order );
			}
			$zone->set_zone_name( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );
			$zone->clear_locations();
			foreach ( (array) ( $row['regions'] ?? [] ) as $region ) {
				$parts = explode( ':', (string) $region, 2 );
				if ( count( $parts ) === 2 && in_array( $parts[0], [ 'country', 'state' ], true ) ) {
					$zone->add_location( $parts[1], $parts[0] );
				}
			}
			foreach ( (array) ( $row['postcodes'] ?? [] ) as $postcode ) {
				$zone->add_location( (string) $postcode, 'postcode' );
			}
			$zone->save();
			$map->remember( FxcShippingWriter::ZONE, $source_id, (int) $zone->get_id() );
			$this->methods( $zone, (array) ( $row['methods'] ?? [] ), $map );
		}
		$rest = is_array( $data['rest'] ?? null ) ? $data['rest'] : [];
		$this->methods( new \WC_Shipping_Zone( 0 ), (array) ( $rest['methods'] ?? [] ), $map );
		\WC_Cache_Helper::get_transient_version( 'shipping', true );

		$action = $map->row( Record::SHIPPING, $record->source_id() ) === null ? WriteResult::CREATED : WriteResult::UPDATED;
		$map->remember( Record::SHIPPING, $record->source_id(), 1, $record->checksum );
		return new WriteResult( $action, 1, $this->problems );
	}

	/** @param array<array-key,mixed> $rows */
	private function methods( \WC_Shipping_Zone $zone, array $rows, Mapping $map ): void {
		$existing = $zone->get_shipping_methods( false, 'json' );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source_id = (string) ( $row['id'] ?? '' );
			$type      = (string) ( $row['type'] ?? '' );
			$title     = (string) ( $row['title'] ?? $type );
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$this->problems[] = Problem::warning( 'unknown_shipping_method', sprintf( 'The shipping method "%1$s" (%2$s) has no WooCommerce equivalent and was not copied.', $title, $type ), [ 'method' => $source_id ] );
				continue;
			}
			$instance_id = $map->target_id( FxcShippingWriter::METHOD, $source_id );
			if ( $instance_id <= 0 || ! isset( $existing[ $instance_id ] ) || (string) ( $existing[ $instance_id ]->id ?? '' ) !== $type ) {
				$instance_id = (int) $zone->add_shipping_method( $type );
			}
			$method = $instance_id > 0 ? \WC_Shipping_Zones::get_shipping_method( $instance_id ) : false;
			if ( ! $method instanceof \WC_Shipping_Method ) {
				$this->problems[] = Problem::warning( 'shipping_method_failed', sprintf( 'The shipping method "%s" could not be added to WooCommerce.', $title ), [ 'method' => $source_id ] );
				continue;
			}
			$method->init_instance_settings();
			$settings = array_merge( $method->instance_settings, $this->settings( $type, $row, $title ) );
			update_option( $method->get_instance_option_key(), $settings );
			$this->repository->set_enabled( $instance_id, ! empty( $row['enabled'] ) );
			$map->remember( FxcShippingWriter::METHOD, $source_id, $instance_id );
		}
	}

	/**
	 * @param array<array-key,mixed> $row
	 * @return array<string,string>
	 */
	private function settings( string $type, array $row, string $title ): array {
		$amount = static fn ( string $key ): string => Decimal::clean( $row[ $key ] ?? null ) ?? '0';
		$out    = [ 'title' => $title ];
		if ( $type === 'free_shipping' ) {
			$min                     = $amount( 'min_amount' );
			$requires                = (string) ( $row['requires'] ?? '' );
			$out['requires']         = in_array( $requires, [ 'coupon', 'either', 'both' ], true ) ? $requires : ( $min === '0' ? '' : 'min_amount' );
			$out['min_amount']       = $min === '0' ? '' : wc_format_localized_price( $min );
			$out['ignore_discounts'] = empty( $row['ignore_discounts'] ) ? 'no' : 'yes';
			return $out;
		}
		$out['tax_status'] = array_key_exists( 'taxable', $row ) && ! $row['taxable'] ? 'none' : 'taxable';
		$fixed             = $amount( 'cost' );
		$item              = $type === 'flat_rate' ? $amount( 'cost_per_item' ) : '0';
		$out['cost']       = match ( true ) {
			$item === '0'  => wc_format_localized_price( $fixed ),
			$fixed === '0' => wc_format_localized_price( $item ) . ' * [qty]',
			default        => wc_format_localized_price( $fixed ) . ' + ' . wc_format_localized_price( $item ) . ' * [qty]',
		};
		if ( $type === 'local_pickup' && trim( (string) ( $row['location'] ?? '' ) ) !== '' ) {
			$this->problems[] = Problem::warning( 'pickup_location', sprintf( 'WooCommerce local pickup has no address field; the pickup address of "%s" was not copied.', $title ), [ 'method' => (string) ( $row['id'] ?? '' ) ] );
		}
		return $out;
	}
}
