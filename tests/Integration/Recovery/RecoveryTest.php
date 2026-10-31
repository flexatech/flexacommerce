<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Recovery;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Privacy\Privacy;
use Flexa\Commerce\Recovery\Recovery;
use Flexa\Commerce\Recovery\RecoveryService;
use Flexa\Commerce\Recovery\RecoverySettings;
use Flexa\Commerce\Storefront\RecoveryHandler;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * Abandoned cart recovery (ADR-063). Each shopper is a browser with its own
 * cookie, swapped into the plugin so the recovery service works on it. Time
 * moves by ageing the session row.
 */
final class RecoveryTest extends OrderTestCase {
	private MemoryTokenStore $cookie;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		reset_phpmailer_instance();
		$this->shopper();
	}

	public function tear_down(): void {
		global $wpdb;
		$this->swap( null );
		parent::tear_down();
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'recoveries' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'coupons' ) ); // phpcs:ignore WordPress.DB
		delete_option( RecoverySettings::OPTION );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_a_quiet_guest_cart_gets_a_reminder_whose_link_restores_it(): void {
		$this->enable( [ [ 'coupon_type' => 'percent', 'coupon_amount' => 10, 'coupon_days' => 3 ] ] );
		$product = $this->simple( 2500 );
		$this->cart()->add( 0, $product, $product, 2 );
		self::assertTrue( $this->recovery()->capture( 0, 'ada@example.com' ) );

		$this->recovery()->run();
		self::assertNull( $this->row(), 'A cart in use is left alone.' );

		$this->age( 120 );
		$this->recovery()->run();
		$row = $this->row();
		self::assertNotNull( $row );
		self::assertSame( 'ada@example.com', $row->email );
		self::assertSame( 5000, $row->total );
		self::assertSame( 2, $row->item_count );
		self::assertSame( 1, $row->emails_sent );
		self::assertSame( 1, $row->reminder );
		self::assertNull( $row->next_send_at, 'Only the first reminder is on.' );
		self::assertMatchesRegularExpression( '/^CART-[A-F0-9]{8}$/', $row->coupon_code );

		$mail = $this->mails();
		self::assertCount( 1, $mail );
		self::assertSame( 'ada@example.com', $mail[0]['to'] );
		self::assertSame( 'You left something in your cart', $mail[0]['subject'] );
		self::assertStringContainsString( $row->coupon_code, $mail[0]['body'] );
		self::assertStringContainsString( '10%', $mail[0]['body'] );
		self::assertStringContainsString( RecoveryService::RECOVER_PARAM . '=' . $row->key(), $mail[0]['body'] );
		self::assertStringContainsString( RecoveryService::STOP_PARAM . '=' . $row->key(), $mail[0]['body'] );

		$code   = Coupon::normalize( $row->coupon_code );
		$coupon = Plugin::instance()->coupons()->repository()->by_codes( [ $code ] )[ $code ];
		self::assertNotNull( $coupon );
		self::assertSame( 1, $coupon->usage_limit );
		self::assertSame( [ 'ada@example.com' ], $coupon->emails );

		// Another run sends nothing more.
		$this->recovery()->run();
		self::assertCount( 1, $this->mails() );

		// The link opened in another browser fills its cart with the copy and the code.
		$this->shopper();
		self::assertFalse( $this->recovery()->recover( $row->id . '.' . str_repeat( 'a', 32 ), 0 ) );
		self::assertTrue( $this->recovery()->recover( $row->key(), 0 ) );
		$cart = $this->cart()->saved( 0 );
		self::assertSame( 2, $cart->item_count() );
		self::assertContains( $code, $cart->coupons );
		self::assertNotNull( $this->row()?->clicked_at );
		self::assertSame( 'ada@example.com', $this->sessions()->current( 0 )?->email );
	}

	public function test_an_order_after_a_reminder_counts_as_recovered(): void {
		$this->enable( [ [], [ 'enabled' => true ] ] );
		$product = $this->simple( 1500 );
		$this->cart()->add( 0, $product, $product, 1 );
		$this->recovery()->capture( 0, 'ada@example.com' );
		$this->age( 120 );
		$this->recovery()->run();
		$row = $this->row();
		self::assertNotNull( $row );
		self::assertSame( 1, $row->emails_sent );
		self::assertNotNull( $row->next_send_at, 'The second reminder waits.' );

		$order = $this->place( [ $product => 1 ], 0, null, S::PROCESSING );
		reset_phpmailer_instance();
		$this->due( $row->id );
		$this->recovery()->run();

		$row = $this->row();
		self::assertNotNull( $row );
		self::assertSame( Recovery::RECOVERED, $row->status );
		self::assertSame( $order->id, $row->order_id );
		self::assertSame( [], $this->mails(), 'No reminder after the order.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$list = $this->send( 'GET', '/admin/recovery' )->get_data();
		self::assertSame( 1, $list['total'] );
		self::assertSame( 'recovered', $list['items'][0]['status'] );
		self::assertSame( $order->order_number, $list['items'][0]['order_number'] );
		self::assertSame( 1, $list['stats']['recovered'] );
		self::assertSame( 100, $list['stats']['rate'] );
		self::assertSame( 1500, $list['stats']['revenue']['amount'] );
		self::assertSame( 0, $this->send( 'GET', '/admin/recovery', [ 'status' => 'open' ] )->get_data()['total'] );
	}

	public function test_an_emptied_cart_is_forgotten_before_a_reminder_and_lost_after_one(): void {
		$this->enable( [ [ 'delay_hours' => 5 ] ] );
		$product = $this->simple( 1000 );
		$this->cart()->add( 0, $product, $product, 1 );
		$this->recovery()->capture( 0, 'ada@example.com' );
		$this->age( 120 );
		$this->recovery()->run();
		self::assertNotNull( $this->row() );
		self::assertSame( [], $this->mails(), 'The reminder is not due yet.' );

		$this->cart()->clear( 0 );
		$this->age( 110 );
		$this->recovery()->run();
		self::assertNull( $this->row(), 'Nobody was reached, so the row goes.' );

		// Ages only shrink from here: a change has to be newer than the last look.
		$this->cart()->add( 0, $product, $product, 1 );
		$this->age( 100 );
		$this->recovery()->run();
		$this->due( (int) $this->row()?->id );
		$this->recovery()->run();
		self::assertSame( 1, $this->row()?->emails_sent );
		$this->cart()->clear( 0 );
		$this->age( 90 );
		$this->recovery()->run();
		self::assertSame( Recovery::LOST, $this->row()?->status );
	}

	public function test_a_signed_in_shopper_is_followed_without_typing_an_email(): void {
		$this->enable();
		$user = self::factory()->user->create( [ 'user_email' => 'grace@example.com' ] );
		$product = $this->simple( 1000 );
		$this->cart()->add( $user, $product, $product, 1 );
		$this->age( 120 );
		$this->recovery()->run();
		self::assertSame( 'grace@example.com', $this->row()?->email );
		self::assertSame( $user, $this->row()?->user_id );
		self::assertSame( 'grace@example.com', $this->mails()[0]['to'] ?? null );
	}

	public function test_the_stop_link_asks_first_and_then_keeps_the_address_quiet(): void {
		$this->enable( [ [ 'delay_hours' => 5 ] ] );
		$product = $this->simple( 1000 );
		$this->cart()->add( 0, $product, $product, 1 );
		$this->recovery()->capture( 0, 'ada@example.com' );
		$this->age( 120 );
		$this->recovery()->run();
		$row = $this->row();
		self::assertNotNull( $row );

		$_GET[ RecoveryService::STOP_PARAM ] = $row->key();
		$_SERVER['REQUEST_METHOD']            = 'GET';
		$page                                 = $this->die_message( static fn () => RecoveryHandler::instance()->handle() );
		self::assertStringContainsString( 'Stop cart reminders', $page );
		self::assertStringContainsString( 'method="post"', $page );
		self::assertFalse( Plugin::instance()->recovery()->repository()->is_unsubscribed( 'ada@example.com' ), 'Opening the link stops nothing.' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = wp_create_nonce( RecoveryHandler::NONCE );
		$done                      = $this->die_message( static fn () => RecoveryHandler::instance()->handle() );
		self::assertStringContainsString( 'no more reminders', $done );
		self::assertTrue( Plugin::instance()->recovery()->repository()->is_unsubscribed( 'ada@example.com' ) );

		$_GET[ RecoveryService::STOP_PARAM ] = $row->id . '.' . str_repeat( '0', 32 );
		self::assertStringContainsString( 'not valid', $this->die_message( static fn () => RecoveryHandler::instance()->handle() ) );
		unset( $_GET[ RecoveryService::STOP_PARAM ], $_REQUEST['_wpnonce'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->cart()->add( 0, $product, $product, 1 );
		$this->age( 70 );
		$this->due( $row->id );
		$this->recovery()->run();
		self::assertSame( [], $this->mails(), 'An address that said stop gets nothing.' );
		self::assertSame( 1, $this->row()?->item_count, 'Its cart is not copied again either.' );
	}

	public function test_capture_needs_the_setting_and_a_cart(): void {
		self::assertFalse( $this->recovery()->capture( 0, 'ada@example.com' ), 'Reminders are off.' );
		$this->enable();
		self::assertFalse( $this->recovery()->capture( 0, 'ada@example.com' ), 'No cart yet.' );
		RecoverySettings::update( [ 'capture_guests' => false ] );
		$product = $this->simple( 1000 );
		$this->cart()->add( 0, $product, $product, 1 );
		self::assertFalse( $this->recovery()->capture( 0, 'ada@example.com' ) );
		RecoverySettings::update( [ 'capture_guests' => true ] );
		self::assertTrue( $this->recovery()->capture( 0, 'ada@example.com' ) );
		self::assertSame( 'ada@example.com', $this->sessions()->current( 0 )?->email );
	}

	public function test_the_daily_run_gives_up_on_old_carts(): void {
		global $wpdb;
		$this->enable( [ [ 'delay_hours' => 5 ] ] );
		$product = $this->simple( 1000 );
		$this->cart()->add( 0, $product, $product, 1 );
		$this->recovery()->capture( 0, 'ada@example.com' );
		$this->age( 120 );
		$this->recovery()->run();
		$row = $this->row();
		self::assertNotNull( $row );
		$old = gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS );
		$wpdb->update( Schema::table( 'recoveries' ), [ 'last_activity_at' => $old ], [ 'id' => $row->id ] ); // phpcs:ignore WordPress.DB
		$this->recovery()->daily();
		self::assertSame( Recovery::LOST, $this->row()?->status );
		self::assertNull( $this->row()?->next_send_at );

		$wpdb->update( Schema::table( 'recoveries' ), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ], [ 'id' => $row->id ] ); // phpcs:ignore WordPress.DB
		$this->recovery()->daily();
		self::assertNull( $this->row() );
	}

	public function test_the_settings_routes_merge_partial_saves_and_send_a_test(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator', 'user_email' => 'boss@example.com' ] ) );
		$data = $this->send( 'GET', '/admin/recovery/settings' )->get_data();
		self::assertFalse( $data['settings']['enabled'] );
		self::assertSame( 60, $data['settings']['abandon_minutes'] );
		self::assertCount( 3, $data['settings']['reminders'] );
		self::assertTrue( $data['settings']['reminders'][0]['enabled'] );
		self::assertFalse( $data['settings']['reminders'][1]['enabled'] );
		self::assertSame( 'You left something in your cart', $data['defaults'][0]['subject'] );
		self::assertSame( 'boss@example.com', $data['email'] );

		$this->send( 'PATCH', '/admin/recovery/settings', [ 'enabled' => true ] );
		$saved = $this->send(
			'PATCH',
			'/admin/recovery/settings',
			[
				'reminders' => [
					[],
					[
						'enabled'       => true,
						'subject'       => 'Come back <b>soon</b>',
						'coupon_type'   => 'percent',
						'coupon_amount' => 150,
					],
				],
			]
		)->get_data()['settings'];
		self::assertTrue( $saved['enabled'] );
		self::assertSame( 1, $saved['reminders'][0]['delay_hours'] );
		self::assertTrue( $saved['reminders'][1]['enabled'] );
		self::assertSame( 'Come back soon', $saved['reminders'][1]['subject'] );
		self::assertSame( 100, $saved['reminders'][1]['coupon_amount'] );
		self::assertSame( 72, $saved['reminders'][2]['delay_hours'] );
		self::assertArrayNotHasKey( RecoverySettings::OPTION, wp_load_alloptions() );

		self::assertSame( 400, $this->send( 'PATCH', '/admin/recovery/settings', [ 'abandon_minutes' => 5 ] )->get_status() );

		$test = $this->send( 'POST', '/admin/recovery/test', [ 'reminder' => 1 ] );
		self::assertSame( 200, $test->get_status() );
		$mail = $this->mails();
		self::assertSame( 'boss@example.com', $mail[0]['to'] );
		self::assertSame( 'Come back soon', $mail[0]['subject'] );
		self::assertStringContainsString( 'CART-SAMPLE', $mail[0]['body'] );
		self::assertSame( 400, $this->send( 'POST', '/admin/recovery/test', [ 'to' => 'nope' ] )->get_status() );
	}

	public function test_a_row_can_be_deleted_and_the_routes_need_their_capabilities(): void {
		$this->enable( [ [ 'delay_hours' => 5 ] ] );
		$product = $this->simple( 1000 );
		$this->cart()->add( 0, $product, $product, 1 );
		$this->recovery()->capture( 0, 'ada@example.com' );
		$this->age( 120 );
		$this->recovery()->run();
		$row = $this->row();
		self::assertNotNull( $row );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'GET', '/admin/recovery' )->get_status() );
		self::assertSame( 403, $this->send( 'DELETE', '/admin/recovery/' . $row->id )->get_status() );
		self::assertSame( 403, $this->send( 'GET', '/admin/recovery/settings' )->get_status() );
		self::assertSame( 403, $this->send( 'PATCH', '/admin/recovery/settings', [ 'enabled' => false ] )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/recovery/test' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$list = $this->send( 'GET', '/admin/recovery' )->get_data();
		self::assertSame( 'open', $list['items'][0]['status'] );
		self::assertSame( '$10.00', $list['items'][0]['total']['formatted'] );
		self::assertSame( [ [ 'name' => get_the_title( $product ), 'quantity' => 1 ] ], $list['items'][0]['items'] );
		self::assertTrue( $list['enabled'] );
		self::assertSame( 200, $this->send( 'DELETE', '/admin/recovery/' . $row->id )->get_status() );
		self::assertSame( 404, $this->send( 'DELETE', '/admin/recovery/' . $row->id )->get_status() );
	}

	public function test_privacy_exports_and_erases_the_left_carts(): void {
		Privacy::instance()->register();
		self::assertArrayHasKey( 'flexacommerce-reminders', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		self::assertArrayHasKey( 'flexacommerce-reminders', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
		$this->enable( [ [ 'delay_hours' => 5 ] ] );
		$product = $this->simple( 1000 );
		$this->cart()->add( 0, $product, $product, 3 );
		$this->recovery()->capture( 0, 'ada@example.com' );
		$this->age( 120 );
		$this->recovery()->run();

		$export = Privacy::instance()->export_reminders( 'ada@example.com' );
		self::assertCount( 1, $export['data'] );
		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		self::assertSame( get_the_title( $product ) . ' × 3', $fields['Cart items'] );
		self::assertSame( '0', $fields['Reminders sent'] );

		$erased = Privacy::instance()->erase_reminders( 'ada@example.com' );
		self::assertTrue( $erased['items_removed'] );
		self::assertNull( $this->row() );
		self::assertSame( '', Plugin::instance()->cart()->session_rows()->find( (int) $this->sessions()->current( 0 )?->id )?->email );
		self::assertSame( [], Privacy::instance()->export_reminders( 'ada@example.com' )['data'] );
	}

	/** @param list<array<string,mixed>> $reminders */
	private function enable( array $reminders = [] ): void {
		RecoverySettings::update(
			[
				'enabled'   => true,
				'reminders' => $reminders,
			]
		);
	}

	/** A new browser with an empty cookie, wired into the plugin. */
	private function shopper(): void {
		$this->cookie = new MemoryTokenStore();
		$this->swap( new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), $this->cookie ) );
	}

	private function swap( ?CartServices $services ): void {
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), $services );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'recovery' ) )->setValue( Plugin::instance(), null );
	}

	private function recovery(): RecoveryService {
		return Plugin::instance()->recovery();
	}

	private function cart(): \Flexa\Commerce\Cart\CartService {
		return Plugin::instance()->cart()->cart();
	}

	private function sessions(): \Flexa\Commerce\Cart\SessionStore {
		return Plugin::instance()->cart()->sessions();
	}

	/** The cart was last touched `$minutes` ago. */
	private function age( int $minutes ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = %s', Schema::table( 'sessions' ), gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		$this->shopper_again();
	}

	/** The same browser on its next request: nothing cached from the last one. */
	private function shopper_again(): void {
		$this->swap( new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), $this->cookie ) );
	}

	private function due( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'recoveries' ), [ 'next_send_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ], [ 'id' => $id ] ); // phpcs:ignore WordPress.DB
	}

	private function row(): ?Recovery {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Schema::table( 'recoveries' ) ) ); // phpcs:ignore WordPress.DB
		return $id > 0 ? Plugin::instance()->recovery()->repository()->find( $id ) : null;
	}

	/** @return list<array{to:string,subject:string,body:string}> */
	private function mails(): array {
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

	private function die_message( callable $run ): string {
		try {
			$run();
		} catch ( \WPDieException $e ) {
			return $e->getMessage();
		}
		self::fail( 'Expected wp_die.' );
	}

	/** @param array<string,mixed>|null $body */
	private function send( string $method, string $path, ?array $body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		if ( $body !== null && $method === 'GET' ) {
			$request->set_query_params( $body );
		} elseif ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
