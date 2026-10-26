<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Cart\SessionNonce;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\FormHandler;
use Flexa\Commerce\Storefront\Render\CartTable;

/** The store and admin coupon routes, the no-JS form and the cart markup. */
final class CouponRoutesTest extends CouponTestCase {
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
		$_POST = [];
		unset( $_SERVER['HTTP_REFERER'] );
		remove_all_filters( 'wp_redirect' );
		$this->swap_cart( null );
		parent::tear_down();
	}

	public function test_store_routes_apply_and_remove_a_code(): void {
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		$response = $this->store( 'POST', '/store/cart/items', [ 'product_id' => $this->simple( 1000 ) ] );
		$nonce    = $response->get_headers()[ SessionNonce::HEADER ];

		$this->next_request();
		$response = $this->store( 'POST', '/store/cart/coupons', [ 'code' => 'Ten' ], $nonce );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 100, $response->get_data()['totals']['discount']['amount'] );
		self::assertSame( 'TEN', $response->get_data()['coupons'][0]['label'] );

		$this->next_request();
		$response = $this->store( 'POST', '/store/cart/coupons', [ 'code' => 'nope' ], $nonce );
		self::assertSame( 400, $response->get_status() );
		self::assertStringContainsString( 'does not exist', $response->get_data()['message'] );

		$this->next_request();
		$response = $this->store( 'DELETE', '/store/cart/coupons/ten', null, $nonce );
		self::assertSame( 200, $response->get_status() );
		self::assertArrayNotHasKey( 'coupons', $response->get_data(), 'a cart without codes has no coupon fields' );
		self::assertArrayNotHasKey( 'discount', $response->get_data()['totals'] );
	}

	public function test_the_no_js_form_applies_and_removes(): void {
		$this->coupon( 'TEN', Coupon::PERCENT, 1000 );
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new \RuntimeException( $location );
			}
		);
		$_SERVER['HTTP_REFERER'] = home_url( '/cart/' );
		Plugin::instance()->cart()->cart()->add( 0, $id = $this->simple( 1000 ), $this->service()->get( $id )->base->id, 1 );

		self::assertSame( 'coupon_invalid', $this->form( [ 'coupon_code' => 'nope' ] ) );
		self::assertSame( 'coupon_empty', $this->form( [ 'coupon_code' => '' ] ) );
		self::assertSame( 'coupon_applied', $this->form( [ 'coupon_code' => 'ten' ] ) );
		self::assertSame( 'coupon_duplicate', $this->form( [ 'coupon_code' => 'TEN' ] ) );
		self::assertSame( [ 'ten' ], Plugin::instance()->cart()->cart()->state( 0 )->cart->coupons );

		$cart = Plugin::instance()->cart();
		$html = ( new CartTable( $cart->presenter(), $cart->cart(), $cart->pricing() ) )->html( $cart->cart()->state( 0 ) );
		self::assertStringContainsString( 'id="fxc-cart-coupon"', $html );
		self::assertStringContainsString( 'fxc-cart__coupon-row', $html );
		self::assertStringContainsString( 'name="remove_coupon"', $html );
		self::assertStringContainsString( 'TEN', $html );

		self::assertSame( 'coupon_removed', $this->form( [ 'remove_coupon' => 'ten' ] ) );
		self::assertSame( [], Plugin::instance()->cart()->cart()->state( 0 )->cart->coupons );
		$_GET[ FormHandler::RESULT ] = 'coupon_applied';
		self::assertStringContainsString( 'Coupon applied', FormHandler::notice_for( 0 ) );
		$_GET = [];
	}

	public function test_admin_crud_with_validation(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$created = $this->admin(
			'POST',
			'/admin/coupons',
			[
				'code'       => 'Spring',
				'type'       => 'percent',
				'amount'     => 1500,
				'expires_at' => '2099-04-01T00:00:00Z',
				'emails'     => 'A@Example.com, *@staff.test',
			]
		);
		self::assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$id = $created->get_data()['id'];
		self::assertSame( '2099-04-01T00:00:00Z', $created->get_data()['expires_at'] );
		self::assertSame( [ 'a@example.com', '*@staff.test' ], $created->get_data()['emails'] );

		foreach ( [
			'code'       => [ 'code' => 'spring' ],
			'amount'     => [ 'amount' => 10001 ],
			'type'       => [ 'type' => 'bogus' ],
			'expires_at' => [
				'starts_at'  => '2099-05-01 00:00',
				'expires_at' => '2099-04-01 00:00',
			],
		] as $field => $body ) {
			$response = $this->admin(
				'POST',
				'/admin/coupons',
				$body + [
					'code'   => 'Other',
					'type'   => 'percent',
					'amount' => 1000,
				]
			);
			self::assertSame( 400, $response->get_status(), $field );
			self::assertSame( $field, $response->get_data()['data']['field'], $field );
		}

		$patched = $this->admin( 'PATCH', "/admin/coupons/{$id}", [ 'status' => 'disabled' ] );
		self::assertSame( 200, $patched->get_status() );
		self::assertSame( 'disabled', $patched->get_data()['status'] );
		self::assertSame( 1500, $patched->get_data()['amount'], 'a partial save keeps the rest' );

		$list = $this->admin( 'GET', '/admin/coupons', null, [ 'status' => 'disabled' ] );
		self::assertSame( '1', $list->get_headers()['X-WP-Total'] );
		self::assertSame( 'Spring', $list->get_data()[0]['code'] );
		self::assertSame( '0', $this->admin( 'GET', '/admin/coupons', null, [ 'search' => 'zzz' ] )->get_headers()['X-WP-Total'] );

		self::assertSame( 200, $this->admin( 'DELETE', "/admin/coupons/{$id}" )->get_status() );
		self::assertSame( 404, $this->admin( 'GET', "/admin/coupons/{$id}" )->get_status() );
	}

	public function test_admin_routes_need_the_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->admin( 'GET', '/admin/coupons' )->get_status() );
		self::assertSame( 403, $this->admin( 'POST', '/admin/coupons', [ 'code' => 'x' ] )->get_status() );
	}

	/** @param array<string,mixed> $fields */
	private function form( array $fields ): string {
		$cart  = Plugin::instance()->cart();
		$_POST = [
			'action'           => FormHandler::ACTION,
			'fxc_op'           => 'coupon',
			FormHandler::FIELD => $cart->nonce()->create( 0, '', $cart->sessions()->token() ),
		] + $fields;
		$this->next_request();
		try {
			FormHandler::handle();
		} catch ( \RuntimeException $redirect ) {
			parse_str( (string) wp_parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $query );
			return (string) ( $query[ FormHandler::RESULT ] ?? '' );
		}
		self::fail( 'No redirect.' );
	}

	private function next_request(): void {
		$this->swap_cart( $this->shopper( $this->cookie ) );
	}

	private function swap_cart( ?CartServices $services ): void {
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), $services );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
	}

	/** @param array<string,mixed>|null $body */
	private function store( string $method, string $path, ?array $body, ?string $nonce = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_header( 'origin', home_url() );
		$request->set_header(
			SessionNonce::HEADER,
			$nonce ?? Plugin::instance()->cart()->nonce()->create( 0, '', $this->cookie->get() )
		);
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,mixed>      $query
	 */
	private function admin( string $method, string $path, ?array $body = null, array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_query_params( $query );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return $this->server->dispatch( $request );
	}
}
