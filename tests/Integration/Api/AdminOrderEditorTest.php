<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Notifications\EmailSettings;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Tests\Integration\Coupons\CouponTestCase;

/**
 * The order editor (ADR-061): drafts priced as a cart, orders made and
 * changed by the admin, address edits and the order details email.
 */
final class AdminOrderEditorTest extends CouponTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		reset_phpmailer_instance();
		// The zones were saved by another repository; the plugin's copy may hold an earlier test's.
		Plugin::instance()->shipping()->service()->zones()->flush();
	}

	public function tear_down(): void {
		delete_option( EmailSettings::PREFIX . 'customer_invoice' );
		parent::tear_down();
		// A transaction in these routes commits the zones saved in set_up, so the rollback cannot remove them.
		delete_option( ZoneRepository::OPTION );
		Plugin::instance()->shipping()->service()->zones()->flush();
	}

	public function test_a_draft_is_priced_like_a_cart_with_its_coupons_and_rates(): void {
		$product = $this->simple( 1000, 5 );
		$this->coupon( 'TENOFF', Coupon::PERCENT, 1000 );

		$quote = $this->send( 'POST', '/admin/orders/quote', $this->draft( [ $product => 2 ], [ 'coupons' => [ 'tenoff', 'NOPE' ] ] ) );
		self::assertSame( 200, $quote->get_status(), (string) wp_json_encode( $quote->get_data() ) );
		$data = $quote->get_data();
		self::assertSame( 2000, $data['totals']['subtotal']['amount'] );
		self::assertSame( 200, $data['totals']['discount']['amount'] );
		self::assertSame( 500, $data['totals']['shipping']['amount'] );
		self::assertSame( 2300, $data['totals']['total']['amount'] );
		self::assertSame( $this->rate_id, $data['shipping_rate'] );
		self::assertSame( [ $this->rate_id ], array_column( $data['shipping_rates'], 'id' ) );
		self::assertSame( [ 'TENOFF', 'nope' ], array_column( $data['coupons'], 'code' ) );
		self::assertSame( [ true, false ], array_column( $data['coupons'], 'applied' ) );
		self::assertArrayHasKey( 'coupons', $data['errors'] );
		self::assertContains( 'cod', array_column( $data['payment_methods'], 'id' ) );

		$empty = $this->send( 'POST', '/admin/orders/quote', $this->draft( [] ) )->get_data();
		self::assertArrayHasKey( 'lines', $empty['errors'] );
		$bad = $this->send( 'POST', '/admin/orders/quote', $this->draft( [ $product => 1 ], [ 'billing' => [ 'country' => 'ZZ' ] + self::BILLING ] ) )->get_data();
		self::assertArrayHasKey( 'billing.country', $bad['errors'] );
	}

	public function test_create_takes_stock_and_the_coupon_and_is_never_expired(): void {
		$product = $this->simple( 1000, 5 );
		$coupon  = $this->coupon( 'TENOFF', Coupon::PERCENT, 1000 );

		$created = $this->send( 'POST', '/admin/orders', $this->draft( [ $product => 2 ], [ 'coupons' => [ 'TENOFF' ] ] ) );
		self::assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$data = $created->get_data();
		self::assertSame( OrderStatus::PENDING, $data['status'] );
		self::assertSame( 'admin', $data['created_via'] );
		self::assertSame( 2300, $data['total'] );
		self::assertTrue( $data['editable'] );
		self::assertStringContainsString( 'order-pay=', $data['links']['pay'] );
		self::assertSame( 3, $this->stock_of( $product ) );
		self::assertSame( 1, $this->uses( $coupon->id ) );
		self::assertContains( 'Order created by the admin.', array_column( $data['history'], 'message' ) );

		$repository = Plugin::instance()->orders()->orders();
		self::assertSame( [], $repository->stale_pending( gmdate( 'Y-m-d H:i:s', time() + 3600 ), 10 ) );

		$refused = $this->send( 'POST', '/admin/orders', $this->draft( [ $product => 9 ] ) );
		self::assertSame( 400, $refused->get_status() );
		self::assertArrayHasKey( 'lines', $refused->get_data()['data']['errors'] );
	}

	public function test_editing_an_unpaid_order_gives_back_and_takes_again_its_stock_and_coupons(): void {
		$product = $this->simple( 1000, 5 );
		$other   = $this->simple( 400, 5 );
		$coupon  = $this->coupon( 'TENOFF', Coupon::PERCENT, 1000 );
		$id      = $this->send( 'POST', '/admin/orders', $this->draft( [ $product => 2 ], [ 'coupons' => [ 'TENOFF' ] ] ) )->get_data()['id'];

		$draft = $this->send( 'GET', '/admin/orders/' . $id . '/draft' )->get_data();
		self::assertSame( [ $product ], array_column( $draft['lines'], 'product_id' ) );
		self::assertSame( [ 'TENOFF' ], $draft['coupons'] );
		self::assertSame( $this->rate_id, $draft['shipping_rate'] );
		self::assertTrue( $draft['editable'] );

		$edited = $this->send(
			'PUT',
			'/admin/orders/' . $id,
			$this->draft(
				[
					$product => 1,
					$other   => 3,
				]
			)
		);
		self::assertSame( 200, $edited->get_status(), (string) wp_json_encode( $edited->get_data() ) );
		self::assertSame( 1000 + 1200 + 500, $edited->get_data()['total'] );
		self::assertSame( 4, $this->stock_of( $product ) );
		self::assertSame( 2, $this->stock_of( $other ) );
		self::assertSame( 0, $this->uses( $coupon->id ) );
		self::assertSame( [ OrderItem::PRODUCT, OrderItem::PRODUCT, OrderItem::SHIPPING ], array_column( $edited->get_data()['items'], 'type' ) );
		self::assertContains( 'Order edited by the admin.', array_column( $edited->get_data()['history'], 'message' ) );

		$this->orders()->record_payment( $id, 2700, TransitionContext::admin() );
		$paid = $this->send( 'PUT', '/admin/orders/' . $id, $this->draft( [ $product => 1 ] ) );
		self::assertSame( 400, $paid->get_status() );
		self::assertSame( 4, $this->stock_of( $product ) );
	}

	public function test_a_variation_line_keeps_its_choices_for_the_next_edit(): void {
		[ $product, $ids ] = $this->sized();
		$lines             = [
			[
				'product_id' => $product,
				'variant_id' => $ids['m'] ?? reset( $ids ),
				'quantity'   => 1,
			],
		];
		$id                = $this->send( 'POST', '/admin/orders', [ 'lines' => $lines ] + $this->draft( [] ) )->get_data()['id'];
		$order             = $this->orders()->get( $id );
		self::assertSame( $ids['m'] ?? reset( $ids ), $order->items_of_type( OrderItem::PRODUCT )[0]->variant_id );

		$draft = $this->send( 'GET', '/admin/orders/' . $id . '/draft' )->get_data();
		self::assertSame( $ids['m'] ?? reset( $ids ), $draft['lines'][0]['variant_id'] );

		$form = $this->send( 'GET', '/admin/orders/product-form', null, [ 'product_id' => $product ] )->get_data();
		self::assertCount( 3, $form['variants'] );
		self::assertSame( 'variable', $form['type'] );
		self::assertSame( [], $form['options'] );
	}

	public function test_addresses_change_on_a_paid_order_and_the_details_email_goes_out(): void {
		$created = $this->send( 'POST', '/admin/orders', $this->draft( [ $this->simple( 1000 ) => 1 ], [ 'send_details' => true ] ) )->get_data();
		self::assertTrue( $created['details_sent'] );
		self::assertContains( 'Order details sent to the customer.', array_column( $created['history'], 'message' ) );
		$id = $created['id'];
		reset_phpmailer_instance();

		$sent = $this->send( 'POST', '/admin/orders/' . $id . '/details-email' );
		self::assertSame( 200, $sent->get_status(), (string) wp_json_encode( $sent->get_data() ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		self::assertNotFalse( $mail );
		self::assertSame( 'ada@example.com', $mail->to[0][0] );
		self::assertStringContainsString( 'order-pay=' . $id, $mail->body );
		self::assertContains( 'Order details sent to the customer.', array_column( $sent->get_data()['history'], 'message' ) );

		$this->orders()->record_payment( $id, 1500, TransitionContext::admin() );
		$moved = $this->send(
			'PUT',
			'/admin/orders/' . $id . '/addresses',
			[
				'billing'  => [ 'city' => 'Boston' ] + self::BILLING,
				'shipping' => [
					'first_name' => 'Ada',
					'address_1'  => '2 Main St',
					'city'       => 'Boston',
					'country'    => 'US',
				],
			]
		);
		self::assertSame( 200, $moved->get_status(), (string) wp_json_encode( $moved->get_data() ) );
		self::assertSame( 'Boston', $moved->get_data()['billing']['city'] );
		self::assertSame( '2 Main St', $moved->get_data()['shipping']['address_1'] );
		self::assertFalse( $moved->get_data()['editable'] );
		self::assertContains( 'Addresses changed by the admin.', array_column( $moved->get_data()['history'], 'message' ) );

		$bad = $this->send( 'PUT', '/admin/orders/' . $id . '/addresses', [ 'billing' => [ 'country' => 'ZZ' ] ] );
		self::assertSame( 400, $bad->get_status() );
	}

	public function test_the_routes_need_the_edit_orders_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'POST', '/admin/orders/quote', $this->draft( [] ) )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/orders', $this->draft( [] ) )->get_status() );
	}

	/**
	 * @param array<int,int>      $lines product id => quantity
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function draft( array $lines, array $extra = [] ): array {
		$out = [];
		foreach ( $lines as $product_id => $quantity ) {
			$out[] = [
				'product_id' => $product_id,
				'quantity'   => $quantity,
			];
		}
		return $extra + [
			'lines'         => $out,
			'billing'       => self::BILLING,
			'shipping_rate' => $this->rate_id,
		];
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,mixed>      $query
	 */
	private function send( string $method, string $path, ?array $body = null, array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_query_params( $query );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
