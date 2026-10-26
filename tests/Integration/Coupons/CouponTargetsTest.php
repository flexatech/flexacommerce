<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\RuleTargets;
use Flexa\Commerce\Plugin;

/** Product and variation rules kept apart, and the picker rows of the Coupons screen. */
final class CouponTargetsTest extends CouponTestCase {
	private \WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
	}

	public function test_a_variation_rule_covers_that_variation_only(): void {
		[ $shirt, $sizes ] = $this->sized();
		$this->coupon( 'MEDIUM', Coupon::PERCENT, 5000, [ 'variant_ids' => [ $sizes['m'] ] ] );
		$this->coupon( 'NOTMEDIUM', Coupon::PERCENT, 5000, [ 'exclude_variant_ids' => [ $sizes['m'] ] ] );
		$this->coupon( 'SHIRT', Coupon::PERCENT, 5000, [ 'product_ids' => [ $shirt ] ] );

		self::assertSame( 1000, $this->discount( $shirt, $sizes, 'medium' ) );
		self::assertSame( 1000, $this->discount( $shirt, $sizes, 'notmedium' ) );
		self::assertSame( 2000, $this->discount( $shirt, $sizes, 'shirt' ), 'a product rule covers every variation' );
	}

	public function test_a_product_id_does_not_match_a_variant_with_the_same_number(): void {
		[ $shirt, $sizes ] = $this->sized();
		self::assertNotSame( $shirt, $sizes['m'] );
		$this->coupon( 'SAME', Coupon::PERCENT, 5000, [ 'product_ids' => [ $sizes['m'] ] ] );

		$browser = $this->shopper();
		$browser->cart()->add( 0, $shirt, $sizes['m'], 1 );
		try {
			$browser->cart()->apply_coupon( 0, 'same' );
			self::fail( 'the variant id is not read as a product id' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'coupon_invalid', $e->reason );
		}
	}

	public function test_the_editor_saves_variant_lists(): void {
		$coupon = $this->coupon(
			'LISTS',
			Coupon::PERCENT,
			1000,
			[
				'variant_ids'         => [ 7, '8', 7 ],
				'exclude_variant_ids' => [ 9 ],
			]
		);
		self::assertSame( [ 7, 8 ], $coupon->variant_ids );
		self::assertSame( [ 9 ], $coupon->exclude_variant_ids );
		self::assertSame( [ 7, 8 ], $coupon->to_array()['variant_ids'] );

		$this->expectException( \Flexa\Commerce\Contracts\Exceptions\InvalidCoupon::class );
		$this->coupons()->editor()->save( [ 'variant_ids' => [ 0 ] ], $coupon->id );
	}

	public function test_search_finds_products_by_title_and_sku_with_their_variations(): void {
		$plain = $this->product( 'Quokka mug' );
		$this->save(
			$plain,
			[
				'base' => [
					'regular_price' => 1000,
					'sku'           => 'QM-1',
				],
			]
		);
		[ $shirt, $sizes ] = $this->sized();
		wp_update_post(
			[
				'ID'         => $shirt,
				'post_title' => 'Quokka shirt',
			]
		);
		$rows = [];
		foreach ( $sizes as $size => $variant ) {
			$rows[] = [
				'id'  => $variant,
				'sku' => $size === 'l' ? 'ZEB-L' : null,
			];
		}
		$this->save( $shirt, [ 'variations' => $rows ] );
		$targets = $this->targets();

		$rows = $targets->search_products( 'quokka' );
		$keys = array_map( static fn ( array $r ): string => $r['type'] . ':' . $r['id'], $rows );
		self::assertContains( 'product:' . $plain, $keys );
		self::assertContains( 'product:' . $shirt, $keys );
		foreach ( $sizes as $variant ) {
			self::assertContains( 'variant:' . $variant, $keys, 'a product found by its title lists every variation' );
		}
		$mug = $rows[ array_search( 'product:' . $plain, $keys, true ) ];
		self::assertSame( 'Quokka mug', $mug['label'] );
		self::assertSame( 'QM-1', $mug['sku'] );
		$large = $rows[ array_search( 'variant:' . $sizes['l'], $keys, true ) ];
		self::assertSame( 'Quokka shirt – L', $large['label'] );
		self::assertSame( 'ZEB-L', $large['sku'] );

		$rows = $targets->search_products( 'zeb-' );
		self::assertSame(
			[ 'product:' . $shirt, 'variant:' . $sizes['l'] ],
			array_map( static fn ( array $r ): string => $r['type'] . ':' . $r['id'], $rows ),
			'a product found by the SKU of a variation lists that variation only'
		);

		self::assertSame( [ 'product:' . $plain ], array_map( static fn ( array $r ): string => $r['type'] . ':' . $r['id'], $targets->search_products( 'qm-1' ) ) );
	}

	public function test_lookup_names_the_ids_and_leaves_out_missing_ones(): void {
		$sizes  = $this->sized()[1];
		$mug    = $this->product( 'Mug' );
		$parent = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Clothing',
			]
		);
		$child  = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => 'Shirts & tees',
				'parent'   => $parent,
			]
		);

		$rows = $this->targets()->lookup( [ $mug, 999999 ], [ $sizes['s'], 999999 ], [ $child, 999999 ] );
		self::assertSame(
			[
				[ RuleTargets::PRODUCT, $mug, 'Mug' ],
				[ RuleTargets::VARIANT, $sizes['s'], 'Test product – S' ],
				[ RuleTargets::CATEGORY, $child, 'Clothing › Shirts & tees' ],
			],
			array_map( static fn ( array $r ): array => [ $r['type'], $r['id'], $r['label'] ], $rows )
		);

		$found = $this->targets()->search_categories( 'shirts' );
		self::assertSame( [ $child ], array_column( $found, 'id' ) );
	}

	public function test_the_targets_route(): void {
		$mug = $this->product( 'Wombat mug' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->get( [ 'search' => 'wombat' ] )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = $this->get( [ 'search' => 'wombat' ] );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ $mug ], array_column( $response->get_data(), 'id' ) );

		$response = $this->get( [ 'product_ids' => (string) $mug ] );
		self::assertSame( 'Wombat mug', $response->get_data()[0]['label'] );

		self::assertSame( 400, $this->get( [ 'kind' => 'tag' ] )->get_status() );
		self::assertSame(
			[],
			$this->get(
				[
					'kind'   => 'category',
					'search' => 'no such category',
				]
			)->get_data()
		);
	}

	/** @param array<string,int> $sizes */
	private function discount( int $shirt, array $sizes, string $code ): int {
		$browser = $this->shopper();
		foreach ( [ 's', 'm' ] as $size ) {
			$browser->cart()->add( 0, $shirt, $sizes[ $size ], 1 );
		}
		return $browser->cart()->apply_coupon( 0, $code )->discount()->amount;
	}

	private function targets(): RuleTargets {
		return new RuleTargets( Plugin::instance()->catalog()->products(), Plugin::instance()->catalog()->variants() );
	}

	/** @param array<string,string> $query */
	private function get( array $query ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/coupons/targets' );
		$request->set_query_params( $query );
		return $this->server->dispatch( $request );
	}
}
