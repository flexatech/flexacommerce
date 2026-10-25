<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Catalog;

use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\VariantRepository;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Plugin;

final class ProductServiceTest extends CatalogTestCase {
	public function test_new_product_gets_a_base_row(): void {
		$id   = $this->product();
		$rows = Plugin::instance()->catalog()->variants()->for_product( $id );

		self::assertCount( 1, $rows );
		self::assertTrue( $rows[0]->is_base );
		self::assertSame( sha1( '' ), $rows[0]->combination->hash() );
		self::assertSame( 'simple', get_post_meta( $id, ProductRepository::META_TYPE, true ) );
	}

	public function test_simple_product_prices_and_denormalized_meta(): void {
		$id    = $this->product();
		$saved = $this->save(
			$id,
			[
				'base' => [
					'regular_price' => 2500,
					'sale_price'    => 1999,
					'weight'        => '1250.5',
				],
			]
		);

		self::assertSame( 2500, $saved->base->regular_price );
		self::assertSame( '1250.500', $saved->base->weight );
		self::assertSame( '1999', get_post_meta( $id, ProductRepository::META_PRICE_MIN, true ) );
		self::assertSame( '1999', get_post_meta( $id, ProductRepository::META_PRICE_MAX, true ) );
		self::assertSame( 'instock', get_post_meta( $id, ProductRepository::META_STOCK_STATUS, true ) );
	}

	public function test_sale_price_must_be_below_regular(): void {
		try {
			$this->save(
				$this->product(),
				[
					'base' => [
						'regular_price' => 1000,
						'sale_price'    => 1000,
					],
				]
			);
			self::fail( 'Expected InvalidProduct' );
		} catch ( InvalidProduct $e ) {
			self::assertSame( 'base.sale_price', $e->field );
		}
	}

	public function test_sale_schedule_sets_next_price_change_and_cron_refreshes(): void {
		$id   = $this->product();
		$soon = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->save(
			$id,
			[
				'base' => [
					'regular_price' => 1000,
					'sale_price'    => 800,
					'sale_from'     => $soon,
				],
			]
		);

		self::assertSame( $soon, get_post_meta( $id, ProductRepository::META_NEXT_CHANGE, true ) );
		self::assertSame( '1000', get_post_meta( $id, ProductRepository::META_PRICE_MIN, true ) );

		// Pretend the start time has passed.
		update_post_meta( $id, ProductRepository::META_NEXT_CHANGE, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'fxc_variants', [ 'sale_from' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ], [ 'product_id' => $id ] );

		self::assertGreaterThanOrEqual( 1, $this->service()->refresh_due_prices() );
		self::assertSame( '800', get_post_meta( $id, ProductRepository::META_PRICE_MIN, true ) );
		self::assertSame( '', get_post_meta( $id, ProductRepository::META_NEXT_CHANGE, true ) );
	}

	public function test_variable_product_generates_every_combination_once(): void {
		[ $color, [ $red, $blue ] ] = $this->attribute( 'Color', 'Red', 'Blue' );
		$id                         = $this->product();
		$this->save(
			$id,
			[
				'type'       => 'variable',
				'attributes' => [
					[
						'type'              => 'global',
						'attribute_term_id' => $color,
						'value_term_ids'    => [ $red, $blue ],
						'variation'         => true,
					],
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => [ 'S', 'M', 'L' ],
						'variation' => true,
					],
				],
			]
		);

		$first = $this->service()->generate_variants( $id );
		self::assertSame( 6, $first->created );
		self::assertCount( 6, $first->product->variations );

		$again = $this->service()->generate_variants( $id );
		self::assertSame( 0, $again->created );
		self::assertSame( 6, $again->skipped );

		// Global value terms are assigned to the post for tax queries.
		$terms = wp_get_object_terms( $id, 'fxc_attribute', [ 'fields' => 'ids' ] );
		self::assertEqualsCanonicalizing( [ $red, $blue ], array_map( 'intval', $terms ) );
	}

	public function test_generation_can_be_limited_to_some_values(): void {
		$id     = $this->variable_product( [ 'S', 'M', 'L' ] );
		$result = $this->service()->generate_variants( $id, [ 'l:size' => [ 's', 'l' ] ] );
		self::assertSame( 2, $result->created );
	}

	public function test_global_attribute_rejects_foreign_values(): void {
		[ $color ]             = $this->attribute( 'Color', 'Red' );
		[ , [ $other_value ] ] = $this->attribute( 'Material', 'Wool' );

		$this->expectException( InvalidProduct::class );
		$this->save(
			$this->product(),
			[
				'attributes' => [
					[
						'type'              => 'global',
						'attribute_term_id' => $color,
						'value_term_ids'    => [ $other_value ],
					],
				],
			]
		);
	}

	public function test_removed_value_deletes_variations_unless_they_hold_stock(): void {
		$id = $this->variable_product( [ 'S', 'M', 'L' ] );
		$this->service()->generate_variants( $id );
		$product = $this->service()->get( $id );
		$medium  = null;
		foreach ( $product->variations as $variation ) {
			if ( $variation->combination->get( 'l:size' ) === 'm' ) {
				$medium = $variation;
			}
		}
		self::assertNotNull( $medium );
		$this->save(
			$id,
			[
				'variations' => array_map(
					static fn ( $v ): array => $v->id === $medium->id
						? [
							'id'             => $v->id,
							'manage_stock'   => true,
							'stock_quantity' => 4,
						]
						: [ 'id' => $v->id ],
					$product->variations
				),
			]
		);

		// Drop M and L from the attribute; M has stock, L does not.
		$saved = $this->save(
			$id,
			[
				'attributes' => [
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => [ 'S' ],
						'variation' => true,
					],
				],
			]
		);

		self::assertCount( 2, $saved->variations );
		foreach ( $saved->variations as $variation ) {
			if ( $variation->id === $medium->id ) {
				self::assertFalse( $variation->enabled );
			} else {
				self::assertSame( 's', $variation->combination->get( 'l:size' ) );
			}
		}
	}

	public function test_new_variation_attribute_fills_existing_rows_with_wildcard(): void {
		$id = $this->variable_product( [ 'S' ] );
		$this->service()->generate_variants( $id );

		$saved = $this->save(
			$id,
			[
				'attributes' => [
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => [ 'S' ],
						'variation' => true,
					],
					[
						'type'      => 'local',
						'name'      => 'Fit',
						'values'    => [ 'Slim', 'Regular' ],
						'variation' => true,
					],
				],
			]
		);
		self::assertSame( '*', $saved->variations[0]->combination->get( 'l:fit' ) );
	}

	public function test_variation_with_unknown_value_is_rejected(): void {
		$id = $this->variable_product( [ 'S' ] );

		try {
			$this->save( $id, [ 'variations' => [ [ 'attributes' => [ 'l:size' => 'xxl' ] ] ] ] );
			self::fail( 'Expected InvalidProduct' );
		} catch ( InvalidProduct $e ) {
			self::assertSame( 'variations.0.attributes', $e->field );
		}
	}

	public function test_two_variations_with_the_same_values_are_rejected(): void {
		$id = $this->variable_product( [ 'S' ] );

		$this->expectException( InvalidProduct::class );
		$this->save(
			$id,
			[
				'variations' => [
					[ 'attributes' => [ 'l:size' => 's' ] ],
					[ 'attributes' => [ 'l:size' => 's' ] ],
				],
			]
		);
	}

	public function test_dropping_an_axis_keeps_one_row_per_remaining_combination(): void {
		$id = $this->variable_product( [ 'S', 'M' ] );
		$this->save(
			$id,
			[
				'attributes' => [
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => [ 'S', 'M' ],
						'variation' => true,
					],
					[
						'type'      => 'local',
						'name'      => 'Fit',
						'values'    => [ 'Slim', 'Regular' ],
						'variation' => true,
					],
				],
			]
		);
		self::assertSame( 4, $this->service()->generate_variants( $id )->created );

		$saved = $this->save(
			$id,
			[
				'attributes' => [
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => [ 'S', 'M' ],
						'variation' => true,
					],
				],
			]
		);

		$sizes = array_map( static fn ( $v ): ?string => $v->combination->get( 'l:size' ), $saved->variations );
		self::assertEqualsCanonicalizing( [ 's', 'm' ], $sizes );
		foreach ( $saved->variations as $variation ) {
			self::assertNull( $variation->combination->get( 'l:fit' ) );
		}
	}

	public function test_deleted_store_attribute_is_dropped_from_products(): void {
		[ $color, [ $red, $blue ] ] = $this->attribute( 'Color', 'Red', 'Blue' );
		$id                         = $this->product();
		$this->save(
			$id,
			[
				'type'       => 'variable',
				'attributes' => [
					[
						'type'              => 'global',
						'attribute_term_id' => $color,
						'value_term_ids'    => [ $red, $blue ],
						'variation'         => true,
					],
				],
			]
		);
		$this->service()->generate_variants( $id );
		self::assertSame( 1, Plugin::instance()->catalog()->attributes()->usage( $color ) );

		Plugin::instance()->catalog()->attributes()->delete( $color );

		self::assertSame( [], $this->service()->get( $id )->attributes );
		$saved = $this->save( $id, [ 'base' => [ 'sku' => $this->sku( 'after-delete' ) ] ] );
		self::assertSame( [], $saved->variations );
	}

	public function test_variations_left_out_of_the_input_are_deleted(): void {
		$id    = $this->variable_product( [ 'S', 'M' ] );
		$first = $this->service()->generate_variants( $id )->product;

		$saved = $this->save( $id, [ 'variations' => [ [ 'id' => $first->variations[0]->id ] ] ] );
		self::assertCount( 1, $saved->variations );
		self::assertSame( $first->variations[0]->id, $saved->variations[0]->id );
	}

	public function test_variable_to_simple_deletes_variations_and_keeps_base(): void {
		$id = $this->variable_product( [ 'S', 'M' ] );
		$this->service()->generate_variants( $id );
		$base_id = $this->service()->get( $id )->base->id;

		$saved = $this->save( $id, [ 'type' => 'simple' ] );
		self::assertSame( [], $saved->variations );
		self::assertSame( $base_id, $saved->base->id );
		self::assertCount( 1, Plugin::instance()->catalog()->variants()->for_product( $id ) );
	}

	public function test_variable_price_range_uses_in_stock_variations(): void {
		$id        = $this->variable_product( [ 'S', 'M' ] );
		$product   = $this->service()->generate_variants( $id )->product;
		[ $a, $b ] = $product->variations;

		$this->save(
			$id,
			[
				'variations' => [
					[
						'id'            => $a->id,
						'regular_price' => 1000,
					],
					[
						'id'             => $b->id,
						'regular_price'  => 3000,
						'manage_stock'   => true,
						'stock_quantity' => 0,
					],
				],
			]
		);
		self::assertSame( '1000', get_post_meta( $id, ProductRepository::META_PRICE_MAX, true ) );
	}

	public function test_deleting_the_post_removes_its_rows(): void {
		$id = $this->variable_product( [ 'S', 'M' ] );
		$this->service()->generate_variants( $id );

		wp_delete_post( $id, true );
		wp_cache_delete( $id, VariantRepository::CACHE_GROUP );
		self::assertSame( [], Plugin::instance()->catalog()->variants()->for_product( $id ) );
	}

	public function test_duplicate_copies_rows_without_skus(): void {
		$id = $this->variable_product( [ 'S', 'M' ] );
		$this->save( $id, [ 'base' => [ 'sku' => $this->sku( 'DUP' ) ] ] );
		$this->service()->generate_variants( $id );

		$copy_id = $this->service()->duplicate( $id );
		$this->track_post( $copy_id );
		$copy = $this->service()->get( $copy_id );

		self::assertSame( 'draft', get_post_status( $copy_id ) );
		self::assertSame( 'variable', $copy->type );
		self::assertCount( 2, $copy->variations );
		self::assertNull( $copy->base->sku );
	}

	public function test_prime_loads_many_products_in_one_query(): void {
		$ids = [ $this->product(), $this->product(), $this->product() ];
		foreach ( $ids as $id ) {
			wp_cache_delete( $id, VariantRepository::CACHE_GROUP );
		}

		global $wpdb;
		$before = $wpdb->num_queries;
		Plugin::instance()->catalog()->products()->prime( $ids );
		self::assertSame( 1, $wpdb->num_queries - $before );

		$before = $wpdb->num_queries;
		foreach ( $ids as $id ) {
			Plugin::instance()->catalog()->variants()->for_product( $id );
		}
		self::assertSame( 0, $wpdb->num_queries - $before );
	}

	public function test_product_with_100_variants_saves_within_two_seconds(): void {
		$sizes = array_map( static fn ( int $i ): string => 'Size ' . $i, range( 1, 10 ) );
		$id    = $this->product();
		$this->save(
			$id,
			[
				'type'       => 'variable',
				'attributes' => [
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => $sizes,
						'variation' => true,
					],
					[
						'type'      => 'local',
						'name'      => 'Color',
						'values'    => array_map( static fn ( int $i ): string => 'Color ' . $i, range( 1, 10 ) ),
						'variation' => true,
					],
				],
			]
		);
		$product = $this->service()->generate_variants( $id )->product;
		self::assertCount( 100, $product->variations );

		$input = [];
		foreach ( $product->variations as $i => $variation ) {
			$input[] = [
				'id'             => $variation->id,
				'sku'            => $this->sku( 'BULK' . $i ),
				'regular_price'  => 1000 + $i,
				'manage_stock'   => true,
				'stock_quantity' => 5,
			];
		}

		$started = microtime( true );
		$saved   = $this->save( $id, [ 'variations' => $input ] );
		$elapsed = microtime( true ) - $started;

		self::assertCount( 100, $saved->variations );
		fwrite( STDERR, sprintf( "\n100-variant save: %.3f s\n", $elapsed ) );
		self::assertLessThan( 2.0, $elapsed, sprintf( 'Saving 100 variations took %.2f s', $elapsed ) );
	}
}
