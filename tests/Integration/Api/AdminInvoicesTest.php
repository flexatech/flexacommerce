<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Invoices\InvoiceSettings;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** The invoice routes behind Settings > Invoices and the order page card. */
final class AdminInvoicesTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Plugin::instance()->invoices()->reset();
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		delete_option( InvoiceSettings::OPTION );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE name LIKE %s', Schema::table( 'sequences' ), 'invoice\\_number%' ) ); // phpcs:ignore WordPress.DB
		Plugin::instance()->invoices()->reset();
		wp_cache_flush();
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_the_settings_merge_partial_saves(): void {
		$data = $this->send( 'GET', '/admin/invoices/settings' )->get_data();
		self::assertSame( 'INV-', $data['settings']['prefix'] );
		self::assertSame( 1, $data['next_number'] );
		self::assertSame( 'INV-0001', $data['next_formatted'] );
		self::assertContains( 'customer_processing_order', array_column( $data['emails'], 'id' ) );

		$this->send( 'PATCH', '/admin/invoices/settings', [ 'prefix' => 'F-' ] );
		$saved = $this->send(
			'PATCH',
			'/admin/invoices/settings',
			[
				'padding'     => 6,
				'next_number' => 42,
			]
		);
		self::assertSame( 200, $saved->get_status() );
		self::assertSame( 'F-', $saved->get_data()['settings']['prefix'] );
		self::assertSame( 6, $saved->get_data()['settings']['padding'] );
		self::assertSame( 42, $saved->get_data()['next_number'] );
		self::assertSame( 'F-000042', $saved->get_data()['next_formatted'] );

		$lower = $this->send( 'PATCH', '/admin/invoices/settings', [ 'next_number' => 10 ] );
		self::assertSame( 400, $lower->get_status() );
		self::assertSame( 'flexacommerce_invoice_number_lower', $lower->get_data()['code'] );

		self::assertSame( 400, $this->send( 'PATCH', '/admin/invoices/settings', [ 'logo_id' => 999999 ] )->get_status() );
	}

	public function test_an_order_invoice_is_assigned_from_the_order_page(): void {
		$order = $this->place( [ $this->simple( 1500 ) => 1 ], 0, null, S::PROCESSING );
		$path  = '/admin/orders/' . $order->id . '/invoice';

		$before = $this->send( 'GET', $path )->get_data();
		self::assertNull( $before['invoice'] );
		self::assertTrue( $before['customer_can'] );
		self::assertStringContainsString( 'key=' . $order->order_key, $before['customer_url'] );

		$created = $this->send( 'POST', $path );
		self::assertSame( 201, $created->get_status() );
		self::assertSame( 'INV-0001', $created->get_data()['invoice']['number'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $created->get_data()['invoice']['date'] );
		self::assertSame( 'INV-0001', $this->send( 'POST', $path )->get_data()['invoice']['number'] );
		self::assertSame( 404, $this->send( 'GET', '/admin/orders/999999/invoice' )->get_status() );
	}

	public function test_the_routes_need_their_capabilities(): void {
		$order = $this->place( [ $this->simple( 1500 ) => 1 ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'GET', '/admin/invoices/settings' )->get_status() );
		self::assertSame( 403, $this->send( 'PATCH', '/admin/invoices/settings', [ 'prefix' => 'X' ] )->get_status() );
		self::assertSame( 403, $this->send( 'GET', '/admin/orders/' . $order->id . '/invoice' )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/orders/' . $order->id . '/invoice' )->get_status() );
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
