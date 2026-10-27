<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Checkout;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Checkout\Simulator;
use Flexa\Commerce\Contracts\Orders\CustomerHistory;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;
use Flexa\Commerce\Pricing\Rules\PriceRules;
use Flexa\Commerce\Tests\Integration\Coupons\CouponTestCase;

/** The rule simulator (ADR-046): a made-up cart priced as checkout would, never stored. */
final class SimulatorTest extends CouponTestCase {
	private MemoryTokenStore $tokens;

	public function set_up(): void {
		parent::set_up();
		$this->tokens = new MemoryTokenStore();
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		update_option( PriceRuleRepository::OPTION, [], true );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		Plugin::instance()->cart()->pricing()->reset();
	}

	public function test_each_price_rule_says_what_it_did_to_the_line(): void {
		$shirt = $this->simple( 2000 );
		$other = $this->simple( 500 );
		$rules = [
			'all'      => [],
			'shirts'   => [
				'amount'      => 2500,
				'applies_to'  => PriceRule::PRODUCTS,
				'product_ids' => [ $shirt ],
			],
			'off'      => [ 'enabled' => false ],
			'later'    => [ 'starts_at' => '2099-01-01 00:00:00' ],
			'members'  => [
				'customers' => PriceRule::ROLES,
				'roles'     => [ 'editor' ],
			],
			'elsewise' => [
				'applies_to'  => PriceRule::PRODUCTS,
				'product_ids' => [ $other ],
			],
			'big cart' => [
				'conditions' => [
					'items' => [
						[
							'type'  => 'cart_quantity',
							'op'    => 'gte',
							'value' => 10,
						],
					],
				],
			],
			'five up'  => [
				'type'  => PriceRule::BULK,
				'tiers' => [
					[
						'min'    => 5,
						'mode'   => PriceRule::PERCENT,
						'amount' => 5000,
					],
				],
			],
		];
		foreach ( $rules as $name => $input ) {
			$this->rules()->save( $input + [ 'name' => $name ] );
		}

		$result = $this->simulator()->run(
			[
				'lines' => [
					[
						'product_id' => $shirt,
						'quantity'   => 2,
					],
				],
			]
		);
		$line   = $result['lines'][0];
		self::assertSame( [ 2, 2000, 1500, 3000 ], [ $line['quantity'], $line['before']['amount'], $line['unit']['amount'], $line['total']['amount'] ] );
		$status = array_column( $line['rules'], 'status', 'name' );
		self::assertSame(
			[
				'all'      => PriceRules::BEATEN,
				'shirts'   => PriceRules::APPLIED,
				'off'      => PriceRules::OFF,
				'later'    => PriceRules::SCHEDULED,
				'members'  => PriceRules::CUSTOMER,
				'elsewise' => PriceRules::NOT_TARGETED,
				'big cart' => PriceRules::UNMET,
				'five up'  => PriceRules::NO_TIER,
			],
			$status
		);
		$unmet = $line['rules'][6];
		self::assertSame( [ 'cart_quantity', 'gte', 10 ], [ $unmet['condition']['type'], $unmet['condition']['op'], $unmet['condition']['value'] ] );
		self::assertSame( 3600, $line['rules'][0]['amount']['amount'], 'what the beaten rule would have charged' );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$result = $this->simulator()->run(
			[
				'lines'       => [ [ 'product_id' => $shirt ] ],
				'customer_id' => $editor,
			]
		);
		self::assertSame( $editor, $result['customer_id'] );
		self::assertSame( PriceRules::BEATEN, array_column( $result['lines'][0]['rules'], 'status', 'name' )['members'], 'the role is met; the shirt rule still takes more off' );
	}

	public function test_fees_coupons_and_totals_follow_checkout(): void {
		$shirt = $this->simple( 1000 );
		$this->rules()->save(
			[
				'name'       => 'Small order fee',
				'type'       => PriceRule::FEE,
				'mode'       => PriceRule::FIXED,
				'amount'     => 300,
				'conditions' => [
					'items' => [
						[
							'type'  => 'cart_subtotal',
							'op'    => 'lte',
							'value' => 5000,
						],
					],
				],
			]
		);
		$this->rules()->save(
			[
				'name'       => 'Bank fee',
				'type'       => PriceRule::FEE,
				'mode'       => PriceRule::FIXED,
				'amount'     => 100,
				'conditions' => [
					'items' => [
						[
							'type'  => 'payment_method',
							'op'    => 'in',
							'value' => [ 'bacs' ],
						],
					],
				],
			]
		);
		$this->coupon( 'save1', Coupon::FIXED_CART, 100 );
		$this->coupon( 'Welcome', Coupon::PERCENT, 1000, [ 'automatic' => true ] );
		$this->coupon(
			'Big spender',
			Coupon::PERCENT,
			2000,
			[
				'automatic'    => true,
				'min_subtotal' => 10000,
			]
		);

		$result = $this->simulator()->run(
			[
				'lines'   => [
					[ 'product_id' => $shirt ],
					[ 'product_id' => $shirt ],
					[ 'product_id' => 999999 ],
				],
				'country' => 'US',
				'state'   => 'CA',
				'coupons' => [ 'SAVE1', 'nope' ],
			]
		);
		self::assertCount( 1, $result['lines'], 'the same product twice is one line' );
		self::assertSame( 2, $result['lines'][0]['quantity'] );
		self::assertCount( 1, $result['problems'] );
		self::assertStringStartsWith( 'Line 3: ', $result['problems'][0] );

		self::assertSame(
			[
				'Small order fee' => PriceRules::APPLIED,
				'Bank fee'        => PriceRules::UNMET,
			],
			array_column( $result['fees'], 'status', 'name' )
		);
		self::assertSame( 'payment_method', $result['fees'][1]['condition']['type'] );

		$coupons = [];
		foreach ( $result['coupons'] as $row ) {
			$coupons[ $row['code'] ] = [ $row['status'], $row['automatic'] ];
		}
		self::assertSame(
			[
				'save1'       => [ 'applied', false ],
				'nope'        => [ 'rejected', false ],
				'Welcome'     => [ 'applied', true ],
				'Big spender' => [ 'skipped', true ],
			],
			$coupons
		);
		self::assertNotSame( '', $result['coupons'][1]['message'] );
		self::assertNotSame( '', $result['coupons'][3]['message'] );
		self::assertSame( 'Big spender', $result['next_discount']['label'] );

		self::assertSame( $this->rate_id, $result['shipping_rate'], 'the rate checkout would pick' );
		self::assertSame( 'cod', $result['payment_method'] );
		self::assertSame( 2000, $result['totals']['subtotal']['amount'] );
		self::assertSame( 290, $result['totals']['discount']['amount'], '1.00, then 10 % of the 19.00 left' );
		self::assertSame( 300, $result['totals']['fees']['amount'] );
		self::assertSame( 500, $result['totals']['shipping']['amount'] );
		self::assertSame( $result['totals']['total']['amount'], 2000 - 290 + 300 + 500 + $result['totals']['tax']['amount'] );
	}

	public function test_nothing_is_stored(): void {
		global $wpdb;
		$shirt  = $this->simple( 1000 );
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'sessions' ) ); // phpcs:ignore WordPress.DB
		$this->simulator()->run(
			[
				'lines'   => [ [ 'product_id' => $shirt ] ],
				'country' => 'US',
			]
		);
		self::assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'sessions' ) ) ); // phpcs:ignore WordPress.DB
		self::assertSame( [], $this->shopper( $this->tokens )->cart()->state( 0 )->items );
	}

	public function test_the_route_needs_the_store_manager(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$shirt   = $this->simple( 1000 );
		$request = new \WP_REST_Request( 'POST', '/flexacommerce/v1/admin/simulator' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'lines' => [
						[
							'type'     => 'product',
							'id'       => $shirt,
							'quantity' => 3,
						],
					],
				]
			)
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, rest_do_request( $request )->get_status() );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$response = rest_do_request( $request );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 3000, $response->get_data()['lines'][0]['total']['amount'] );

		$editor = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Edna Test',
				'user_email'   => 'edna@example.com',
			]
		);
		$search = new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/simulator/customers' );
		$search->set_param( 'search', 'edna' );
		$found = rest_do_request( $search )->get_data();
		self::assertSame( [ $editor ], array_column( $found, 'id' ), 'an account that never ordered is found' );
		self::assertSame( [ 'Editor' ], $found[0]['roles'] );
		if ( is_multisite() ) {
			revoke_super_admin( $admin );
		}
	}

	private function simulator(): Simulator {
		$browser = $this->shopper( $this->tokens );
		return new Simulator( $browser->cart(), $this->checkout( $browser ), $browser->presenter(), fn (): CouponService => $this->coupons()->service() );
	}

	private function rules(): PriceRuleRepository {
		return Plugin::instance()->price_rule_repository();
	}

	protected function shopper( ?MemoryTokenStore $tokens = null ): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			$tokens ?? new MemoryTokenStore(),
			coupons: fn (): CouponService => $this->coupons()->service(),
			rules: fn (): PriceRules => $plugin->price_rules(),
			history: fn (): CustomerHistory => $plugin->customer_history(),
		);
	}
}
