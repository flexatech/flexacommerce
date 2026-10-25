<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\FieldValues;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * The admin order, gateway and shipping zone routes the React screens use.
 */
final class AdminOrdersTest extends OrderTestCase {
	private \WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		delete_option( GatewaySettings::PREFIX . 'bacs' );
		delete_option( ZoneRepository::OPTION );
		( new \ReflectionProperty( Plugin::class, 'shipping' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'payments' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
		parent::tear_down();
	}

	public function test_list_filters_and_counts(): void {
		$a       = $this->simple( 1000, 10 );
		$pending = $this->place( [ $a => 1 ] );
		$held    = $this->place( [ $a => 2 ], 0, null, OrderStatus::ON_HOLD, 'bacs' );
		Plugin::instance()->orders()->orders()->set_needs_attention( $held->id, true, gmdate( 'Y-m-d H:i:s' ) );

		$all = $this->send( 'GET', '/admin/orders' );
		self::assertSame( 200, $all->get_status() );
		self::assertSame( '2', $all->get_headers()['X-WP-Total'] );
		self::assertSame( [ $held->id, $pending->id ], array_column( $all->get_data(), 'id' ) );
		self::assertSame( 'ada@example.com', $all->get_data()[0]['customer']['email'] );
		self::assertStringEndsWith( 'Z', $all->get_data()[0]['created_at'] );

		$filtered = $this->send( 'GET', '/admin/orders', null, [ 'needs_attention' => 'true' ] );
		self::assertSame( [ $held->id ], array_column( $filtered->get_data(), 'id' ) );
		$by_status = $this->send( 'GET', '/admin/orders', null, [ 'status' => OrderStatus::PENDING ] );
		self::assertSame( [ $pending->id ], array_column( $by_status->get_data(), 'id' ) );
		$by_number = $this->send( 'GET', '/admin/orders', null, [ 'search' => '#' . $pending->order_number ] );
		self::assertSame( [ $pending->id ], array_column( $by_number->get_data(), 'id' ) );

		$counts = $this->send( 'GET', '/admin/orders/counts' )->get_data();
		self::assertSame( 2, $counts['all'] );
		self::assertSame( 1, $counts['status'][ OrderStatus::ON_HOLD ] );
		self::assertSame( 0, $counts['status'][ OrderStatus::COMPLETED ] );
		self::assertSame( 1, $counts['needs_attention'] );
	}

	public function test_detail_lists_lines_history_transitions_and_links(): void {
		$order = $this->place( [ $this->simple( 1000, 5 ) => 2 ], 500 );

		$response = $this->send( 'GET', '/admin/orders/' . $order->id );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 2500, $data['total'] );
		self::assertSame( 2500, $data['balance_due'] );
		self::assertSame( [ 'product', 'shipping' ], array_column( $data['items'], 'type' ) );
		self::assertTrue( $data['items'][0]['stock_managed'] );
		self::assertSame( 'Ada', $data['billing']['first_name'] );
		self::assertNull( $data['shipping'] );
		self::assertSame( [ OrderStatus::ON_HOLD, OrderStatus::PROCESSING, OrderStatus::CANCELLED, OrderStatus::FAILED ], $data['transitions'] );
		self::assertNotEmpty( $data['history'] );
		self::assertStringContainsString( 'order-received=' . $order->id, $data['links']['view'] );
		self::assertStringContainsString( 'order-pay=' . $order->id, $data['links']['pay'] );
		self::assertTrue( $data['can']['refund'] );

		self::assertSame( 404, $this->send( 'GET', '/admin/orders/999999' )->get_status() );
	}

	public function test_admin_actions_go_through_the_state_machine(): void {
		$product = $this->simple( 1000, 5 );
		$order   = $this->place( [ $product => 1 ] );

		$note = $this->send(
			'POST',
			'/admin/orders/' . $order->id . '/notes',
			[
				'note'             => 'Called the customer',
				'customer_visible' => true,
			]
		);
		self::assertSame( 201, $note->get_status() );
		self::assertSame( 'Called the customer', $note->get_data()['history'][0]['message'] );
		self::assertTrue( $note->get_data()['history'][0]['customer_visible'] );
		self::assertNotSame( '', $note->get_data()['history'][0]['actor_name'] );

		$paid = $this->send(
			'POST',
			'/admin/orders/' . $order->id . '/payments',
			[
				'amount' => 1000,
				'note'   => 'Cash at the door',
			]
		);
		self::assertSame( 201, $paid->get_status() );
		self::assertSame( OrderStatus::PROCESSING, $paid->get_data()['status'] );
		self::assertSame( 'manual', $paid->get_data()['transactions'][0]['method'] );

		$item   = $paid->get_data()['items'][0];
		$refund = $this->send(
			'POST',
			'/admin/orders/' . $order->id . '/refunds',
			[
				'amount' => 1000,
				'reason' => 'Broken',
				'lines'  => [
					[
						'item_id' => $item['id'],
						'qty'     => 1,
						'amount'  => 1000,
						'restock' => true,
					],
				],
			]
		);
		self::assertSame( 201, $refund->get_status(), (string) wp_json_encode( $refund->get_data() ) );
		self::assertSame( 1000, $refund->get_data()['amount_refunded'] );
		self::assertSame( 5, $this->stock_of( $product ) );

		$too_much = $this->send( 'POST', '/admin/orders/' . $order->id . '/refunds', [ 'amount' => 1 ] );
		self::assertSame( 400, $too_much->get_status() );

		$bad = $this->send( 'POST', '/admin/orders/' . $order->id . '/transitions', [ 'status' => OrderStatus::PENDING ] );
		self::assertSame( 409, $bad->get_status() );
		self::assertSame( 'flexacommerce_invalid_transition', $bad->as_error()->get_error_code() );
	}

	public function test_cancel_releases_stock_and_attention_can_be_cleared(): void {
		$product = $this->simple( 1000, 5 );
		$order   = $this->place( [ $product => 2 ] );
		Plugin::instance()->orders()->orders()->set_needs_attention( $order->id, true, gmdate( 'Y-m-d H:i:s' ) );

		$cancel = $this->send(
			'POST',
			'/admin/orders/' . $order->id . '/transitions',
			[
				'status' => OrderStatus::CANCELLED,
				'note'   => 'Customer asked',
			]
		);
		self::assertSame( 200, $cancel->get_status() );
		self::assertSame( OrderStatus::CANCELLED, $cancel->get_data()['status'] );
		self::assertSame( [], $cancel->get_data()['transitions'] );
		self::assertSame( 5, $this->stock_of( $product ) );

		$cleared = $this->send( 'DELETE', '/admin/orders/' . $order->id . '/attention' );
		self::assertFalse( $cleared->get_data()['needs_attention'] );
	}

	public function test_order_routes_check_the_order_capabilities(): void {
		$order = $this->place( [ $this->simple( 1000, 5 ) => 1 ] );
		$user  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $user )->add_cap( 'view_fxc_orders' );
		wp_set_current_user( $user );

		self::assertSame( 200, $this->send( 'GET', '/admin/orders/' . $order->id )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/orders/' . $order->id . '/notes', [ 'note' => 'x' ] )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/orders/' . $order->id . '/refunds', [ 'amount' => 1 ] )->get_status() );
		self::assertSame( 403, $this->send( 'GET', '/admin/payments/gateways' )->get_status() );

		wp_set_current_user( 0 );
		self::assertSame( 401, $this->send( 'GET', '/admin/orders' )->get_status() );
	}

	public function test_gateway_settings_mask_secrets_and_keep_them_on_save(): void {
		$list = $this->send( 'GET', '/admin/payments/gateways' );
		self::assertSame( 200, $list->get_status() );
		self::assertContains( 'bacs', array_column( $list->get_data(), 'id' ) );

		$saved = $this->send(
			'PATCH',
			'/admin/payments/gateways/bacs',
			[
				'values' => [
					'enabled'        => true,
					'account_number' => '12345678',
				],
			]
		);
		self::assertSame( 200, $saved->get_status() );
		self::assertTrue( $saved->get_data()['enabled'] );
		self::assertSame( '12345678', $saved->get_data()['values']['account_number'] );

		self::assertSame( 404, $this->send( 'PATCH', '/admin/payments/gateways/nope', [ 'values' => [] ] )->get_status() );
	}

	public function test_secret_fields_never_leave_in_clear(): void {
		$fields = [ new \Flexa\Commerce\Contracts\SettingField( 'api_key', 'secret', 'Key' ) ];
		$masked = FieldValues::masked( $fields, [ 'api_key' => 'sk_live_abcd1234' ] );
		self::assertSame( FieldValues::MASK . '1234', $masked['api_key'] );
		$kept = FieldValues::sanitize( $fields, [ 'api_key' => $masked['api_key'] ], [ 'api_key' => 'sk_live_abcd1234' ] );
		self::assertSame( 'sk_live_abcd1234', $kept['api_key'] );
	}

	public function test_shipping_zones_save_in_order_with_sanitized_settings(): void {
		$initial = $this->send( 'GET', '/admin/shipping/zones' )->get_data();
		self::assertSame( [ 0 ], array_column( $initial['zones'], 'id' ) );
		self::assertContains( 'flat_rate', array_column( $initial['methods'], 'type' ) );
		$us = array_values( array_filter( $initial['countries'], static fn ( array $c ): bool => $c['code'] === 'US' ) );
		self::assertContains( 'CA', array_column( $us[0]['states'], 'code' ) );

		$saved = $this->send(
			'PUT',
			'/admin/shipping/zones',
			[
				'zones' => [
					[
						'id'        => 0,
						'name'      => 'California',
						'regions'   => [ 'state:us:ca' ],
						'postcodes' => [ '90*' ],
						'methods'   => [
							[
								'type'     => 'flat_rate',
								'settings' => [
									'cost'  => '700',
									'title' => '<b>Ground</b>',
									'junk'  => 'x',
								],
							],
						],
					],
					[
						'id'      => 0,
						'name'    => 'Everywhere else',
						'methods' => [],
					],
				],
			]
		);
		self::assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$zones = $saved->get_data()['zones'];
		self::assertSame( [ 'California', 'Everywhere else' ], array_column( $zones, 'name' ) );
		self::assertSame( [ 'state:US:CA' ], $zones[0]['regions'] );
		$method = $zones[0]['methods'][0];
		self::assertGreaterThan( 0, $method['instance_id'] );
		self::assertSame( 700, $method['settings']['cost'] );
		self::assertSame( 'Ground', $method['settings']['title'] );
		self::assertArrayNotHasKey( 'junk', $method['settings'] );

		$invalid = $this->send(
			'PUT',
			'/admin/shipping/zones',
			[
				'zones' => [
					[
						'name'    => 'Nowhere',
						'regions' => [ 'country:ZZ' ],
					],
				],
			]
		);
		self::assertSame( 400, $invalid->get_status() );
		self::assertSame( 'zones.0.regions', $invalid->get_data()['data']['field'] );

		// A new zone without regions is not mistaken for "everywhere else" unless it is the last row.
		$unnamed = $this->send(
			'PUT',
			'/admin/shipping/zones',
			[
				'zones' => [
					[ 'id' => 0 ],
					[ 'id' => 0 ],
				],
			]
		);
		self::assertSame( 400, $unnamed->get_status() );
		self::assertSame( 'zones.0.name', $unnamed->get_data()['data']['field'] );
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
