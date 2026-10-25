<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Catalog;

use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Plugin;

/**
 * Phase 2 spike (doc 07): ten WooCommerce product shapes are mapped by hand
 * to the canonical product input and saved through ProductService. The
 * mapper here is throwaway; the real WooSource adapter comes in phase 6
 * (doc 05 §5.2). Findings are written up in doc 03 §3.9.
 */
final class WooCommerceMappingSpikeTest extends CatalogTestCase {
	private const CURRENCY_EXPONENT = 2;
	private const WEIGHT_PLACES     = 3; // kg to g.
	private const DIMENSION_PLACES  = 1; // cm to mm.

	/** @var array{taxonomies:array<string,array{label:string,terms:array<string,string>}>,products:list<array<string,mixed>>} */
	private array $fixture;

	/** @var array<string,int> pa_{name} => attribute term id */
	private array $attribute_ids = [];

	/** @var array<string,array<string,int>> pa_{name} => slug => value term id */
	private array $value_ids = [];

	/** @var list<string> */
	private array $warnings = [];

	public function set_up(): void {
		parent::set_up();
		$this->fixture = require dirname( __DIR__, 2 ) . '/fixtures/woocommerce-products.php';
		$this->import_taxonomies();
	}

	public function test_fixture_covers_ten_products(): void {
		$this->assertCount( 10, $this->fixture['products'] );
	}

	public function test_attributes_sharing_a_label_are_both_imported(): void {
		$repository = Plugin::instance()->catalog()->attributes();
		$size       = $repository->attribute( $this->attribute_ids['pa_size'] );
		$print      = $repository->attribute( $this->attribute_ids['pa_print-size'] );

		$this->assertNotNull( $size );
		$this->assertNotNull( $print );
		$this->assertSame( 'Size', $size->name );
		$this->assertSame( 'Size', $print->name );
		$this->assertNotSame( $size->slug, $print->slug );
	}

	public function test_local_attribute_variations_with_display_values(): void {
		$product = $this->import( 60 );

		$this->assertSame( 'variable', $product->type );
		$this->assertSame( [ 'Small', 'Large' ], $product->attributes[0]->values );
		$this->assertSame( [ 'l:size' => 'small' ], $product->variations[0]->combination->to_array() );
		$this->assertSame( 10000, $product->variations[0]->regular_price );
		$this->assertSame( 8000, $product->variations[0]->sale_price );
		$this->assertSame( 16000, $product->variations[1]->sale_price );
	}

	public function test_empty_variation_values_become_wildcards(): void {
		$product = $this->import( 703 );
		$color   = Combination::global_key( $this->attribute_ids['pa_color'] );
		$size    = Combination::global_key( $this->attribute_ids['pa_size'] );
		$made_in = Combination::global_key( $this->attribute_ids['pa_made-in'] );
		$pattern = Combination::global_key( $this->attribute_ids['pa_pattern'] );

		$this->assertCount( 4, $product->attributes );
		$this->assertSame( 3, $product->variations[0]->combination->wildcard_count() );

		$match = Plugin::instance()->catalog()->matcher()->match(
			$product->variations,
			[
				$color   => (string) $this->value_ids['pa_color']['red'],
				$size    => (string) $this->value_ids['pa_size']['medium'],
				$made_in => (string) $this->value_ids['pa_made-in']['uk'],
				$pattern => (string) $this->value_ids['pa_pattern']['shirt'],
			]
		);
		$this->assertNotNull( $match );
		$this->assertSame( $product->variations[1]->id, $match->id );
	}

	public function test_full_grid_of_global_variations(): void {
		$product = $this->import( 1412 );

		$this->assertTrue( $product->featured );
		$this->assertSame( 'NB-COAST', $product->base->sku );
		$this->assertCount( 3, $product->attributes );
		$this->assertCount( 24, $product->variations );
		$this->assertSame( 3800, $product->variations[0]->regular_price );
		$this->assertSame( 12400, $product->variations[23]->regular_price );
		// WooCommerce stored menu_order 0 for every row; the import keeps its id order explicitly.
		$this->assertSame( range( 0, 23 ), array_map( static fn ( Variant $v ): int => $v->menu_order, $product->variations ) );

		$min = get_post_meta( $product->id, '_fxc_price_min', true );
		$max = get_post_meta( $product->id, '_fxc_price_max', true );
		$this->assertSame( '3800', (string) $min );
		$this->assertSame( '12700', (string) $max );
	}

	public function test_simple_products(): void {
		$sale = $this->import( 1403 );
		$this->assertSame( 3800, $sale->base->regular_price );
		$this->assertSame( 2900, $sale->base->sale_price );
		$this->assertNull( $sale->base->sale_from );

		$stock = $this->import( 1406 );
		$this->assertTrue( $stock->base->manage_stock );
		$this->assertSame( 2, $stock->base->stock_quantity );

		$draft = $this->import( 203 );
		$this->assertNull( $draft->base->sku );
		$this->assertSame( 4899, $draft->base->regular_price );
	}

	public function test_parent_managed_stock_maps_to_stock_from_base(): void {
		$product             = $this->import( 9001 );
		[ $natural, $black ] = $product->variations;

		$this->assertTrue( $product->base->manage_stock );
		$this->assertSame( 12, $product->base->stock_quantity );
		$this->assertTrue( $natural->stock_from_base );
		$this->assertSame( $product->base, $product->stock_owner( $natural ) );
		$this->assertFalse( $black->stock_from_base );
		$this->assertSame( 3, $black->stock_quantity );
		$this->assertSame( 'notify', $black->backorders );
		$this->assertSame( 'Black cotton canvas.', $black->description );

		$this->assertSame( '350', $this->decimal( $product->base->weight ) );
		$this->assertSame( '400', $this->decimal( $product->base->length ) );
		$this->assertSame( '105', $this->decimal( $product->base->height ) );
		// An empty variation weight falls back to the base row (doc 03 §3.6).
		$this->assertNull( $natural->weight );
		$this->assertSame( '400', $this->decimal( $black->weight ) );
	}

	public function test_sale_schedule_and_backorders(): void {
		$product = $this->import( 9010 );

		$this->assertSame( 4900, $product->base->regular_price );
		$this->assertSame( 3950, $product->base->sale_price );
		$this->assertSame( '2026-01-01 00:00:00', $product->base->sale_from );
		// WooCommerce stores the last second of the end day; our sale_to is exclusive.
		$this->assertSame( '2026-01-09 00:00:00', $product->base->sale_to );
		$this->assertSame( 0, $product->base->stock_quantity );
		$this->assertSame( 3, $product->base->low_stock_threshold );
		$this->assertSame( Variant::ONBACKORDER, $product->base->stock_status );
		$this->assertSame( '200', $this->decimal( $product->base->weight ) );
		$this->assertNull( $product->base->length );
		$this->assertSame( 'catalog', $product->visibility );
	}

	public function test_unpriced_product_with_padded_sku(): void {
		$product = $this->import( 9020 );

		$this->assertNull( $product->base->regular_price );
		$this->assertSame( 'WRAP-01', $product->base->sku );
		$this->assertSame( 5, $product->base->stock_quantity );
		$this->assertSame( 'hidden', $product->visibility );
	}

	public function test_data_woocommerce_accepts_but_we_reject_is_reported(): void {
		$product = $this->import( 9030 );

		// Kept: the wildcard row (sale dropped) and the private row (disabled).
		$this->assertCount( 2, $product->variations );
		[ $red_any, $blue ] = $product->variations;
		$this->assertNull( $red_any->sale_price );
		$this->assertSame( 1, $red_any->combination->wildcard_count() );
		$this->assertFalse( $blue->enabled );

		$this->assertSame(
			[
				'9031: sale price not below regular price, sale dropped',
				'9032: same attribute values as an earlier variation, skipped',
				'9034: pa_color value "green" is not offered by the product, skipped',
			],
			$this->warnings
		);
	}

	private function import_taxonomies(): void {
		$repository = Plugin::instance()->catalog()->attributes();
		foreach ( $this->fixture['taxonomies'] as $taxonomy => $data ) {
			// Suffix the slug so a crashed earlier run cannot collide.
			$slug = substr( $taxonomy, 3 ) . '-' . strtolower( $this->run );
			$id   = $repository->create_attribute( $data['label'], $slug );
			$this->track_term( $id );
			$this->attribute_ids[ $taxonomy ] = $id;
			foreach ( $data['terms'] as $term_slug => $name ) {
				$value = $repository->create_value( $id, $name, $term_slug . '-' . strtolower( $this->run ) );
				$this->track_term( $value );
				$this->value_ids[ $taxonomy ][ (string) $term_slug ] = $value;
			}
		}
	}

	private function import( int $source_id ): Product {
		$source = null;
		foreach ( $this->fixture['products'] as $candidate ) {
			if ( $candidate['id'] === $source_id ) {
				$source = $candidate;
			}
		}
		$this->assertNotNull( $source );

		$id = $this->product( $source['title'] );
		return $this->save( $id, $this->map( $source ) );
	}

	/**
	 * @param array<string,mixed> $source
	 * @return array<string,mixed>
	 */
	private function map( array $source ): array {
		$attributes = [];
		$axes       = []; // attribute_{name} meta key => [ key, value map or null for local ]
		$wc         = $source['attributes'];
		uasort( $wc, static fn ( array $a, array $b ): int => (int) $a['position'] <=> (int) $b['position'] );
		foreach ( $wc as $name => $attribute ) {
			if ( (int) $attribute['is_taxonomy'] === 1 ) {
				$values = [];
				foreach ( $source['terms'][ $name ] ?? [] as $slug ) {
					$values[ $slug ] = $this->value_ids[ $name ][ $slug ];
				}
				$attributes[]                 = [
					'type'              => 'global',
					'attribute_term_id' => $this->attribute_ids[ $name ],
					'value_term_ids'    => array_values( $values ),
					'visible'           => (bool) $attribute['is_visible'],
					'variation'         => (bool) $attribute['is_variation'],
				];
				$axes[ 'attribute_' . $name ] = [ Combination::global_key( $this->attribute_ids[ $name ] ), $values ];
				continue;
			}
			$attributes[]                 = [
				'type'      => 'local',
				'name'      => $attribute['name'],
				'values'    => array_map( 'trim', explode( '|', (string) $attribute['value'] ) ),
				'visible'   => (bool) $attribute['is_visible'],
				'variation' => (bool) $attribute['is_variation'],
			];
			$axes[ 'attribute_' . $name ] = [ Combination::local_key( (string) $attribute['name'] ), null ];
		}

		$visibility_terms = $source['visibility_terms'];
		$hide_catalog     = in_array( 'exclude-from-catalog', $visibility_terms, true );
		$hide_search      = in_array( 'exclude-from-search', $visibility_terms, true );

		$data = [
			'type'       => $source['type'],
			'visibility' => $hide_catalog && $hide_search ? 'hidden' : ( $hide_catalog ? 'search' : ( $hide_search ? 'catalog' : 'visible' ) ),
			'featured'   => in_array( 'featured', $visibility_terms, true ),
			'attributes' => $attributes,
			'base'       => $this->row( $source['id'], $source['meta'] ),
		];

		if ( $source['type'] === 'variable' ) {
			$parent_manages = ( $source['meta']['_manage_stock'] ?? 'no' ) === 'yes';
			$rows           = [];
			$seen           = [];
			$variations     = $source['variations'];
			usort( $variations, static fn ( array $a, array $b ): int => [ $a['menu_order'], $a['id'] ] <=> [ $b['menu_order'], $b['id'] ] );
			foreach ( $variations as $variation ) {
				$combination = [];
				foreach ( $axes as $meta_key => [ $key, $values ] ) {
					$value = (string) ( $variation['meta'][ $meta_key ] ?? '' );
					if ( $value === '' ) {
						$combination[ $key ] = Combination::WILDCARD;
					} elseif ( $values === null ) {
						$combination[ $key ] = Combination::local_value( $value );
					} elseif ( isset( $values[ $value ] ) ) {
						$combination[ $key ] = (string) $values[ $value ];
					} else {
						$this->warnings[] = sprintf( '%d: %s value "%s" is not offered by the product, skipped', $variation['id'], substr( $meta_key, 10 ), $value );
						continue 2;
					}
				}
				$hash = Combination::from_array( $combination )->hash();
				if ( isset( $seen[ $hash ] ) ) {
					$this->warnings[] = sprintf( '%d: same attribute values as an earlier variation, skipped', $variation['id'] );
					continue;
				}
				$seen[ $hash ] = true;

				$meta   = $variation['meta'];
				$rows[] = [
					'attributes'      => $combination,
					'enabled'         => $variation['status'] === 'publish',
					'menu_order'      => count( $rows ),
					// WooCommerce does not store "parent": an unmanaged variation of a managed parent uses the parent's stock.
					'stock_from_base' => $parent_manages && ( $meta['_manage_stock'] ?? 'no' ) !== 'yes',
					'description'     => $meta['_variation_description'] ?? null,
				] + $this->row( $variation['id'], $meta );
			}
			$data['variations'] = $rows;
		}
		return $data;
	}

	/**
	 * @param array<string,?string> $meta
	 * @return array<string,mixed>
	 */
	private function row( int $source_id, array $meta ): array {
		$regular = $this->money( $meta['_regular_price'] ?? null );
		$sale    = $this->money( $meta['_sale_price'] ?? null );
		if ( $sale !== null && ( $regular === null || $sale >= $regular ) ) {
			$this->warnings[] = sprintf( '%d: sale price not below regular price, sale dropped', $source_id );
			$sale             = null;
		}
		$from = $meta['_sale_price_dates_from'] ?? '';
		$to   = $meta['_sale_price_dates_to'] ?? '';

		$row = [
			'sku'                 => $meta['_sku'] ?? null,
			'regular_price'       => $regular,
			'sale_price'          => $sale,
			'sale_from'           => $sale !== null && $from !== '' ? gmdate( 'Y-m-d H:i:s', (int) $from ) : null,
			'sale_to'             => $sale !== null && $to !== '' ? gmdate( 'Y-m-d H:i:s', (int) $to + 1 ) : null,
			'manage_stock'        => ( $meta['_manage_stock'] ?? 'no' ) === 'yes',
			'backorders'          => $meta['_backorders'] ?? 'no',
			'stock_status'        => $meta['_stock_status'] ?? Variant::INSTOCK,
			'low_stock_threshold' => ( $meta['_low_stock_amount'] ?? '' ) === '' ? null : (int) $meta['_low_stock_amount'],
			'weight'              => $this->shift( $meta['_weight'] ?? null, self::WEIGHT_PLACES ),
			'length'              => $this->shift( $meta['_length'] ?? null, self::DIMENSION_PLACES ),
			'width'               => $this->shift( $meta['_width'] ?? null, self::DIMENSION_PLACES ),
			'height'              => $this->shift( $meta['_height'] ?? null, self::DIMENSION_PLACES ),
		];
		if ( $row['manage_stock'] ) {
			$row['stock_quantity'] = (int) floor( (float) ( $meta['_stock'] ?? 0 ) );
		}
		return $row;
	}

	private function money( ?string $value ): ?int {
		$shifted = $this->shift( $value, self::CURRENCY_EXPONENT );
		return $shifted === null ? null : (int) $shifted;
	}

	/** Moves the decimal point right with string arithmetic; null for an empty value. */
	private function shift( ?string $value, int $places ): ?string {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return null;
		}
		$this->assertMatchesRegularExpression( '/^\d+(\.\d+)?$/', $value );
		[ $int, $fraction ] = array_pad( explode( '.', $value, 2 ), 2, '' );
		$fraction           = str_pad( $fraction, $places, '0' );
		$whole              = ltrim( $int . substr( $fraction, 0, $places ), '0' );
		$rest               = rtrim( substr( $fraction, $places ), '0' );
		return ( $whole === '' ? '0' : $whole ) . ( $rest === '' ? '' : '.' . $rest );
	}

	/** decimal(12,3) comes back as "350.000". */
	private function decimal( ?string $value ): ?string {
		return $value === null ? null : rtrim( rtrim( $value, '0' ), '.' );
	}
}
