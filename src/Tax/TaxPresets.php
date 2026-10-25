<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Tax\TaxPreset;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Market presets (doc 02 §7.12.8): core files in resources/tax-presets plus
 * the `flexacommerce.tax.presets` filter. Applying one replaces only the rows
 * with its own source, so manual and imported rates stay.
 */
final class TaxPresets {
	/** @var array<string,TaxPreset>|null */
	private ?array $presets = null;

	public function __construct( private readonly TaxService $tax ) {}

	/** @return array<string,TaxPreset> */
	public function all(): array {
		if ( $this->presets !== null ) {
			return $this->presets;
		}
		$core = [];
		foreach ( glob( FLEXACOMMERCE_PATH . 'resources/tax-presets/*.json' ) ?: [] as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file shipped with the plugin.
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $data ) ) {
				$core[] = TaxPreset::from_array( $data );
			}
		}
		$list = apply_filters( 'flexacommerce.tax.presets', $core );
		if ( ! is_array( $list ) ) {
			_doing_it_wrong( 'flexacommerce.tax.presets', 'The filter must return a list of TaxPreset.', '1.0.0' );
			$list = $core;
		}
		$out = [];
		foreach ( $list as $preset ) {
			if ( $preset instanceof TaxPreset ) {
				$out[ $preset->id ] = $preset;
			}
		}
		$this->presets = $out;
		return $out;
	}

	public function get( string $id ): ?TaxPreset {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * What applying the preset for these states would change: rows added,
	 * rows of the same source removed, and the settings it proposes.
	 *
	 * @param list<string> $states
	 * @return array{add:list<array<string,mixed>>,remove:list<array<string,mixed>>,settings:array<string,mixed>,market:?array<string,mixed>}
	 */
	public function preview( TaxPreset $preset, array $states ): array {
		$add = [];
		foreach ( $this->rows( $preset, $states ) as $row ) {
			$add[] = $row->to_array();
		}
		$remove = [];
		foreach ( $this->tax->repository()->all( null, 'preset:' . $preset->id ) as $row ) {
			$remove[] = $row->to_array();
		}
		return [
			'add'      => $add,
			'remove'   => $remove,
			'settings' => $preset->settings,
			'market'   => $this->market( $preset ),
		];
	}

	/**
	 * Replace the preset's rows and save the settings the admin kept ticked.
	 *
	 * @param list<string> $states
	 * @param list<string> $settings keys of the preset settings to apply; `market` for the market override
	 * @return int rates written
	 */
	public function apply( TaxPreset $preset, array $states, array $settings ): int {
		$written = $this->tax->repository()->replace_source( 'preset:' . $preset->id, $this->rows( $preset, $states ) );
		$partial = array_intersect_key( $preset->settings, array_flip( $settings ) );
		$market  = in_array( 'market', $settings, true ) ? $this->market( $preset ) : null;
		if ( $market !== null ) {
			$markets                = array_values(
				array_filter(
					(array) Settings::get( 'tax_markets' ),
					static fn ( mixed $m ): bool => is_array( $m ) && ( $m['id'] ?? '' ) !== $market['id']
				)
			);
			$partial['tax_markets'] = [ ...$markets, $market ];
		}
		if ( $partial !== [] ) {
			Settings::update( $partial );
		}
		$this->tax->compile_base_rates();
		return $written;
	}

	/**
	 * @param list<string> $states
	 * @return list<RateRow>
	 */
	private function rows( TaxPreset $preset, array $states ): array {
		return array_map( static fn ( array $row ): RateRow => RateRow::from_input( $row ), $preset->rows( $states ) );
	}

	/** @return array<string,mixed>|null */
	private function market( TaxPreset $preset ): ?array {
		if ( $preset->market === null ) {
			return null;
		}
		$market              = $preset->market;
		$overrides           = is_array( $market['overrides'] ?? null ) ? $market['overrides'] : [];
		$market['overrides'] = $overrides + ( $preset->origin_states !== [] ? [ 'origin_states' => $preset->origin_states ] : [] );
		return $market;
	}
}
