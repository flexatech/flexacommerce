<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Cart\SessionNonce;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidOptionSet;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSet;
use Flexa\Commerce\Options\OptionSetRepository;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRules;

/** Product options (ADR-025): what the list keeps, what the cart takes and charges, and the routes. */
final class ProductOptionsTest extends CartTestCase {
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		// After the rollback: a cart write commits, which can keep the lists.
		update_option( ProductOptions::OPTION, [], true );
		update_option( PricingService::RULES_OPTION, [], true );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
	}

	public function test_the_list_keeps_sets_and_field_ids(): void {
		$set = $this->sets()->save( $this->gift() );
		self::assertSame( 1, $set->id );
		self::assertSame( [ 1, 2 ], array_map( static fn ( OptionField $f ): int => $f->id, $set->fields ) );
		self::assertSame( [ 1, 2 ], array_column( $set->fields[0]->choices, 'id' ), 'choices without an id get the next one' );
		self::assertSame( '1', $set->fields[1]->logic['rules'][0]['value'] ?? null, 'a choice in a rule is kept as text' );

		$changed = $this->sets()->save( [ 'enabled' => false ], $set->id );
		self::assertFalse( $changed->enabled );
		self::assertCount( 2, $changed->fields, 'fields not sent stay' );

		$other = $this->sets()->save( [ 'name' => 'B' ] + $this->engraving() );
		$this->sets()->reorder( [ $other->id ] );
		self::assertSame( [ 2, 1 ], array_map( static fn ( OptionSet $s ): int => $s->id, $this->sets()->all() ) );
		$this->sets()->delete( $other->id );
		self::assertSame( [ 1 ], array_map( static fn ( OptionSet $s ): int => $s->id, $this->sets()->all() ) );
	}

	public function test_the_editor_refuses_a_set_it_cannot_show(): void {
		$text = [
			'type'  => OptionField::TEXT,
			'label' => 'Text',
		];
		foreach (
			[
				'name'         => [ 'name' => '' ],
				'product_ids'  => [ 'applies_to' => OptionSet::PRODUCTS ],
				'category_ids' => [ 'applies_to' => OptionSet::CATEGORIES ],
			] as $field => $input
		) {
			try {
				$this->sets()->save( $input + [ 'fields' => [ $text ] ] + [ 'name' => 'x' ] );
				self::fail( $field . ' is refused' );
			} catch ( InvalidOptionSet $e ) {
				self::assertSame( $field, $e->field );
			}
		}
		foreach (
			[
				'no fields'          => [ [], -1 ],
				'only a heading'     => [
					[
						[
							'type'  => OptionField::HEADING,
							'label' => 'H',
						],
					],
					-1,
				],
				'unknown type'       => [
					[
						$text,
						[
							'type'  => 'color',
							'label' => 'C',
						],
					],
					1,
				],
				'no label'           => [ [ [ 'type' => OptionField::TEXT ] ], 0 ],
				'no choices'         => [
					[
						[
							'type'  => OptionField::SELECT,
							'label' => 'S',
						],
					],
					0,
				],
				'two defaults'       => [
					[
						[
							'type'    => OptionField::RADIO,
							'label'   => 'R',
							'choices' => [
								[
									'label'   => 'A',
									'default' => true,
								],
								[
									'label'   => 'B',
									'default' => true,
								],
							],
						],
					],
					0,
				],
				'percent over 100'   => [
					[
						$text + [
							'price_mode' => OptionField::PERCENT,
							'price'      => 10001,
						],
					],
					0,
				],
				'max below min'      => [
					[
						[
							'type'  => OptionField::NUMBER,
							'label' => 'N',
							'min'   => 5,
							'max'   => 2,
						],
					],
					0,
				],
				'rule on a later'    => [
					[
						$text + [
							'id'    => 1,
							'logic' => [
								'rules' => [
									[
										'field' => 2,
										'op'    => OptionField::NOT_EMPTY,
									],
								],
							],
						],
						$text + [ 'id' => 2 ],
					],
					0,
				],
				'is on a text field' => [
					[
						$text + [ 'id' => 1 ],
						$text + [
							'logic' => [
								'rules' => [
									[
										'field' => 1,
										'op'    => OptionField::IS,
										'value' => '1',
									],
								],
							],
						],
					],
					1,
				],
			] as $case => [ $fields, $index ]
		) {
			try {
				$this->sets()->save(
					[
						'name'   => 'x',
						'fields' => $fields,
					]
				);
				self::fail( $case . ' is refused' );
			} catch ( InvalidOptionSet $e ) {
				self::assertSame( [ 'fields', $index ], [ $e->field, $e->index ], $case );
			}
		}
		self::assertSame( [], $this->sets()->all() );
	}

	public function test_the_cart_takes_checked_values_and_charges_them(): void {
		$id = $this->simple( 2000 );
		$this->sets()->save( $this->gift() );
		$this->sets()->save( $this->engraving() );
		$browser = $this->shopper();

		$state = $browser->cart()->add( 0, $id, 0, 1 );
		self::assertSame( 2000, $state->items[0]->unit?->active->amount, 'optional fields left empty' );

		try {
			$browser->cart()->add( 0, $id, 0, 1, [], [ '1-1' => [ '9' ] ] );
			self::fail( 'an unknown choice is refused' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( [ 'options', 'invalid_option' ], [ $e->field, $e->reason ] );
		}

		$state = $browser->cart()->add(
			0,
			$id,
			0,
			2,
			[],
			[
				'1-1' => [ '2', '1' ],
				'1-2' => " Happy\nbirthday ",
				'2-1' => 'ANNA',
			]
		);
		self::assertCount( 2, $state->items, 'other values make another line' );
		$line = $state->items[1];
		self::assertSame(
			[
				'1-1' => [ '1', '2' ],
				'1-2' => "Happy\nbirthday",
				'2-1' => 'ANNA',
			],
			$line->line->options,
			'checkboxes in choice order, text trimmed'
		);
		// 20.00 + wrap 5.00 + card 10% of 20.00 + engraving 3.00.
		self::assertSame( 3000, $line->unit?->active->amount );
		self::assertSame( 6000, $line->total?->amount );
		self::assertSame(
			[
				[
					'name'  => 'Gift wrap',
					'value' => 'Wrap, Card',
				],
				[
					'name'  => 'Gift message',
					'value' => "Happy\nbirthday",
				],
				[
					'name'  => 'Engraving',
					'value' => 'ANNA',
				],
			],
			$line->line->labels
		);

		$state = $browser->cart()->add(
			0,
			$id,
			0,
			1,
			[],
			[
				'1-2' => 'Hidden',
				'2-1' => 'BOB',
			]
		);
		self::assertSame( [ '2-1' => 'BOB' ], $state->items[2]->line->options, 'a field hidden by its logic is dropped' );

		try {
			$this->sets()->save(
				[
					'fields' => [
						[
							'id'         => 1,
							'type'       => OptionField::TEXT,
							'label'      => 'Engraving',
							'required'   => true,
							'max_length' => 4,
						],
					],
				],
				2
			);
			$this->reset( $browser );
			$browser->cart()->add( 0, $id, 0, 1 );
			self::fail( 'a required field is required' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'option_required', $e->reason );
		}
		$this->expectException( InvalidCartRequest::class );
		$browser->cart()->add( 0, $id, 0, 1, [], [ '2-1' => 'TOO LONG' ] );
	}

	public function test_a_percent_is_of_the_price_after_rules(): void {
		$id = $this->simple( 2000 );
		$this->sets()->save( $this->engraving( OptionField::PERCENT, 1000 ) );
		Plugin::instance()->price_rule_repository()->save(
			[
				'name'   => 'Half off',
				'amount' => 5000,
			]
		);
		$browser = $this->shopper();

		$state = $browser->cart()->add( 0, $id, 0, 1, [], [ '1-1' => 'X' ] );
		self::assertSame( 1100, $state->items[0]->unit?->active->amount );
		self::assertSame( 2100, $state->items[0]->unit?->regular->amount, 'the regular price carries the same extra' );
	}

	public function test_sets_follow_their_products(): void {
		$shirt  = $this->simple();
		$mug    = $this->simple();
		$clothe = self::factory()->term->create( [ 'taxonomy' => PostTypes::CATEGORY ] );
		$child  = self::factory()->term->create(
			[
				'taxonomy' => PostTypes::CATEGORY,
				'parent'   => $clothe,
			]
		);
		wp_set_object_terms( $shirt, [ $child ], PostTypes::CATEGORY );
		$this->sets()->save(
			[
				'applies_to'   => OptionSet::CATEGORIES,
				'category_ids' => [ $clothe ],
			] + $this->engraving()
		);
		$form = static fn ( int $id ): string => do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );

		$html = $form( $shirt );
		self::assertStringContainsString( '<div class="fxc-option fxc-option--text" data-option="1-1">', $html, 'a parent category counts' );
		self::assertStringContainsString( 'name="options[1-1]"', $html );
		self::assertStringContainsString( '(+$3.00)', $html );
		self::assertStringNotContainsString( 'fxc-options', $form( $mug ) );

		$this->sets()->save( [ 'exclude_product_ids' => [ $shirt ] ], 1 );
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
		self::assertStringNotContainsString( 'fxc-options', $form( $shirt ) );
	}

	public function test_a_hidden_field_renders_disabled(): void {
		$id = $this->simple();
		$this->sets()->save( $this->gift() );
		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertMatchesRegularExpression( '/data-option="1-2" hidden><label[^>]*>Gift message<\/label> <textarea [^>]*disabled/', $html );
		self::assertStringContainsString( 'name="options[1-1][]" value="1"', $html );
	}

	public function test_the_routes(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$admin = static function ( string $method, string $path, array $body = [] ) use ( $wp_rest_server ): \WP_REST_Response {
			$request = new \WP_REST_Request( $method, '/flexacommerce/v1/admin/product-options' . $path );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
			return $wp_rest_server->dispatch( $request );
		};

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::assertSame( 403, $admin( 'GET', '' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertSame( [], $admin( 'GET', '' )->get_data()['items'] );

		$response = $admin( 'POST', '', $this->engraving() );
		self::assertSame( 201, $response->get_status() );
		self::assertSame( 'Engraving', $response->get_data()['items'][0]['fields'][0]['label'] );
		$bad = $admin( 'PATCH', '/1', [ 'fields' => [ [ 'type' => 'color' ] ] ] );
		self::assertSame( 400, $bad->get_status() );
		self::assertSame( [ 'fields', 0 ], [ $bad->get_data()['data']['field'], $bad->get_data()['data']['index'] ] );
		self::assertSame( 404, $admin( 'DELETE', '/9' )->get_status() );

		// The store route takes the values.
		wp_set_current_user( 0 );
		$cookie = new MemoryTokenStore();
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), $this->shopper( $cookie ) );
		$request = new \WP_REST_Request( 'POST', '/flexacommerce/v1/store/cart/items' );
		$request->set_header( 'origin', home_url() );
		$request->set_header( SessionNonce::HEADER, Plugin::instance()->cart()->nonce()->create( 0, '', $cookie->get() ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'product_id' => $this->simple( 1000 ),
					'options'    => [
						'1-1'   => 'AB',
						'x'     => 'ignored',
						'99-99' => 'unknown field',
					],
				]
			)
		);
		$response = $wp_rest_server->dispatch( $request );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$item = $response->get_data()['items'][0];
		self::assertSame( 1300, $item['prices']['active']['amount'] );
		self::assertSame(
			[
				[
					'name'  => 'Engraving',
					'value' => 'AB',
				],
			],
			$item['attributes']
		);
	}

	/** @return array<string,mixed> */
	private function gift(): array {
		return [
			'name'   => 'Gift',
			'fields' => [
				[
					'type'    => OptionField::CHECKBOXES,
					'label'   => 'Gift wrap',
					'choices' => [
						[
							'label'      => 'Wrap',
							'price_mode' => OptionField::FIXED,
							'price'      => 500,
						],
						[
							'label'      => 'Card',
							'price_mode' => OptionField::PERCENT,
							'price'      => 1000,
						],
					],
				],
				[
					'type'  => OptionField::TEXTAREA,
					'label' => 'Gift message',
					'logic' => [
						'action' => OptionField::SHOW,
						'rules'  => [
							[
								'field' => 1,
								'op'    => OptionField::IS,
								'value' => 1,
							],
						],
					],
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private function engraving( string $mode = OptionField::FIXED, int $price = 300 ): array {
		return [
			'name'   => 'Engraving',
			'fields' => [
				[
					'type'       => OptionField::TEXT,
					'label'      => 'Engraving',
					'price_mode' => $mode,
					'price'      => $price,
				],
			],
		];
	}

	private function sets(): OptionSetRepository {
		return Plugin::instance()->option_sets();
	}

	private function shopper( ?MemoryTokenStore $tokens = null ): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			$tokens ?? new MemoryTokenStore(),
			rules: fn (): PriceRules => $plugin->price_rules(),
			product_options: fn (): ProductOptions => new ProductOptions( $plugin->option_sets() ),
		);
	}

	private function reset( CartServices $browser ): void {
		$browser->pricing()->reset();
	}
}
