<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Cart\SessionNonce;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * Store routes through the REST server. Each "request" gets a fresh
 * CartServices whose cookie store holds what the browser would send.
 */
final class StoreApiTest extends OrderTestCase {
	private \WP_REST_Server $server;

	private MemoryTokenStore $cookie;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		$this->cookie = new MemoryTokenStore();
		$this->next_request();
	}

	public function tear_down(): void {
		$this->swap_cart( null );
		parent::tear_down();
	}

	public function test_get_cart_is_public_and_creates_no_session(): void {
		$response = $this->dispatch( 'GET', '/store/cart' );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 0, $response->get_data()['item_count'] );
		self::assertNull( $this->cookie->get() );
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		self::assertNotEmpty( $response->get_headers()[ SessionNonce::HEADER ] );
	}

	public function test_add_with_the_public_guest_nonce_then_with_the_session_nonce(): void {
		$id       = $this->simple( 1500 );
		$response = $this->dispatch(
			'POST',
			'/store/cart/items',
			[
				'product_id' => $id,
				'quantity'   => 2,
			]
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 3000, $response->get_data()['totals']['subtotal']['amount'] );
		self::assertNotNull( $this->cookie->get() );

		// The response nonce is bound to the new session.
		$nonce = $response->get_headers()[ SessionNonce::HEADER ];
		$this->next_request();
		$key      = $response->get_data()['items'][0]['key'];
		$response = $this->dispatch( 'PUT', '/store/cart/items/' . $key, [ 'quantity' => 5 ], $nonce );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 5, $response->get_data()['item_count'] );

		$this->next_request();
		$response = $this->dispatch( 'DELETE', '/store/cart/items/' . $key, null, $nonce );
		self::assertSame( 0, $response->get_data()['item_count'] );
	}

	public function test_writes_need_json(): void {
		$request = $this->request( 'POST', '/store/cart/items', null );
		$request->set_header( 'content-type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( [ 'product_id' => $this->simple() ] );
		self::assertSame( 415, $this->server->dispatch( $request )->get_status() );
	}

	public function test_cross_site_and_null_origin_are_refused(): void {
		$id = $this->simple();
		foreach ( [ 'https://evil.test', 'null' ] as $origin ) {
			$request = $this->request( 'POST', '/store/cart/items', [ 'product_id' => $id ] );
			$request->set_header( 'origin', $origin );
			$response = $this->server->dispatch( $request );
			self::assertSame( 403, $response->get_status(), $origin );
			self::assertSame( 'flexacommerce_bad_origin', $response->get_data()['code'] );
		}
		self::assertNull( $this->cookie->get() );
	}

	public function test_bad_nonce_returns_a_fresh_one(): void {
		$response = $this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $this->simple() ], 'wrong' );
		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'flexacommerce_invalid_nonce', $response->get_data()['code'] );
		self::assertSame( $this->nonce(), $response->get_data()['data']['nonce'] );
	}

	public function test_a_logged_in_shopper_cannot_use_the_guest_nonce(): void {
		$guest_nonce = $this->nonce();
		wp_set_current_user( self::factory()->user->create() );
		$response = $this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $this->simple() ], $guest_nonce );
		self::assertSame( 403, $response->get_status() );
		self::assertSame( 201, $this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $this->simple() ] )->get_status() );
	}

	public function test_errors_map_to_status_codes(): void {
		$out = $this->simple( 1000, 0 );
		self::assertSame( 409, $this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $out ] )->get_status() );
		self::assertSame( 400, $this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => 999999 ] )->get_status() );
		self::assertSame( 400, $this->dispatch( 'PUT', '/store/cart/items/0123456789abcdef', [ 'quantity' => 1 ] )->get_status() );
	}

	public function test_store_variants_lists_options_of_published_products_only(): void {
		[ $id, $sizes ] = $this->sized();
		$response       = $this->dispatch( 'GET', '/store/products/' . $id . '/variants' );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'public, max-age=60', $response->get_headers()['Cache-Control'] );
		self::assertCount( 3, $response->get_data()['variants'] );
		self::assertTrue( $response->get_data()['in_stock'] );

		wp_update_post(
			[
				'ID'          => $id,
				'post_status' => 'draft',
			]
		);
		self::assertSame( 404, $this->dispatch( 'GET', '/store/products/' . $id . '/variants' )->get_status() );
		unset( $sizes );
	}

	public function test_checkout_places_an_order_and_reports_field_errors(): void {
		$cod = Plugin::instance()->payments()->gateways()->get( 'cod' );
		self::assertNotNull( $cod );
		Plugin::instance()->payments()->gateways()->settings()->save( $cod, [ 'enabled' => true ] );
		$response = $this->dispatch( 'POST', '/store/cart/items', [ 'product_id' => $this->simple( 1500 ) ] );
		$nonce    = $response->get_headers()[ SessionNonce::HEADER ];

		$this->next_request();
		$form = $this->dispatch( 'GET', '/store/checkout' );
		self::assertSame( 200, $form->get_status() );
		self::assertSame( 'no-store, private', $form->get_headers()['Cache-Control'] );
		$token = $form->get_data()['checkout_token'];
		self::assertNotSame( '', $token );

		$body = [
			'checkout_token' => $token,
			'billing'        => [
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'address_1'  => '1 Main St',
				'city'       => 'Hanoi',
				'postcode'   => '100000',
				'country'    => 'VN',
			],
			'payment_method' => 'cod',
			'expected_total' => 1500,
		];
		$this->next_request();
		$error = $this->dispatch( 'POST', '/store/checkout', $body, $nonce );
		self::assertSame( 400, $error->get_status() );
		self::assertSame( 'flexacommerce_invalid_checkout', $error->get_data()['code'] );
		self::assertArrayHasKey( 'billing.email', $error->get_data()['data']['errors'] );

		$body['billing']['email'] = 'ada@example.com';
		$this->next_request();
		$changed = $this->dispatch( 'POST', '/store/checkout', [ 'expected_total' => 1499 ] + $body, $nonce );
		self::assertSame( 409, $changed->get_status() );

		$this->next_request();
		$placed = $this->dispatch( 'POST', '/store/checkout', $body, $nonce );
		self::assertSame( 201, $placed->get_status(), (string) wp_json_encode( $placed->get_data() ) );
		self::assertSame( 'processing', $placed->get_data()['status'] );
		self::assertStringContainsString( 'order-received=', $placed->get_data()['redirect'] );

		delete_option( GatewaySettings::PREFIX . 'cod' );
	}

	/** A new request: fresh services, the browser's cookie carried over. */
	private function next_request(): void {
		$this->swap_cart( new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), $this->cookie ) );
	}

	private function swap_cart( ?CartServices $services ): void {
		$property = new \ReflectionProperty( Plugin::class, 'cart' );
		$property->setValue( Plugin::instance(), $services );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
	}

	private function nonce(): string {
		return Plugin::instance()->cart()->nonce()->create( get_current_user_id(), wp_get_session_token(), $this->cookie->get() );
	}

	/** @param array<string,mixed>|null $body */
	private function request( string $method, string $path, ?array $body, ?string $nonce = null ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_header( 'origin', home_url() );
		$request->set_header( SessionNonce::HEADER, $nonce ?? $this->nonce() );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return $request;
	}

	/** @param array<string,mixed>|null $body */
	private function dispatch( string $method, string $path, ?array $body = null, ?string $nonce = null ): \WP_REST_Response {
		return $this->server->dispatch( $this->request( $method, $path, $body, $nonce ) );
	}
}
