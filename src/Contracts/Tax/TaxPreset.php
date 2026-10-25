<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * A market preset for the Taxes screen (doc 02 §7.12.8): one base rate per
 * region, the settings it proposes and an optional market override. Core
 * ships `us`; add one with the `flexacommerce.tax.presets` filter.
 *
 * Regions with a null rate have no tax at that level and get no row.
 *
 * @api
 */
final class TaxPreset {
	/**
	 * @param list<array{state:string,name:string,rate:?string,shipping:bool,source:string}> $regions
	 * @param array<string,mixed>                                                         $settings proposed global settings
	 * @param array<string,mixed>|null                                                    $market   proposed tax market `{id, name, countries, overrides}`
	 * @param list<string>                                                                $origin_states states for `origin_states` in the market
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $country,
		public readonly string $as_of,
		public readonly array $regions,
		public readonly array $settings = [],
		public readonly ?array $market = null,
		public readonly array $origin_states = [],
		public readonly string $label = '{STATE} Tax',
		public readonly string $notes = '',
	) {
		if ( preg_match( '/^[a-z0-9_-]{1,24}$/', $id ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Tax preset id "%s" must be 1 to 24 lower-case letters, digits, - or _.', $id ) );
		}
		if ( preg_match( '/^[A-Z]{2}$/', $country ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Tax preset "%s" needs an ISO country code.', $id ) );
		}
	}

	/**
	 * Build from the JSON shape of `resources/tax-presets/*.json`.
	 *
	 * @param array<string,mixed> $data
	 * @throws \InvalidArgumentException
	 */
	public static function from_array( array $data ): self {
		$regions = [];
		foreach ( is_array( $data['regions'] ?? null ) ? $data['regions'] : [] as $region ) {
			if ( ! is_array( $region ) || ! is_string( $region['state'] ?? null ) ) {
				continue;
			}
			$rate      = $region['rate'] ?? null;
			$regions[] = [
				'state'    => strtoupper( $region['state'] ),
				'name'     => is_string( $region['name'] ?? null ) ? $region['name'] : $region['state'],
				'rate'     => is_scalar( $rate ) ? (string) $rate : null,
				'shipping' => ! empty( $region['shipping'] ),
				'source'   => is_string( $region['source'] ?? null ) ? $region['source'] : '',
			];
		}
		$origin = [];
		foreach ( is_array( $data['origin_states'] ?? null ) ? $data['origin_states'] : [] as $state ) {
			if ( is_string( $state ) ) {
				$origin[] = strtoupper( $state );
			}
		}
		return new self(
			id: (string) ( $data['id'] ?? '' ),
			name: (string) ( $data['name'] ?? '' ),
			country: strtoupper( (string) ( $data['country'] ?? '' ) ),
			as_of: (string) ( $data['as_of'] ?? '' ),
			regions: $regions,
			settings: is_array( $data['settings'] ?? null ) ? $data['settings'] : [],
			market: is_array( $data['market'] ?? null ) ? $data['market'] : null,
			origin_states: $origin,
			label: is_string( $data['label'] ?? null ) ? $data['label'] : '{STATE} Tax',
			notes: is_string( $data['notes'] ?? null ) ? $data['notes'] : '',
		);
	}

	/**
	 * Rate rows for the picked regions (nexus states), in the input shape the
	 * rate table accepts: Standard class, priority 1, source `preset:{id}`.
	 *
	 * @param list<string> $states
	 * @return list<array<string,mixed>>
	 */
	public function rows( array $states ): array {
		$picked = array_flip( array_map( 'strtoupper', $states ) );
		$rows   = [];
		foreach ( $this->regions as $region ) {
			if ( $region['rate'] === null || ! isset( $picked[ $region['state'] ] ) ) {
				continue;
			}
			$rows[] = [
				'tax_class' => '',
				'country'   => $this->country,
				'state'     => $region['state'],
				'rate'      => $region['rate'],
				'name'      => str_replace( '{STATE}', $region['state'], $this->label ),
				'priority'  => 1,
				'compound'  => false,
				'shipping'  => $region['shipping'],
				'source'    => 'preset:' . $this->id,
			];
		}
		return $rows;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'            => $this->id,
			'name'          => $this->name,
			'country'       => $this->country,
			'as_of'         => $this->as_of,
			'regions'       => $this->regions,
			'settings'      => $this->settings,
			'market'        => $this->market,
			'origin_states' => $this->origin_states,
			'notes'         => $this->notes,
		];
	}
}
