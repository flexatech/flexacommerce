<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\FormHandler;

/**
 * The no-JS cart forms. The handler ends with a redirect and exit, so the
 * redirect is turned into an exception that carries the location.
 */
final class FormHandlerTest extends CartTestCase {
	private MemoryTokenStore $cookie;

	public function set_up(): void {
		parent::set_up();
		$this->cookie = new MemoryTokenStore();
		$this->swap_cart( $this->browser( $this->cookie ) );
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new \RuntimeException( $location );
			}
		);
		$_SERVER['HTTP_REFERER'] = home_url( '/shop/mug/' );
	}

	public function tear_down(): void {
		$_POST = [];
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ORIGIN'] );
		$this->swap_cart( null );
		parent::tear_down();
	}

	public function test_add_then_update_and_remove(): void {
		$id = $this->simple( 1000, 5 );
		self::assertSame(
			'added',
			$this->post(
				'add',
				[
					'product_id' => (string) $id,
					'quantity'   => '2',
				]
			)
		);
		$cart = Plugin::instance()->cart()->cart()->state( 0 )->cart;
		self::assertSame( 2, $cart->item_count() );
		$key = array_values( $cart->lines )[0]->key;

		self::assertSame( 'updated', $this->post( 'update', [ 'quantities' => [ $key => '4' ] ] ) );
		self::assertSame( 4, Plugin::instance()->cart()->cart()->state( 0 )->cart->item_count() );

		self::assertSame( 'removed', $this->post( 'update', [ 'remove' => $key ] ) );
		self::assertSame( 0, Plugin::instance()->cart()->cart()->state( 0 )->cart->item_count() );
	}

	public function test_variable_product_from_select_fields(): void {
		[ $id ] = $this->sized();
		self::assertSame( 'incomplete', $this->post( 'add', [ 'product_id' => (string) $id ] ) );
		self::assertSame(
			'added',
			$this->post(
				'add',
				[
					'product_id' => (string) $id,
					'attributes' => [ 'l:size' => 'm' ],
				]
			)
		);
	}

	public function test_stock_errors_come_back_as_codes(): void {
		$id = $this->simple( 1000, 1 );
		self::assertSame(
			'insufficient_stock',
			$this->post(
				'add',
				[
					'product_id' => (string) $id,
					'quantity'   => '3',
				]
			)
		);
	}

	public function test_cross_site_posts_and_bad_nonces_change_nothing(): void {
		$id                     = $this->simple();
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
		self::assertSame( 'expired', $this->post( 'add', [ 'product_id' => (string) $id ] ) );
		unset( $_SERVER['HTTP_ORIGIN'] );
		self::assertSame( 'expired', $this->post( 'add', [ 'product_id' => (string) $id ], 'wrong' ) );
		self::assertNull( $this->cookie->get() );
	}

	public function test_notice_is_chosen_from_a_fixed_list(): void {
		$_GET[ FormHandler::RESULT ] = '<script>';
		self::assertSame( '', FormHandler::notice_for( 0 ) );
		$_GET[ FormHandler::RESULT ] = 'updated';
		self::assertStringContainsString( 'Cart updated.', FormHandler::notice_for( 0 ) );
		$_GET = [];
	}

	public function test_hidden_fields_carry_a_valid_nonce(): void {
		$html = FormHandler::hidden_fields( 'add' );
		self::assertMatchesRegularExpression( '/name="fxc_nonce" value="[0-9a-f]{24}"/', $html );
	}

	/** @param array<string,mixed> $fields */
	private function post( string $operation, array $fields, ?string $nonce = null ): string {
		$cart  = Plugin::instance()->cart();
		$_POST = [
			'action'           => FormHandler::ACTION,
			'fxc_op'           => $operation,
			FormHandler::FIELD => $nonce ?? $cart->nonce()->create( 0, '', $cart->sessions()->token() ),
		] + $fields;
		// A fresh request: new services, the same cookie.
		$this->swap_cart( $this->browser( $this->cookie ) );
		try {
			FormHandler::handle();
		} catch ( \RuntimeException $redirect ) {
			parse_str( (string) wp_parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $query );
			self::assertStringStartsWith( home_url( '/shop/mug/' ), $redirect->getMessage() );
			return (string) ( $query[ FormHandler::RESULT ] ?? '' );
		}
		self::fail( 'No redirect.' );
	}

	private function swap_cart( ?CartServices $services ): void {
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), $services );
	}
}
