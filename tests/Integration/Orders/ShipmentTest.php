<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Orders;

use Flexa\Commerce\Cli\ShipmentCommand;
use Flexa\Commerce\Contracts\Exceptions\InvalidOrderRequest;
use Flexa\Commerce\Contracts\Exceptions\OrderNotFound;
use Flexa\Commerce\Notifications\EmailSettings;
use Flexa\Commerce\Orders\Carriers;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Orders\ShipmentService;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\OrderConfirmation;

/** Packages with tracking numbers and the shipped email (ADR-067). */
final class ShipmentTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		global $wpdb;
		remove_all_filters( 'flexacommerce.shipment.carriers' );
		Carriers::reset();
		update_option( 'timezone_string', '' );
		parent::tear_down();
		foreach ( Plugin::instance()->notifications()->emails() as $email ) {
			delete_option( EmailSettings::PREFIX . $email->id() );
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fxc\\_mail\\_%'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_a_known_carrier_builds_the_link_and_the_history_says_so(): void {
		$order = $this->place( [ $this->simple( 1000 ) => 2 ], 0, null, S::PROCESSING );
		reset_phpmailer_instance();
		$shipment = $this->shipments()->add( $order->id, 'ups', '', ' 1Z 999 AA1 ', '', null, '', false, false, TransitionContext::admin() );

		self::assertSame( [ 'ups', 'UPS', '1Z999AA1' ], [ $shipment->carrier, $shipment->carrier_name, $shipment->tracking_number ] );
		self::assertSame( 'https://www.ups.com/track?tracknum=1Z999AA1', $shipment->tracking_url );
		self::assertNull( $shipment->items, 'no lines chosen is the whole order' );
		self::assertSame( S::PROCESSING, $this->status( $order->id ), 'the order keeps its status' );
		$notes = array_filter( Plugin::instance()->orders()->history()->for_order( $order->id ), static fn ( array $row ): bool => $row['type'] === 'shipment' );
		self::assertSame( 'Shipped with UPS, tracking number 1Z999AA1.', array_values( $notes )[0]['message'] );
		self::assertSame( [], $this->sent(), 'no email unless asked' );
	}

	public function test_another_carrier_needs_a_name_and_a_web_link(): void {
		$order    = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		$shipment = $this->shipments()->add( $order->id, '', 'Local Courier', 'AB 12', 'https://courier.example/t/{number}', null, '', false, false, TransitionContext::admin() );
		self::assertSame( [ '', 'Local Courier', 'https://courier.example/t/AB12' ], [ $shipment->carrier, $shipment->carrier_name, $shipment->tracking_url ] );

		$this->assertRejected( 'carrier', fn () => $this->shipments()->add( $order->id, '', '', 'X1', '', null, '', false, false, TransitionContext::admin() ) );
		$this->assertRejected( 'tracking_url', fn () => $this->shipments()->add( $order->id, '', 'Courier', 'X1', 'javascript:alert(1)', null, '', false, false, TransitionContext::admin() ) );
		$this->assertRejected( 'tracking_number', fn () => $this->shipments()->add( $order->id, 'ups', '', str_repeat( '9', 101 ), '', null, '', false, false, TransitionContext::admin() ) );
	}

	public function test_a_filter_adds_a_carrier(): void {
		add_filter(
			'flexacommerce.shipment.carriers',
			static fn ( array $carriers ): array => $carriers + [
				'ghn' => [
					'name' => 'GHN',
					'url'  => 'https://ghn.example/track/%s',
				],
			]
		);
		Carriers::reset();
		$order    = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		$shipment = $this->shipments()->add( $order->id, 'ghn', '', 'G 1', '', null, '', false, false, TransitionContext::admin() );
		self::assertSame( [ 'GHN', 'https://ghn.example/track/G1' ], [ $shipment->carrier_name, $shipment->tracking_url ] );
	}

	public function test_part_of_the_order_and_the_checks_on_lines_status_and_date(): void {
		$a      = $this->simple( 1000 );
		$b      = $this->simple( 2000 );
		$order  = $this->place(
			[
				$a => 2,
				$b => 1,
			],
			0,
			null,
			S::PROCESSING
		);
		$first  = $order->items[0]->id;
		$second = $order->items[1]->id;

		$part = $this->shipments()->add( $order->id, 'usps', '', '9400', '', [ $first => 1 ], '', false, false, TransitionContext::admin() );
		self::assertSame( [ $first => 1 ], $part->items );
		$whole = $this->shipments()->add(
			$order->id,
			'usps',
			'',
			'9401',
			'',
			[
				$second => 1,
				$first  => 2,
			],
			'',
			false,
			false,
			TransitionContext::admin()
		);
		self::assertNull( $whole->items, 'every line in full is the whole order' );

		$this->assertRejected( 'items', fn () => $this->shipments()->add( $order->id, 'usps', '', '1', '', [ $first => 3 ], '', false, false, TransitionContext::admin() ) );
		$this->assertRejected( 'items', fn () => $this->shipments()->add( $order->id, 'usps', '', '1', '', [ 999999 => 1 ], '', false, false, TransitionContext::admin() ) );
		$this->assertRejected( 'items', fn () => $this->shipments()->add( $order->id, 'usps', '', '1', '', [ $first => 0 ], '', false, false, TransitionContext::admin() ) );
		$this->assertRejected( 'shipped_at', fn () => $this->shipments()->add( $order->id, 'usps', '', '1', '', null, '2999-01-01', false, false, TransitionContext::admin() ) );
		$this->assertRejected( 'shipped_at', fn () => $this->shipments()->add( $order->id, 'usps', '', '1', '', null, '2026-02-30', false, false, TransitionContext::admin() ) );

		$pending = $this->place( [ $a => 1 ] );
		$this->assertRejected( 'status', fn () => $this->shipments()->add( $pending->id, 'usps', '', '1', '', null, '', false, false, TransitionContext::admin() ) );
		self::assertCount( 2, $this->shipments()->for_order( $order->id ) );
	}

	public function test_a_past_day_is_noon_in_the_shop_time_zone(): void {
		update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
		$order    = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		$shipment = $this->shipments()->add( $order->id, 'fedex', '', '7777', '', null, '2026-01-15', false, false, TransitionContext::admin() );
		self::assertSame( '2026-01-15 05:00:00', $shipment->shipped_at );
	}

	public function test_completing_sends_the_shipped_email_instead_of_the_completed_one(): void {
		$order = $this->place( [ $this->simple( 1500 ) => 1 ], 0, null, S::PROCESSING );
		reset_phpmailer_instance();

		$this->shipments()->add( $order->id, 'dhl', '', 'JD0001', '', null, '', true, true, TransitionContext::admin() );
		self::assertSame( S::COMPLETED, $this->status( $order->id ) );
		$sent = $this->sent();
		self::assertCount( 1, $sent, (string) wp_json_encode( array_column( $sent, 'subject' ) ) );
		self::assertSame( 'ada@example.com', $sent[0]['to'] );
		self::assertStringContainsString( 'order #' . $order->order_number . ' has shipped', $sent[0]['subject'] );
		self::assertStringContainsString( 'JD0001', $sent[0]['body'] );
		self::assertStringContainsString( 'DHL Express', $sent[0]['body'] );
		self::assertStringContainsString( 'tracking-id=JD0001', $sent[0]['body'] );

		// A second package on the completed order emails only that package.
		reset_phpmailer_instance();
		$this->shipments()->add( $order->id, 'usps', '', '9405', '', null, '', true, true, TransitionContext::admin() );
		$sent = $this->sent();
		self::assertCount( 1, $sent );
		self::assertStringContainsString( '9405', $sent[0]['body'] );
		self::assertStringNotContainsString( 'JD0001', $sent[0]['body'] );

		// Completing on its own still sends the completed email, with all the tracking.
		$other = $this->place( [ $this->simple( 1500 ) => 1 ], 0, null, S::PROCESSING );
		$this->shipments()->add( $other->id, 'ups', '', '1ZA', '', null, '', false, false, TransitionContext::admin() );
		reset_phpmailer_instance();
		$this->orders()->transition( $other->id, S::COMPLETED, TransitionContext::admin() );
		$sent = $this->sent();
		self::assertCount( 1, $sent );
		self::assertStringContainsString( 'is complete', $sent[0]['subject'] );
		self::assertStringContainsString( '1ZA', $sent[0]['body'] );
	}

	public function test_the_customer_order_page_lists_the_tracking(): void {
		$order = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		self::assertSame( '', OrderConfirmation::shipments( $order ) );
		$this->shipments()->add( $order->id, 'royal_mail', '', 'RM1', '', null, '', false, false, TransitionContext::admin() );

		$html = OrderConfirmation::shipments( Plugin::instance()->orders()->orders()->find( $order->id ) );
		self::assertStringContainsString( 'Royal Mail', $html );
		self::assertStringContainsString( 'href="https://www.royalmail.com/track-your-item#/tracking-results/RM1"', $html );
		self::assertStringContainsString( 'rel="noopener noreferrer"', $html );
	}

	public function test_delete_checks_the_order_and_search_finds_the_number(): void {
		$order    = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		$other    = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		$shipment = $this->shipments()->add( $order->id, 'ups', '', '1ZFIND', '', null, '', false, false, TransitionContext::admin() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$found = $this->send( 'GET', '/admin/orders', null, [ 'search' => '1Z FIND' ] );
		self::assertSame( [ $order->id ], array_column( $found->get_data(), 'id' ) );

		try {
			$this->shipments()->delete( $other->id, $shipment->id, TransitionContext::admin() );
			self::fail( 'A shipment of another order cannot be deleted through this one.' );
		} catch ( OrderNotFound ) {
			self::assertCount( 1, $this->shipments()->for_order( $order->id ) );
		}
		$this->shipments()->delete( $order->id, $shipment->id, TransitionContext::admin() );
		self::assertSame( [], $this->shipments()->for_order( $order->id ) );
	}

	public function test_rest_routes_add_list_and_remove(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$order = $this->place( [ $this->simple( 1000 ) => 2 ], 0, null, S::PROCESSING );
		$line  = $order->items[0]->id;
		reset_phpmailer_instance();

		$carriers = $this->send( 'GET', '/admin/shipment-carriers' )->get_data();
		self::assertContains( 'ups', array_column( $carriers['carriers'], 'key' ) );
		self::assertNull( $carriers['last'] );

		$added = $this->send(
			'POST',
			'/admin/orders/' . $order->id . '/shipments',
			[
				'carrier'         => 'fedex',
				'tracking_number' => '7489',
				'items'           => [ (string) $line => 1 ],
				'complete'        => true,
				'notify'          => false,
			]
		);
		self::assertSame( 201, $added->get_status(), (string) wp_json_encode( $added->get_data() ) );
		$data = $added->get_data();
		self::assertSame( S::COMPLETED, $data['status'] );
		self::assertSame( [ 'fedex', '7489' ], [ $data['shipments'][0]['carrier'], $data['shipments'][0]['tracking_number'] ] );
		self::assertSame( [ (string) $line => 1 ], (array) $data['shipments'][0]['items'] );
		self::assertStringEndsWith( 'Z', $data['shipments'][0]['shipped_at'] );
		self::assertSame( [], $this->sent(), 'notify off sends nothing, and the completed email stays quiet' );
		self::assertSame( 'fedex', $this->send( 'GET', '/admin/shipment-carriers' )->get_data()['last']['carrier'] );

		$bad = $this->send(
			'POST',
			'/admin/orders/' . $order->id . '/shipments',
			[
				'carrier'    => 'ups',
				'shipped_at' => '2999-01-01',
			]
		);
		self::assertSame( 400, $bad->get_status() );

		$removed = $this->send( 'DELETE', '/admin/orders/' . $order->id . '/shipments/' . $data['shipments'][0]['id'] );
		self::assertSame( 200, $removed->get_status() );
		self::assertSame( [], $removed->get_data()['shipments'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'POST', '/admin/orders/' . $order->id . '/shipments', [ 'carrier' => 'ups' ] )->get_status() );
	}

	public function test_the_cli_row_reads_in_one_line(): void {
		$order    = $this->place( [ $this->simple( 1000 ) => 2 ], 0, null, S::PROCESSING );
		$line     = $order->items[0]->id;
		$shipment = $this->shipments()->add( $order->id, 'ups', '', '1ZCLI', '', [ $line => 1 ], '', false, false, new TransitionContext( 'cli' ) );
		$row      = ShipmentCommand::row( $shipment );
		self::assertSame( ShipmentCommand::FIELDS, array_keys( $row ) );
		self::assertSame( 'UPS', $row['carrier'] );
		self::assertSame( $line . ':1', $row['items'] );
	}

	private function shipments(): ShipmentService {
		return Plugin::instance()->orders()->shipment_service();
	}

	private function status( int $order_id ): string {
		return (string) Plugin::instance()->orders()->orders()->find( $order_id )?->status;
	}

	private function assertRejected( string $field, callable $call ): void {
		try {
			$call();
			self::fail( 'Expected the ' . $field . ' to be rejected.' );
		} catch ( InvalidOrderRequest $e ) {
			self::assertSame( $field, $e->field, $e->getMessage() );
		}
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null, array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_query_params( $query );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/** @return list<array{to:string,subject:string,body:string}> */
	private function sent(): array {
		$out = [];
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$out[] = [
				'to'      => (string) $mail['to'][0][0],
				'subject' => (string) $mail['subject'],
				'body'    => (string) $mail['body'],
			];
		}
		return $out;
	}
}
