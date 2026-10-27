<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\Exceptions\InvalidPriceRule;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;
use Flexa\Commerce\Pricing\Rules\PriceRules;
use Flexa\Commerce\Support\Settings;

/** Pricing rules (ADR-024): what the list keeps, the prices they give, and where they show. */
final class PriceRulesTest extends CartTestCase {
	private const NOW = '2026-06-01 12:00:00';

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		// After the rollback: a cart write commits, which can keep the rules.
		update_option( PriceRuleRepository::OPTION, [], true );
		Settings::update(
			[
				'tier_table'       => true,
				'tier_table_title' => '',
			]
		);
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		Plugin::instance()->cart()->pricing()->reset();
	}

	public function test_the_list_keeps_rules_in_order(): void {
		$sale = $this->rules()->save( [ 'name' => ' Summer sale ' ] );
		$bulk = $this->rules()->save(
			[
				'name'  => 'Bulk',
				'type'  => PriceRule::BULK,
				'mode'  => PriceRule::FIXED,
				'tiers' => [
					[
						'min'    => '10',
						'mode'   => PriceRule::PERCENT,
						'amount' => 2000,
					],
					[
						'min'    => 5,
						'mode'   => PriceRule::FIXED,
						'amount' => 100,
					],
				],
			]
		);
		self::assertSame( [ 1, 2 ], [ $sale->id, $bulk->id ] );
		self::assertSame( 'Summer sale', $sale->name );
		self::assertSame( [ 5, 10 ], array_column( $bulk->tiers, 'min' ), 'tiers are sorted' );
		self::assertSame( [ PriceRule::PERCENT, 0 ], [ $bulk->mode, $bulk->amount ], 'a bulk rule keeps only its tiers' );

		$changed = $this->rules()->save( [ 'enabled' => false ], $sale->id );
		self::assertFalse( $changed->enabled );
		self::assertSame( 1000, $changed->amount, 'fields not sent stay' );

		$this->rules()->reorder( [ $bulk->id ] );
		self::assertSame( [ 2, 1 ], array_map( static fn ( PriceRule $r ): int => $r->id, $this->rules()->all() ) );
		$this->rules()->delete( $bulk->id );
		self::assertSame( [ 1 ], array_map( static fn ( PriceRule $r ): int => $r->id, $this->rules()->all() ) );
	}

	public function test_the_editor_refuses_a_rule_it_cannot_apply(): void {
		$tier = static fn ( mixed $min, string $mode = PriceRule::PERCENT, mixed $amount = 1000 ): array => [
			'min'    => $min,
			'mode'   => $mode,
			'amount' => $amount,
		];
		foreach (
			[
				'name'         => [ 'name' => '' ],
				'type'         => [ 'type' => 'cart' ],
				'amount'       => [ 'amount' => 10001 ],
				'tiers'        => [
					'type'  => PriceRule::BULK,
					'tiers' => [ $tier( 5 ), $tier( '5' ) ],
				],
				'product_ids'  => [ 'applies_to' => PriceRule::PRODUCTS ],
				'category_ids' => [
					'applies_to'   => PriceRule::CATEGORIES,
					'category_ids' => [ 0 ],
				],
				'roles'        => [
					'customers' => PriceRule::ROLES,
					'roles'     => [ 'pirate' ],
				],
				'ends_at'      => [
					'starts_at' => '2026-06-02 00:00',
					'ends_at'   => '2026-06-01 00:00',
				],
			] as $field => $input
		) {
			try {
				$this->rules()->save( $input + [ 'name' => 'x' ] );
				self::fail( $field . ' is refused' );
			} catch ( InvalidPriceRule $e ) {
				self::assertSame( $field, $e->field );
			}
		}
		foreach ( [ [ $tier( 0 ) ], [ $tier( 2, PriceRule::FIXED, 0 ) ], [] ] as $tiers ) {
			try {
				$this->rules()->save(
					[
						'name'  => 'x',
						'type'  => PriceRule::BULK,
						'tiers' => $tiers,
					]
				);
				self::fail( 'bad tiers are refused' );
			} catch ( InvalidPriceRule $e ) {
				self::assertSame( 'tiers', $e->field );
			}
		}
		self::assertSame( [], $this->rules()->all() );
	}

	public function test_the_lowest_price_wins_and_none_is_raised(): void {
		$shirt  = $this->simple( 2000 );
		$mug    = $this->simple( 1000 );
		$parent = $this->category( 'Clothing' );
		$child  = $this->category( 'Shirts', $parent );
		wp_set_object_terms( $shirt, [ $child ], PostTypes::CATEGORY );

		$this->rules()->save(
			[
				'name'         => 'Clothes 10%',
				'applies_to'   => PriceRule::CATEGORIES,
				'category_ids' => [ $parent ],
			]
		);
		self::assertSame( 1800, $this->price( $shirt ), 'a parent category counts' );
		self::assertSame( 1000, $this->price( $mug ) );

		$this->rules()->save(
			[
				'name'        => 'Shirt 3 off',
				'mode'        => PriceRule::FIXED,
				'amount'      => 300,
				'applies_to'  => PriceRule::PRODUCTS,
				'product_ids' => [ $shirt ],
			]
		);
		$this->rules()->save(
			[
				'name'                => 'Everything at 25',
				'mode'                => PriceRule::PRICE,
				'amount'              => 2500,
				'exclude_product_ids' => [ $mug ],
			]
		);
		self::assertSame( 1700, $this->price( $shirt ), 'the rules do not stack; the lowest wins' );
		self::assertSame( 1000, $this->price( $mug ), 'a fixed price above the price changes nothing' );

		$this->rules()->save(
			[
				'name'      => 'Later',
				'amount'    => PriceRule::FULL_PERCENT,
				'starts_at' => '2026-06-02 00:00',
			]
		);
		self::assertSame( 1000, $this->price( $mug ) );
		self::assertSame( 0, $this->price( $mug, now: '2026-06-03 00:00:00' ) );
	}

	public function test_sale_items_and_customers(): void {
		$id = $this->product();
		$this->save(
			$id,
			[
				'base' => [
					'regular_price' => 2000,
					'sale_price'    => 1500,
				],
			]
		);
		$rule = $this->rules()->save(
			[
				'name'      => 'Members',
				'customers' => PriceRule::LOGGED_IN,
			]
		);
		self::assertSame( 1500, $this->price( $id ), 'a guest gets the sale price' );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		self::assertSame( 1350, $this->price( $id, user_id: $member ), 'the rule applies on the sale price' );

		$this->rules()->save( [ 'sale_items' => PriceRule::SALE_SKIP ], $rule->id );
		self::assertSame( 1500, $this->price( $id, user_id: $member ) );

		$this->rules()->save(
			[
				'sale_items' => PriceRule::SALE_APPLY,
				'customers'  => PriceRule::ROLES,
				'roles'      => [ 'editor' ],
			],
			$rule->id
		);
		self::assertSame( 1500, $this->price( $id, user_id: $member ) );
		self::assertSame( 1350, $this->price( $id, user_id: self::factory()->user->create( [ 'role' => 'editor' ] ) ) );
	}

	public function test_bulk_tiers_count_the_cart(): void {
		[ $id, $sizes ]             = $this->sized( 50 );
		[ $small, $medium, $large ] = array_values( $sizes );
		$rule                       = $this->rules()->save(
			[
				'name'  => 'Bulk',
				'type'  => PriceRule::BULK,
				'tiers' => [
					[
						'min'    => 3,
						'mode'   => PriceRule::PERCENT,
						'amount' => 1000,
					],
					[
						'min'    => 6,
						'mode'   => PriceRule::PRICE,
						'amount' => 1500,
					],
				],
			]
		);

		$browser = $this->shopper();
		$browser->cart()->add( 0, $id, $small, 2 );
		self::assertSame( [ 2000 ], $this->units( $browser ) );
		$browser->cart()->add( 0, $id, $medium, 1 );
		self::assertSame( [ 1800, 1800 ], $this->units( $browser ), 'the variations of a product count together' );
		$browser->cart()->add( 0, $id, $large, 3 );
		self::assertSame( [ 1500, 1500, 1500 ], $this->units( $browser ) );
		self::assertSame( 9000, $browser->cart()->state( 0 )->subtotal->amount );

		$this->rules()->save( [ 'count' => PriceRule::COUNT_VARIANT ], $rule->id );
		self::assertSame( [ 2000, 2000, 1800 ], $this->units( $browser ), 'each variation alone' );
	}

	public function test_the_product_page_lists_the_tiers(): void {
		$id    = $this->simple( 1000 );
		$tiers = [
			[
				'min'    => 5,
				'mode'   => PriceRule::PERCENT,
				'amount' => 1250,
			],
			[
				'min'    => 10,
				'mode'   => PriceRule::FIXED,
				'amount' => 300,
			],
		];
		self::assertStringNotContainsString( 'fxc-tiers', $this->form( $id ) );
		$this->rules()->save(
			[
				'name'  => 'Bulk',
				'type'  => PriceRule::BULK,
				'tiers' => $tiers,
			]
		);
		$html = $this->form( $id );
		self::assertStringContainsString( '<table class="fxc-tiers"><caption>Buy more, pay less</caption><thead><tr><th scope="col">Quantity</th><th scope="col">Price</th><th scope="col">You save</th></tr></thead>', $html );
		self::assertStringContainsString(
			'<tr data-min="1" data-max="4" class="is-current"><th scope="row">1 to 4</th><td>$10.00 each</td><td></td></tr>'
			. '<tr data-min="5" data-max="9"><th scope="row">5 to 9</th><td>$8.75 each</td><td>Save 13%</td></tr>'
			. '<tr data-min="10" data-max="0"><th scope="row">10 or more</th><td>$7.00 each</td><td>Save 30%</td></tr>',
			$html
		);

		[ $shirt ] = $this->sized();
		self::assertStringContainsString(
			'<th scope="col">Discount</th></tr></thead><tbody><tr data-min="1" data-max="4" class="is-current"><th scope="row">1 to 4</th><td>Full price</td></tr>'
			. '<tr data-min="5" data-max="9"><th scope="row">5 to 9</th><td>12.5% off</td></tr><tr data-min="10" data-max="0"><th scope="row">10 or more</th><td>$3.00 off each</td></tr>',
			$this->form( $shirt ),
			'variations list the change'
		);

		Settings::update( [ 'tier_table_title' => 'Bulk savings' ] );
		self::assertStringContainsString( '<caption>Bulk savings</caption>', $this->form( $id ) );
		Settings::update( [ 'tier_table' => false ] );
		self::assertStringNotContainsString( 'fxc-tiers', $this->form( $id ), 'the merchant can hide the table' );
	}

	public function test_a_tier_from_one_starts_the_table(): void {
		$id = $this->simple( 1000 );
		$this->rules()->save(
			[
				'name'  => 'Bulk',
				'type'  => PriceRule::BULK,
				'tiers' => [
					[
						'min'    => 1,
						'mode'   => PriceRule::PERCENT,
						'amount' => 500,
					],
					[
						'min'    => 2,
						'mode'   => PriceRule::PERCENT,
						'amount' => 1000,
					],
				],
			]
		);
		$html = $this->form( $id );
		self::assertStringContainsString( '<tr data-min="1" data-max="1" class="is-current"><th scope="row">1</th><td>$9.50 each</td><td></td></tr><tr data-min="2" data-max="0"><th scope="row">2 or more</th><td>$9.00 each</td><td>Save 5%</td></tr>', $html, 'no row below the first tier, and the saving is against the price of one' );
	}

	public function test_the_routes(): void {
		$server = $this->server();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::assertSame( 403, $server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/pricing-rules' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = $this->send( $server, 'GET', '' );
		self::assertSame( [], $response->get_data()['items'] );
		self::assertContains( 'editor', array_column( $response->get_data()['roles'], 'value' ) );

		$response = $this->send(
			$server,
			'POST',
			'',
			[
				'name'    => 'A',
				'ends_at' => '2026-07-01T10:00:00Z',
			]
		);
		self::assertSame( 201, $response->get_status() );
		self::assertSame( '2026-07-01T10:00:00Z', $response->get_data()['items'][0]['ends_at'] );
		$this->send( $server, 'POST', '', [ 'name' => 'B' ] );

		$response = $this->send( $server, 'PATCH', '/1', [ 'enabled' => false ] );
		self::assertFalse( $response->get_data()['items'][0]['enabled'] );
		self::assertSame( [ 'B', 'A' ], array_column( $this->send( $server, 'POST', '/reorder', [ 'ids' => [ 2 ] ] )->get_data()['items'], 'name' ) );

		$bad = $this->send( $server, 'PATCH', '/1', [ 'mode' => 'free' ] );
		self::assertSame( 400, $bad->get_status() );
		self::assertSame( 'mode', $bad->get_data()['data']['field'] );
		self::assertSame( 404, $this->send( $server, 'DELETE', '/9' )->get_status() );
		self::assertSame( [ 'B' ], array_column( $this->send( $server, 'DELETE', '/1' )->get_data()['items'], 'name' ) );
	}

	private function rules(): PriceRuleRepository {
		return Plugin::instance()->price_rule_repository();
	}

	private function category( string $name, int $parent = 0 ): int {
		return self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'name'     => $name,
				'parent'   => $parent,
			]
		);
	}

	private function price( int $id, int $user_id = 0, string $now = self::NOW ): int {
		$product = Plugin::instance()->catalog()->products()->find( $id );
		self::assertNotNull( $product );
		$row    = $product->base;
		$active = (int) $row->active_price( $now );
		return ( new PriceRules( $this->rules() ) )->apply( $product, $row, $active, 1, 1, $user_id, $now );
	}

	private function shopper(): CartServices {
		$plugin = Plugin::instance();
		return new CartServices( $plugin->services(), $plugin->catalog(), new MemoryTokenStore(), rules: fn (): PriceRules => $plugin->price_rules() );
	}

	/** @return list<int> the unit price of each cart line */
	private function units( CartServices $browser ): array {
		$browser->pricing()->reset();
		return array_map( static fn ( $item ): int => (int) $item->unit?->active->amount, $browser->cart()->state( 0 )->items );
	}

	private function form( int $id ): string {
		Plugin::instance()->cart()->pricing()->reset();
		return do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
	}

	private function server(): \WP_REST_Server {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		return $wp_rest_server;
	}

	/** @param array<string,mixed> $body */
	private function send( \WP_REST_Server $server, string $method, string $path, array $body = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1/admin/pricing-rules' . $path );
		$request->set_body_params( $body );
		return $server->dispatch( $request );
	}
}
