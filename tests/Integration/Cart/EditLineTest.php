<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Cart\SessionNonce;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\FormHandler;
use Flexa\Commerce\Storefront\Render\AddToCartForm;

/** Changing the choices of a cart line (ADR-037): the service, the routes and the forms. */
final class EditLineTest extends CartTestCase {
	private MemoryTokenStore $cookie;

	public function set_up(): void {
		parent::set_up();
		$this->cookie = new MemoryTokenStore();
		$this->swap_cart( $this->shopper() );
	}

	public function tear_down(): void {
		global $wpdb;
		$_GET  = [];
		$_POST = [];
		unset( $_SERVER['HTTP_REFERER'] );
		parent::tear_down();
		// After the rollback: a cart write commits, which can keep the list.
		update_option( ProductOptions::OPTION, [], true );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		$this->swap_cart( null );
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
	}

	public function test_a_line_changes_in_its_place(): void {
		[ $id ] = $this->sized();
		$cart   = Plugin::instance()->cart()->cart();
		$first  = $cart->add( 0, $id, 0, 2, [ 'l:size' => 's' ] )->cart->lines[0];
		$cart->add( 0, $id, 0, 1, [ 'l:size' => 'm' ] );

		$lines = $cart->replace( 0, $first->key, $id, 0, 3, [ 'l:size' => 'l' ] )->cart->lines;
		self::assertSame( [ [ 'l:size' => 'l' ], [ 'l:size' => 'm' ] ], array_column( $lines, 'attributes' ), 'The line keeps its place.' );
		self::assertSame( [ 3, 1 ], array_column( $lines, 'quantity' ) );
		self::assertSame( 'L', $lines[0]->labels[0]['value'] );
		self::assertSame( $first->added_at, $lines[0]->added_at );

		$lines = $cart->replace( 0, $lines[0]->key, $id, 0, 4, [ 'l:size' => 'l' ] )->cart->lines;
		self::assertSame( [ 4, 1 ], array_column( $lines, 'quantity' ), 'The same choices set the quantity.' );

		$lines = $cart->replace( 0, $lines[0]->key, $id, 0, 2, [ 'l:size' => 'm' ] )->cart->lines;
		self::assertCount( 1, $lines, 'Choices that match another line join it.' );
		self::assertSame( 3, $lines[0]->quantity );
	}

	public function test_a_change_is_checked_like_an_add(): void {
		[ $id ] = $this->sized( 5 );
		$cart   = Plugin::instance()->cart()->cart();
		$key    = $cart->add( 0, $id, 0, 4, [ 'l:size' => 's' ] )->cart->lines[0]->key;
		$cart->add( 0, $id, 0, 3, [ 'l:size' => 'm' ] );

		// The units leaving the old line are not counted twice.
		self::assertSame( 5, $cart->replace( 0, $key, $id, 0, 5, [ 'l:size' => 's' ] )->cart->lines[0]->quantity );
		$this->assertThrows( InsufficientStock::class, fn () => $cart->replace( 0, $key, $id, 0, 3, [ 'l:size' => 'm' ] ) );

		$other = $this->simple();
		foreach ( [ [ 'abcdef0123456789', $id ], [ $key, $other ] ] as [ $line, $product ] ) {
			try {
				$cart->replace( 0, $line, $product, 0, 1, [ 'l:size' => 'l' ] );
				self::fail( 'An unknown line, or a line of another product, is refused.' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'unknown_line', $e->reason );
			}
		}
		self::assertSame( 8, $cart->state( 0 )->cart->item_count(), 'Nothing changed.' );
	}

	public function test_option_values_change(): void {
		$id = $this->simple( 1000 );
		$this->engraving();
		$cart = Plugin::instance()->cart()->cart();
		$key  = $cart->add( 0, $id, 0, 1, [], [ '1-1' => 'AB' ] )->cart->lines[0]->key;

		$state = $cart->replace( 0, $key, $id, 0, 2, [], [ '1-1' => 'CD' ] );
		self::assertCount( 1, $state->items );
		self::assertSame( [ '1-1' => 'CD' ], $state->items[0]->line->options );
		self::assertSame( 'CD', $state->items[0]->line->labels[0]['value'] );
		self::assertSame( 2600, $state->items[0]->total?->amount );
	}

	public function test_the_product_page_starts_from_the_line(): void {
		[ $id ] = $this->sized();
		$cart   = Plugin::instance()->cart()->cart();
		$key    = $cart->add( 0, $id, 0, 2, [ 'l:size' => 'm' ] )->cart->lines[0]->key;

		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertStringContainsString( '>Add to cart<', $html, 'No line named: a plain form.' );
		self::assertStringNotContainsString( 'name="replace"', $html );

		$_GET[ AddToCartForm::EDIT ] = $key;
		$html                        = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertStringContainsString( '>Update cart<', $html );
		self::assertStringContainsString( 'name="replace" value="' . $key . '"', $html );
		self::assertStringContainsString( '<option value="m" selected', $html );
		self::assertMatchesRegularExpression( '/name="quantity" value="2"/', $html );
		self::assertStringContainsString( 'href="' . esc_url( Assets::cart_url() ) . '">Cancel</a>', $html );

		self::assertStringNotContainsString( 'name="replace"', do_shortcode( '[flexacommerce_add_to_cart id="' . $this->simple() . '"]' ), 'The key names a line of another product.' );
	}

	public function test_option_fields_start_from_the_line(): void {
		$id = $this->simple( 1000 );
		$this->engraving();
		$key = Plugin::instance()->cart()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => 'A "B"' ] )->cart->lines[0]->key;

		$_GET[ AddToCartForm::EDIT ] = $key;
		self::assertStringContainsString( 'value="A &quot;B&quot;"', do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' ) );
	}

	public function test_the_cart_links_lines_with_choices(): void {
		[ $sized ] = $this->sized();
		$plain     = $this->simple();
		$cart      = Plugin::instance()->cart()->cart();
		$key       = $cart->add( 0, $sized, 0, 1, [ 'l:size' => 's' ] )->cart->lines[0]->key;
		$cart->add( 0, $plain, 0, 1 );

		$html = do_shortcode( '[flexacommerce_cart]' );
		self::assertSame( 1, substr_count( $html, 'class="fxc-cart__edit"' ), 'A product with nothing to pick has no link.' );
		self::assertStringContainsString( esc_url( add_query_arg( AddToCartForm::EDIT, $key, (string) get_permalink( $sized ) ) ), $html );
	}

	public function test_the_routes_replace(): void {
		global $wp_rest_server;
		[ $id ] = $this->sized();
		$key    = Plugin::instance()->cart()->cart()->add( 0, $id, 0, 1, [ 'l:size' => 's' ] )->cart->lines[0]->key;

		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$request = new \WP_REST_Request( 'POST', '/flexacommerce/v1/store/cart/items' );
		$request->set_header( 'origin', home_url() );
		$request->set_header( SessionNonce::HEADER, Plugin::instance()->cart()->nonce()->create( 0, '', $this->cookie->get() ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'product_id' => $id,
					'quantity'   => 2,
					'attributes' => [ 'l:size' => 'l' ],
					'replace'    => $key,
				]
			)
		);
		$response = $wp_rest_server->dispatch( $request );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertCount( 1, $response->get_data()['items'] );
		self::assertSame( 2, $response->get_data()['item_count'] );

		$request->set_body(
			(string) wp_json_encode(
				[
					'product_id' => $id,
					'replace'    => 'nope',
				]
			)
		);
		self::assertSame( 400, $wp_rest_server->dispatch( $request )->get_status() );
	}

	public function test_the_form_without_javascript_goes_back_to_the_cart(): void {
		[ $id ] = $this->sized();
		$cart   = Plugin::instance()->cart();
		$key    = $cart->cart()->add( 0, $id, 0, 1, [ 'l:size' => 's' ] )->cart->lines[0]->key;
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new \RuntimeException( $location );
			}
		);
		$_SERVER['HTTP_REFERER'] = home_url( '/shop/shirt/' );
		$_POST                   = [
			'action'           => FormHandler::ACTION,
			'fxc_op'           => 'add',
			FormHandler::FIELD => $cart->nonce()->create( 0, '', $cart->sessions()->token() ),
			'product_id'       => (string) $id,
			'attributes'       => [ 'l:size' => 'm' ],
			'quantity'         => '3',
			'replace'          => $key,
		];
		$this->swap_cart( $this->shopper() );
		try {
			FormHandler::handle();
			self::fail( 'No redirect.' );
		} catch ( \RuntimeException $redirect ) {
			self::assertSame( add_query_arg( FormHandler::RESULT, 'updated', Assets::cart_url() ), $redirect->getMessage() );
		}
		$lines = Plugin::instance()->cart()->cart()->state( 0 )->cart->lines;
		self::assertSame( [ [ 'l:size' => 'm' ], 3 ], [ $lines[0]->attributes, $lines[0]->quantity ] );
		self::assertCount( 1, $lines );
	}

	private function engraving(): void {
		Plugin::instance()->option_sets()->save(
			[
				'name'   => 'Engraving',
				'fields' => [
					[
						'type'       => OptionField::TEXT,
						'label'      => 'Engraving',
						'price_mode' => OptionField::FIXED,
						'price'      => 300,
					],
				],
			]
		);
	}

	/** @param class-string<\Throwable> $class */
	private function assertThrows( string $class, callable $run ): void {
		try {
			$run();
		} catch ( \Throwable $e ) {
			self::assertInstanceOf( $class, $e );
			return;
		}
		self::fail( $class . ' was not thrown.' );
	}

	private function shopper(): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			$this->cookie,
			product_options: fn (): ProductOptions => new ProductOptions( $plugin->option_sets() ),
		);
	}

	private function swap_cart( ?CartServices $services ): void {
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), $services );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
	}
}
