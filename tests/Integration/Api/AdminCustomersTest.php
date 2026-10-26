<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** The read-only customers list derived from orders. */
final class AdminCustomersTest extends OrderTestCase {
	private int $admin = 0;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin ); // Only super admins may edit users on a network.
		}
		wp_set_current_user( $this->admin );
	}

	/** Order writes commit mid-test, so the users made here are removed after the rollback. */
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_multisite() ) {
			revoke_super_admin( $this->admin );
		}
		foreach ( [ 'grace@example.com', 'grace2@example.com', 'grace3@example.com' ] as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof \WP_User ) {
				wp_delete_user( $user->ID );
			}
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_users_and_guests_are_grouped_with_their_totals(): void {
		$user = self::factory()->user->create(
			[
				'user_email'   => 'grace@example.com',
				'display_name' => 'Grace H',
			]
		);
		$this->order( 'grace-old@example.com', 'Grace Hopper', $user, 1000, 0, '2026-01-01 10:00:00' );
		$this->order( 'grace@example.com', 'Grace Hopper', $user, 2500, 500, '2026-03-01 10:00:00' );
		$this->order( 'bob@example.com', 'Bob Guest', 0, 4000, 0, '2026-02-01 10:00:00' );
		$this->order( 'bob@example.com', 'Bob Guest', 0, 0, 0, '2026-02-02 10:00:00' );
		$gone = $this->order( 'erased@example.com', 'Gone', 0, 100, 0, '2026-02-03 10:00:00' );
		Plugin::instance()->orders()->service()->anonymize( $gone, TransitionContext::admin() );

		$response = $this->send( [] );
		self::assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$items = $response->get_data();
		self::assertSame( [ $user, 0 ], array_column( $items, 'customer_id' ) );

		self::assertTrue( $items[0]['registered'] );
		self::assertSame( 'grace@example.com', $items[0]['email'] );
		self::assertSame( 'Grace Hopper', $items[0]['name'] );
		self::assertSame( 2, $items[0]['orders'] );
		self::assertSame( 3000, $items[0]['spent'] );
		self::assertSame( '2026-01-01T10:00:00Z', $items[0]['first_order'] );
		self::assertSame( '2026-03-01T10:00:00Z', $items[0]['last_order'] );
		self::assertStringContainsString( 'user-edit.php?user_id=' . $user, $items[0]['edit_url'] );

		self::assertFalse( $items[1]['registered'] );
		self::assertSame( 'bob@example.com', $items[1]['email'] );
		self::assertSame( 2, $items[1]['orders'] );
		self::assertSame( 4000, $items[1]['spent'] );
		self::assertSame( '', $items[1]['edit_url'] );
	}

	public function test_filters_search_sort_and_pages(): void {
		$user = self::factory()->user->create( [ 'user_email' => 'grace2@example.com' ] );
		$this->order( 'grace2@example.com', 'Grace Hopper', $user, 1000, 0, '2026-01-01 10:00:00' );
		$this->order( 'bob@example.com', 'Bob Guest', 0, 4000, 0, '2026-02-01 10:00:00' );
		$this->order( 'carol@example.com', 'Carol Guest', 0, 200, 0, '2026-03-01 10:00:00' );

		self::assertSame( [ 'grace2@example.com' ], array_column( $this->send( [ 'type' => 'registered' ] )->get_data(), 'email' ) );
		self::assertSame( [ 'carol@example.com', 'bob@example.com' ], array_column( $this->send( [ 'type' => 'guest' ] )->get_data(), 'email' ) );
		self::assertSame( [ 'grace2@example.com' ], array_column( $this->send( [ 'search' => 'hopper' ] )->get_data(), 'email' ) );
		self::assertSame( [ 'bob@example.com' ], array_column( $this->send( [ 'search' => 'BOB@' ] )->get_data(), 'email' ) );
		self::assertSame(
			[ 'bob@example.com', 'grace2@example.com', 'carol@example.com' ],
			array_column( $this->send( [ 'orderby' => 'spent' ] )->get_data(), 'email' )
		);

		$page = $this->send(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);
		self::assertSame( '2', $page->get_headers()['X-WP-TotalPages'] );
		self::assertSame( [ 'grace2@example.com' ], array_column( $page->get_data(), 'email' ) );
	}

	public function test_the_list_needs_the_view_orders_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( [] )->get_status() );
	}

	public function test_a_store_manager_marks_an_account_tax_exempt(): void {
		$user = self::factory()->user->create( [ 'user_email' => 'grace3@example.com' ] );
		$this->order( 'grace3@example.com', 'Grace Hopper', $user, 1000, 0, '2026-03-01 10:00:00' );
		$this->order( 'bob@example.com', 'Bob Guest', 0, 4000, 0, '2026-02-01 10:00:00' );

		$items = $this->send( [] )->get_data();
		self::assertSame( [ false, false ], array_column( $items, 'tax_exempt' ) );
		self::assertSame( [ true, false ], array_column( $items, 'can_exempt' ), 'Guests have no account to flag.' );

		$response = $this->patch( $user, true );
		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['tax_exempt'] );
		self::assertTrue( ( new CustomerProfile() )->tax_exempt( $user ) );
		self::assertSame( [ true, false ], array_column( $this->send( [] )->get_data(), 'tax_exempt' ) );

		self::assertFalse( $this->patch( $user, false )->get_data()['tax_exempt'] );
		self::assertFalse( ( new CustomerProfile() )->tax_exempt( $user ) );
		self::assertSame( 404, $this->patch( 999999, true )->get_status() );
	}

	public function test_only_a_store_manager_changes_the_flag(): void {
		$user    = self::factory()->user->create();
		$viewer  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$granted = get_userdata( $viewer );
		self::assertInstanceOf( \WP_User::class, $granted );
		$granted->add_cap( 'view_fxc_orders' );
		wp_set_current_user( $viewer );

		self::assertSame( 403, $this->patch( $user, true )->get_status() );
		self::assertFalse( ( new CustomerProfile() )->tax_exempt( $user ) );
	}

	private function patch( int $user, bool $exempt ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'PATCH', '/flexacommerce/v1/admin/customers/' . $user );
		$request->set_body_params( [ 'tax_exempt' => $exempt ] );
		return rest_get_server()->dispatch( $request );
	}

	/** Places an order and rewrites the customer columns the list reads. */
	private function order( string $email, string $name, int $user, int $paid, int $refunded, string $created ): int {
		$order = $this->place( [ $this->simple( 5000 ) => 1 ] );
		Plugin::instance()->orders()->orders()->update(
			$order->id,
			[
				'customer_email'  => $email,
				'customer_name'   => $name,
				'customer_id'     => $user > 0 ? $user : null,
				'amount_paid'     => $paid,
				'amount_refunded' => $refunded,
				'created_at'      => $created,
			]
		);
		return $order->id;
	}

	/** @param array<string,mixed> $query */
	private function send( array $query ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/customers' );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}
}
