<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Catalog;

use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Exceptions\DuplicateSku;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Plugin;

final class SkuRulesTest extends CatalogTestCase {
	public function test_sku_is_trimmed_and_empty_becomes_null(): void {
		$a = $this->product();
		$b = $this->product();

		$saved = $this->save( $a, [ 'base' => [ 'sku' => '  ' . $this->sku( 'TRIM' ) . '  ' ] ] );
		self::assertSame( $this->sku( 'TRIM' ), $saved->base->sku );

		// Several products without a SKU must not collide on the unique index.
		self::assertNull( $this->save( $a, [ 'base' => [ 'sku' => '' ] ] )->base->sku );
		self::assertNull( $this->save( $b, [ 'base' => [ 'sku' => '   ' ] ] )->base->sku );
	}

	public function test_sku_longer_than_100_characters_is_rejected(): void {
		$this->expectException( InvalidProduct::class );
		$this->save( $this->product(), [ 'base' => [ 'sku' => str_repeat( 'x', 101 ) ] ] );
	}

	public function test_sku_used_by_another_product_is_rejected_ignoring_case(): void {
		$holder = $this->product();
		$this->save( $holder, [ 'base' => [ 'sku' => $this->sku( 'shirt' ) ] ] );

		try {
			$this->save( $this->product(), [ 'base' => [ 'sku' => strtoupper( $this->sku( 'shirt' ) ) ] ] );
			self::fail( 'Expected DuplicateSku' );
		} catch ( DuplicateSku $e ) {
			self::assertSame( $holder, $e->product_id );
		}
	}

	public function test_saving_the_same_sku_again_on_its_own_product_is_fine(): void {
		$id = $this->product();
		$this->save( $id, [ 'base' => [ 'sku' => $this->sku( 'SAME' ) ] ] );
		$saved = $this->save(
			$id,
			[
				'base' => [
					'sku'           => $this->sku( 'SAME' ),
					'regular_price' => 500,
				],
			]
		);
		self::assertSame( 500, $saved->base->regular_price );
	}

	public function test_variation_sku_cannot_repeat_inside_one_save(): void {
		$id = $this->variable_product( [ 'S', 'M' ] );

		$this->expectException( DuplicateSku::class );
		$this->save(
			$id,
			[
				'variations' => [
					[
						'attributes' => [ 'l:size' => 's' ],
						'sku'        => $this->sku( 'v' ),
					],
					[
						'attributes' => [ 'l:size' => 'm' ],
						'sku'        => strtolower( $this->sku( 'V' ) ),
					],
				],
			]
		);
	}

	public function test_variation_sku_cannot_match_another_products_base(): void {
		$other = $this->product();
		$this->save( $other, [ 'base' => [ 'sku' => $this->sku( 'base' ) ] ] );
		$id = $this->variable_product( [ 'S' ] );

		$this->expectException( DuplicateSku::class );
		$this->save(
			$id,
			[
				'variations' => [
					[
						'attributes' => [ 'l:size' => 's' ],
						'sku'        => $this->sku( 'base' ),
					],
				],
			]
		);
	}

	public function test_skus_can_be_swapped_between_variations_in_one_save(): void {
		$id                 = $this->variable_product( [ 'S', 'M' ] );
		$saved              = $this->save(
			$id,
			[
				'variations' => [
					[
						'attributes' => [ 'l:size' => 's' ],
						'sku'        => $this->sku( 'one' ),
					],
					[
						'attributes' => [ 'l:size' => 'm' ],
						'sku'        => $this->sku( 'two' ),
					],
				],
			]
		);
		[ $small, $medium ] = $saved->variations;

		$swapped = $this->save(
			$id,
			[
				'variations' => [
					[
						'id'  => $small->id,
						'sku' => $this->sku( 'two' ),
					],
					[
						'id'  => $medium->id,
						'sku' => $this->sku( 'one' ),
					],
				],
			]
		);
		$by_id   = [];
		foreach ( $swapped->variations as $variation ) {
			$by_id[ $variation->id ] = $variation->sku;
		}
		self::assertSame( $this->sku( 'two' ), $by_id[ $small->id ] );
		self::assertSame( $this->sku( 'one' ), $by_id[ $medium->id ] );
	}

	public function test_repository_maps_duplicate_key_error_to_duplicate_sku(): void {
		$holder = $this->product();
		$this->save( $holder, [ 'base' => [ 'sku' => $this->sku( 'RAW' ) ] ] );
		$other = $this->product();

		try {
			Plugin::instance()->catalog()->variants()->insert(
				new Variant( id: 0, product_id: $other, is_base: false, combination: Combination::from_array( [ 'l:size' => 's' ] ), sku: strtolower( $this->sku( 'RAW' ) ) )
			);
			self::fail( 'Expected DuplicateSku' );
		} catch ( DuplicateSku $e ) {
			self::assertSame( $holder, $e->product_id );
		}
	}

	public function test_repository_maps_duplicate_combination_to_invalid_product(): void {
		$id = $this->product();

		$this->expectException( InvalidProduct::class );
		// The product already has a base row with the empty combination.
		Plugin::instance()->catalog()->variants()->insert( Variant::base_for( $id ) );
	}

	public function test_duplicate_sku_returns_409_over_rest(): void {
		$this->save( $this->product(), [ 'base' => [ 'sku' => $this->sku( 'REST' ) ] ] );
		$id = $this->product();

		$request = new \WP_REST_Request( 'POST', '/flexacommerce/v1/admin/products/' . $id );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'base' => [ 'sku' => $this->sku( 'REST' ) ] ] ) );
		$response = rest_do_request( $request );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'flexacommerce_duplicate_sku', $response->as_error()->get_error_code() );
	}
}
