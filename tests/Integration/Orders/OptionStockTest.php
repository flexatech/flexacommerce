<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Orders;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidOptionSet;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Inventory\OptionStockRepository;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSetRepository;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\RefundLine;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Pricing\Rules\PriceRules;

/** Stock of option choices (ADR-040): the editor, the cart, orders and the form. */
final class OptionStockTest extends OrderTestCase {
	private const GOLD   = '1-1-2';
	private const SILVER = '1-1-1';

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		update_option( ProductOptions::OPTION, [], true );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'option_stock' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
	}

	public function test_the_editor_keeps_the_counts(): void {
		$set = $this->sets()->save( $this->finish( 3 ) );
		self::assertFalse( $set->fields[0]->choices[0]['track_stock'] );
		self::assertTrue( $set->fields[0]->choices[1]['track_stock'] );
		self::assertSame( [ self::GOLD => 3 ], $this->levels() );

		// An order takes one while the editor is open on 3.
		$this->stock()->take( [ self::GOLD => $this->want( 1 ) ] );
		$this->sets()->save( $this->finish( 3, 3, 'Finish now' ), $set->id );
		self::assertSame( [ self::GOLD => 2 ], $this->levels(), 'An unchanged count keeps what orders took.' );
		$this->sets()->save( [ 'enabled' => false ], $set->id );
		self::assertSame( [ self::GOLD => 2 ], $this->levels(), 'A save without fields leaves the counts.' );
		self::assertTrue( $this->sets()->find( $set->id )?->fields[0]->choices[1]['track_stock'] );

		$this->sets()->save( $this->finish( 10, 2 ), $set->id );
		self::assertSame( [ self::GOLD => 10 ], $this->levels() );

		$this->sets()->save( $this->finish( null ), $set->id );
		self::assertSame( [], $this->levels(), 'An empty count stops tracking.' );

		$this->sets()->save( $this->finish( 0 ), $set->id );
		self::assertSame( [ self::GOLD => 0 ], $this->levels() );
		$this->sets()->delete( $set->id );
		self::assertSame( [], $this->levels() );

		$this->expectException( InvalidOptionSet::class );
		$this->expectExceptionMessage( 'Enter the stock as a whole number of 0 or more, or leave it empty.' );
		$this->sets()->save( $this->finish( -1 ) );
	}

	public function test_the_cart_counts_every_line(): void {
		$a = $this->simple( 1000 );
		$b = $this->simple( 1000 );
		$this->sets()->save( $this->finish( 3 ) );
		$cart = $this->shopper()->cart();

		try {
			$cart->add( 0, $a, 0, 4, [], [ '1-1' => '2' ] );
			self::fail( 'Added 4 of 3.' );
		} catch ( InsufficientStock $e ) {
			self::assertSame( 'Only 3 of Finish: Gold are left.', $e->getMessage() );
			self::assertSame( 3, $e->available );
		}
		$cart->add( 0, $a, 0, 2, [], [ '1-1' => '2' ] );
		$state = $cart->add( 0, $b, 0, 1, [], [ '1-1' => '2' ] );
		try {
			$cart->add( 0, $a, 0, 1, [], [ '1-1' => '2' ] );
			self::fail( 'Added a fourth.' );
		} catch ( InsufficientStock $e ) {
			self::assertSame( 'Finish: Gold is sold out.', $e->getMessage(), 'The other product holds the third.' );
		}
		try {
			$cart->set_quantity( 0, $state->items[1]->line->key, 2 );
			self::fail( 'Raised past the stock.' );
		} catch ( InsufficientStock $e ) {
			self::assertSame( 'Only 1 of Finish: Gold is left.', $e->getMessage(), 'The line can hold one.' );
		}
		$state = $cart->add( 0, $a, 0, 9, [], [ '1-1' => '1' ] );
		self::assertSame( [ self::GOLD => 'Finish: Gold' ], $state->items[0]->option_stock );
		self::assertSame( [], $state->items[2]->option_stock, 'Silver does not track stock.' );

		// Changing the line to another product keeps the same count.
		$state = $cart->replace( 0, $state->items[0]->line->key, $a, 0, 1, [], [ '1-1' => '2' ] );
		self::assertSame( 1, $state->items[0]->line->quantity );
	}

	public function test_orders_take_and_give_back(): void {
		$a = $this->simple( 1000, 5 );
		$this->sets()->save( $this->finish( 4 ) );

		$order = $this->order( $a, 3 );
		self::assertSame( [ self::GOLD => 1 ], $this->levels() );
		self::assertSame( 3, $order->items_of_type( OrderItem::PRODUCT )[0]->option_held() );

		try {
			$this->order( $a, 2 );
			self::fail( 'Took 2 of 1.' );
		} catch ( InsufficientStock $e ) {
			self::assertSame( 'Only 1 of Finish: Gold is left.', $e->getMessage() );
			self::assertSame( [ self::GOLD => 1 ], $this->levels() );
			self::assertSame( 2, $this->stock_of( $a ) );
		}

		$order = $this->orders()->record_payment( $order->id, 3000, TransitionContext::system() );
		$item  = $order->items_of_type( OrderItem::PRODUCT )[0];
		$order = $this->orders()->record_refund( $order->id, 1000, [ new RefundLine( $item->id, 1, 1000, true ) ], '', TransitionContext::admin() );
		self::assertSame( [ self::GOLD => 2 ], $this->levels() );
		self::assertSame( 2, $order->item( $item->id )?->option_held() );

		$this->orders()->cancel( $order->id, TransitionContext::admin() );
		self::assertSame( [ self::GOLD => 4 ], $this->levels() );
		$this->orders()->cancel( $order->id, TransitionContext::admin() );
		self::assertSame( [ self::GOLD => 4 ], $this->levels(), 'Given back once.' );
	}

	public function test_a_short_variant_gives_the_choices_back(): void {
		$a = $this->simple( 1000, 1 );
		$this->sets()->save( $this->finish( 4 ) );
		try {
			$this->order( $a, 2 );
			self::fail( 'Took 2 of 1.' );
		} catch ( InsufficientStock ) {
			self::assertSame( [ self::GOLD => 4 ], $this->levels() );
		}
	}

	public function test_the_form_and_the_listing_show_the_counts(): void {
		$id = $this->simple();
		$this->sets()->save( $this->finish( 0, null, 'Finish', OptionField::SWATCH ) );
		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertMatchesRegularExpression( '/value="2"[^>]* disabled data-out>/', $html );
		self::assertStringContainsString( 'Gold (out of stock)', $html );
		self::assertDoesNotMatchRegularExpression( '/value="2" checked/', $html, 'A sold-out default is not picked.' );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$choices = $wp_rest_server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/product-options' ) )->get_data()['items'][0]['fields'][0]['choices'];
		self::assertSame( [ null, 0 ], array_column( $choices, 'stock' ) );
	}

	/** @return array{quantity:int,label:string,variant_id:int} */
	private function want( int $quantity ): array {
		return [
			'quantity'   => $quantity,
			'label'      => 'Gold',
			'variant_id' => 0,
		];
	}

	/** @return array<string,int> */
	private function levels(): array {
		return $this->stock()->levels( [ self::SILVER, self::GOLD ] );
	}

	/**
	 * Set 1, field 1: Silver (no stock), Gold with `$gold` (null: untracked).
	 *
	 * @return array<string,mixed>
	 */
	private function finish( ?int $gold, ?int $was = null, string $name = 'Finish', string $type = OptionField::SELECT ): array {
		return [
			'name'   => $name,
			'fields' => [
				[
					'id'      => 1,
					'type'    => $type,
					'label'   => 'Finish',
					'choices' => [
						[
							'id'    => 1,
							'label' => 'Silver',
							'color' => '#cccccc',
							'stock' => null,
						],
						[
							'id'        => 2,
							'label'     => 'Gold',
							'color'     => '#ffd700',
							'default'   => true,
							'stock'     => $gold,
							'stock_was' => $was,
						],
					],
				],
			],
		];
	}

	/** A pending order of `$qty` Gold items, as checkout writes it. */
	private function order( int $product_id, int $qty ): Order {
		$product = $this->service()->get( $product_id );
		$billing = new AddressView( type: 'billing', first_name: 'Ada', last_name: 'Lovelace', country: 'US', email: 'ada@example.com' );
		$new     = new NewOrder(
			status: OrderStatus::PENDING,
			currency: 'USD',
			currency_exponent: 2,
			items: [ NewOrderItem::product( $product_id, $product->base->id, $product->base->id, 'Ring', null, [], $qty, 1000, option_stock: [ self::GOLD => 'Finish: Gold' ] ) ],
			subtotal: 1000 * $qty,
			shipping_total: 0,
			total: 1000 * $qty,
			customer_email: 'ada@example.com',
			billing: $billing,
			payment_method: 'cod',
			payment_method_title: 'cod',
			checkout_token: wp_generate_password( 32, false ),
		);
		return $this->orders()->create( $new, $this->orders()->next_number(), new TransitionContext( 'customer' ) );
	}

	private function stock(): OptionStockRepository {
		return Plugin::instance()->option_stock();
	}

	private function sets(): OptionSetRepository {
		return Plugin::instance()->option_sets();
	}

	private function shopper(): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			new MemoryTokenStore(),
			rules: fn (): PriceRules => $plugin->price_rules(),
			product_options: fn (): ProductOptions => new ProductOptions( $plugin->option_sets() ),
		);
	}
}
