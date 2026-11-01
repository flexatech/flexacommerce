<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\Shipping\PackageLine;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\Settings;

/**
 * Shipping classes from the product to the shipping package and the rates
 * (ADR-068).
 */
final class ShippingClassesTest extends CartTestCase {
	public function set_up(): void {
		parent::set_up();
		Settings::update(
			[
				'shipping_classes' => [
					[
						'slug' => 'bulky',
						'name' => 'Bulky',
					],
					[
						'slug' => 'small',
						'name' => 'Small',
					],
				],
			]
		);
	}

	public function tear_down(): void {
		parent::tear_down();
		delete_option( ZoneRepository::OPTION );
	}

	public function test_lines_carry_the_class_and_a_variation_inherits_it(): void {
		$sofa = $this->simple( 50000 );
		$this->save( $sofa, [ 'base' => [ 'shipping_class' => 'bulky' ] ] );
		[ $shirt, $sizes ] = $this->sized();
		$rows              = [];
		foreach ( $sizes as $size => $variant_id ) {
			$rows[] = [
				'id'             => $variant_id,
				'regular_price'  => 2000,
				'shipping_class' => $size === 'l' ? 'bulky' : '',
			];
		}
		$this->save(
			$shirt,
			[
				'base'       => [ 'shipping_class' => 'small' ],
				'variations' => $rows,
			]
		);

		$cart = $this->browser()->cart();
		$cart->add( 0, $sofa, 0, 1 );
		$cart->add( 0, $shirt, 0, 1, [ 'l:size' => 's' ] );
		$state   = $cart->add( 0, $shirt, 0, 2, [ 'l:size' => 'l' ] );
		$package = $cart->package( $state, new Destination( 'US' ) );
		self::assertSame( [ 'bulky', 'small', 'bulky' ], array_map( static fn ( PackageLine $l ): string => $l->shipping_class, $package->lines ) );

		$before = $package->hash();
		Settings::update(
			[
				'shipping_classes' => [
					[
						'slug' => 'small',
						'name' => 'Small',
					],
				],
			]
		);
		$package = $cart->package( $cart->state( 0 ), new Destination( 'US' ) );
		self::assertSame( [ '', 'small', '' ], array_map( static fn ( PackageLine $l ): string => $l->shipping_class, $package->lines ), 'A removed class counts as no class.' );
		self::assertNotSame( $before, $package->hash(), 'The class is part of the cached package.' );
	}

	public function test_flat_rate_and_table_rate_price_the_cart(): void {
		$sofa = $this->simple( 50000 );
		$this->save(
			$sofa,
			[
				'base' => [
					'shipping_class' => 'bulky',
					'weight'         => 12500,
				],
			]
		);
		$mug = $this->simple( 1000 );
		$this->save( $mug, [ 'base' => [ 'weight' => 400 ] ] );

		Plugin::instance()->shipping()->service()->zones()->save(
			[
				new Zone(
					0,
					'US',
					[ 'country:US' ],
					[],
					[
						new MethodInstance(
							0,
							'flat_rate',
							true,
							[
								'cost'        => 500,
								'class_costs' => [
									'calculation' => 'per_class',
									'costs'       => [
										'bulky' => [
											'cost'     => 2500,
											'per_item' => 0,
										],
									],
								],
							]
						),
						new MethodInstance(
							0,
							'table_rate',
							true,
							[
								'weight_unit' => 'kg',
								'rows'        => [
									[
										'condition' => 'weight',
										'max'       => 5,
										'cost'      => 700,
									],
									[
										'condition'   => 'weight',
										'min'         => 5,
										'max'         => 30,
										'cost'        => 1500,
										'weight_cost' => 100,
									],
								],
							]
						),
					]
				),
			]
		);

		$cart = $this->browser()->cart();
		$cart->add( 0, $mug, 0, 2 );
		$state = $cart->add( 0, $sofa, 0, 1 );
		$rates = Plugin::instance()->shipping()->service()->rates( $cart->package( $state, new Destination( 'US', 'NY' ) ) );
		$costs = [];
		foreach ( $rates as $rate ) {
			$costs[ explode( ':', $rate->id )[0] ] = $rate->cost->amount;
		}
		// 13.3 kg: 1500 + 13.3 * 100.
		self::assertSame(
			[
				'flat_rate'  => 3000,
				'table_rate' => 2830,
			],
			$costs
		);
	}

	public function test_the_admin_endpoint_lists_classes_with_product_counts(): void {
		// Catalog rows of earlier tests may outlive their rollback.
		$before = Plugin::instance()->catalog()->variants()->shipping_class_counts();
		$sofa   = $this->simple( 50000 );
		$chair  = $this->simple( 9000 );
		$this->save( $sofa, [ 'base' => [ 'shipping_class' => 'bulky' ] ] );
		$this->save( $chair, [ 'base' => [ 'shipping_class' => 'bulky' ] ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );

		$data = $wp_rest_server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/shipping/zones' ) )->get_data();
		self::assertSame(
			[
				[
					'slug'     => 'bulky',
					'name'     => 'Bulky',
					'products' => ( $before['bulky'] ?? 0 ) + 2,
				],
				[
					'slug'     => 'small',
					'name'     => 'Small',
					'products' => $before['small'] ?? 0,
				],
			],
			$data['classes']
		);
		self::assertContains( 'table_rate', array_column( $data['methods'], 'type' ) );
		$flat   = array_values( array_filter( $data['methods'], static fn ( array $m ): bool => $m['type'] === 'flat_rate' ) )[0];
		$fields = array_column( $flat['fields'], 'type', 'id' );
		self::assertSame( 'class_costs', $fields['class_costs'] );
	}
}
