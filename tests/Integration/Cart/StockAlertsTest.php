<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Api\Store\StockAlertsEndpoint;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Privacy\Privacy;
use Flexa\Commerce\StockAlerts\SignupRefused;
use Flexa\Commerce\StockAlerts\StockAlert;
use Flexa\Commerce\StockAlerts\StockAlertService;
use Flexa\Commerce\StockAlerts\StockAlertSettings;
use Flexa\Commerce\Storefront\StockAlertHandler;
use Flexa\Commerce\Support\RateLimiter;

/**
 * Back-in-stock emails (ADR-065): signups, the restock that marks them due,
 * the cron that sends each once, the stop link, the routes and privacy.
 * Stock changes commit, so the rows are deleted after the rollback.
 */
final class StockAlertsTest extends CartTestCase {
	private const IP = '203.0.113.9';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		reset_phpmailer_instance();
		$_SERVER['REMOTE_ADDR'] = self::IP;
	}

	public function tear_down(): void {
		global $wpdb;
		( new RateLimiter() )->reset( StockAlertsEndpoint::LIMIT, self::IP );
		parent::tear_down();
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'stock_alerts' ) ); // phpcs:ignore WordPress.DB
		delete_option( StockAlertSettings::OPTION );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		unset( $_GET[ StockAlertService::STOP_PARAM ], $_REQUEST['_wpnonce'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_a_guest_waits_for_the_restock_and_gets_one_email(): void {
		$id    = $this->simple( 1000, 0 );
		$alert = $this->alerts()->subscribe( $id, 0, ' Ada@Example.com ', 0 );
		self::assertSame( 'ada@example.com', $alert->email );
		self::assertSame( StockAlert::WAITING, $alert->status );
		self::assertSame( $this->find( $id )->base->id, $alert->variant_id, 'A simple product follows its base row.' );
		self::assertSame( $alert->id, $this->alerts()->subscribe( $id, 0, 'ada@example.com', 0 )->id, 'Asking twice keeps one row.' );

		self::assertSame( 0, $this->alerts()->run(), 'Nothing is due before a stock change.' );
		$this->restock( $id, 3 );
		self::assertSame( 1, $this->alerts()->run() );

		$mail = $this->mails();
		self::assertCount( 1, $mail );
		self::assertSame( 'ada@example.com', $mail[0]['to'] );
		self::assertSame( get_the_title( $id ) . ' is back in stock', $mail[0]['subject'] );
		self::assertStringContainsString( (string) get_permalink( $id ), $mail[0]['body'] );
		self::assertStringContainsString( StockAlertService::STOP_PARAM . '=' . $alert->key(), $mail[0]['body'] );

		$sent = $this->alerts()->repository()->find( $alert->id );
		self::assertSame( StockAlert::SENT, $sent?->status );
		self::assertNotNull( $sent?->sent_at );
		$this->alerts()->repository()->mark_all();
		self::assertSame( 0, $this->alerts()->run(), 'A sent row is not sent again.' );
		self::assertCount( 1, $this->mails() );
	}

	public function test_signups_follow_the_settings_and_the_stock(): void {
		$sold = $this->simple( 1000, 0 );
		$this->assert_refused( 'email', static fn ( StockAlertService $s ) => $s->subscribe( $sold, 0, 'nope', 0 ) );
		$this->assert_refused( 'in_stock', fn ( StockAlertService $s ) => $s->subscribe( $this->simple( 1000, 2 ), 0, 'ada@example.com', 0 ) );
		$this->assert_refused( 'product', static fn ( StockAlertService $s ) => $s->subscribe( 999999, 0, 'ada@example.com', 0 ) );

		StockAlertSettings::update( [ 'guests' => false ] );
		$this->assert_refused( 'login', static fn ( StockAlertService $s ) => $s->subscribe( $sold, 0, 'ada@example.com', 0 ) );
		$user  = self::factory()->user->create( [ 'user_email' => 'grace@example.com' ] );
		$alert = $this->alerts()->subscribe( $sold, 0, 'someone@example.com', $user );
		self::assertSame( 'grace@example.com', $alert->email, 'A signed-in shopper is followed at their own address.' );
		self::assertSame( $user, $alert->user_id );

		StockAlertSettings::update( [ 'enabled' => false ] );
		$this->assert_refused( 'off', static fn ( StockAlertService $s ) => $s->subscribe( $sold, 0, 'ada@example.com', $user ) );
	}

	public function test_a_variation_signup_waits_for_that_variation(): void {
		[ $id, $sizes ] = $this->sized( 5 );
		$sizes          = array_combine( [ 'S', 'M', 'L' ], array_values( $sizes ) );
		$this->restock( $id, -5, $sizes['S'] );
		$this->assert_refused( 'in_stock', static fn ( StockAlertService $s ) => $s->subscribe( $id, $sizes['M'], 'ada@example.com', 0 ) );
		$this->assert_refused( 'in_stock', static fn ( StockAlertService $s ) => $s->subscribe( $id, 0, 'ada@example.com', 0 ) );
		$this->assert_refused( 'product', static fn ( StockAlertService $s ) => $s->subscribe( $id, 999999, 'ada@example.com', 0 ) );
		$alert = $this->alerts()->subscribe( $id, $sizes['S'], 'ada@example.com', 0 );

		// Stock of another size marks the product, but S is still out.
		$this->restock( $id, 1, $sizes['M'] );
		self::assertTrue( $this->alerts()->repository()->has_due() );
		self::assertSame( 0, $this->alerts()->run() );
		self::assertFalse( $this->alerts()->repository()->has_due(), 'Looked at and put back to wait.' );

		$this->restock( $id, 2, $sizes['S'] );
		self::assertSame( 1, $this->alerts()->run() );
		// The store owner also heard that S ran out.
		$mail = array_values( array_filter( $this->mails(), static fn ( array $m ): bool => $m['to'] === 'ada@example.com' ) );
		self::assertCount( 1, $mail );
		self::assertStringContainsString( get_the_title( $id ) . ' – S', $mail[0]['subject'] );
		self::assertStringContainsString( 'fxc_pick', $mail[0]['body'] );
		self::assertSame( StockAlert::SENT, $this->alerts()->repository()->find( $alert->id )?->status );
	}

	public function test_the_stop_link_asks_first_and_stops_every_waiting_row(): void {
		$first  = $this->alerts()->subscribe( $this->simple( 1000, 0 ), 0, 'ada@example.com', 0 );
		$second = $this->alerts()->subscribe( $this->simple( 1000, 0 ), 0, 'ada@example.com', 0 );
		$other  = $this->alerts()->subscribe( $second->product_id, 0, 'grace@example.com', 0 );

		$_GET[ StockAlertService::STOP_PARAM ] = $first->key();
		$page                                  = $this->die_message( static fn () => StockAlertHandler::instance()->handle() );
		self::assertStringContainsString( 'Stop back-in-stock emails', $page );
		self::assertStringContainsString( 'method="post"', $page );
		self::assertSame( StockAlert::WAITING, $this->alerts()->repository()->find( $first->id )?->status, 'Opening the link stops nothing.' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = wp_create_nonce( StockAlertHandler::NONCE );
		self::assertStringContainsString( 'no more back-in-stock emails', $this->die_message( static fn () => StockAlertHandler::instance()->handle() ) );
		self::assertSame( StockAlert::CANCELLED, $this->alerts()->repository()->find( $first->id )?->status );
		self::assertSame( StockAlert::CANCELLED, $this->alerts()->repository()->find( $second->id )?->status );
		self::assertSame( StockAlert::WAITING, $this->alerts()->repository()->find( $other->id )?->status, 'Other addresses keep waiting.' );

		$_GET[ StockAlertService::STOP_PARAM ] = $first->id . '.' . str_repeat( '0', 32 );
		self::assertStringContainsString( 'not valid', $this->die_message( static fn () => StockAlertHandler::instance()->handle() ) );
	}

	public function test_the_daily_run_looks_again_and_drops_old_rows(): void {
		global $wpdb;
		$id    = $this->simple( 1000, 0 );
		$alert = $this->alerts()->subscribe( $id, 0, 'ada@example.com', 0 );
		$done  = $this->alerts()->subscribe( $id, 0, 'grace@example.com', 0 );
		$this->alerts()->repository()->cancel_email( 'grace@example.com', gmdate( 'Y-m-d H:i:s' ) );
		$wpdb->update( Schema::table( 'stock_alerts' ), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ], [ 'id' => $done->id ] ); // phpcs:ignore WordPress.DB

		self::assertFalse( $this->alerts()->repository()->has_due() );
		$this->alerts()->daily();
		self::assertTrue( $this->alerts()->repository()->has_due(), 'Every waiting row is looked at again.' );
		self::assertNull( $this->alerts()->repository()->find( $done->id ), 'A row stopped 100 days ago goes.' );
		self::assertNotNull( $this->alerts()->repository()->find( $alert->id ) );

		wp_delete_post( $id, true );
		$this->alerts()->daily();
		self::assertNull( $this->alerts()->repository()->find( $alert->id ), 'Rows of a deleted product go.' );
	}

	public function test_the_store_route_signs_up_and_is_rate_limited(): void {
		$id       = $this->simple( 1000, 0 );
		$response = $this->store(
			[
				'product_id' => $id,
				'email'      => 'ada@example.com',
			]
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 'waiting', $response->get_data()['status'] );
		self::assertSame( 'Thanks. We will email you when it is back in stock.', $response->get_data()['message'] );

		$bad = $this->store(
			[
				'product_id' => $id,
				'email'      => 'nope',
			]
		);
		self::assertSame( 400, $bad->get_status() );
		self::assertSame( 'flexacommerce_stock_alert_email', $bad->as_error()->get_error_code() );

		for ( $i = 2; $i < 10; $i++ ) {
			$this->store(
				[
					'product_id' => $id,
					'email'      => "shopper{$i}@example.com",
				]
			);
		}
		$limited = $this->store(
			[
				'product_id' => $id,
				'email'      => 'late@example.com',
			]
		);
		self::assertSame( 429, $limited->get_status() );
		self::assertSame( 'flexacommerce_rate_limited', $limited->as_error()->get_error_code(), 'Ten tries an hour, the bad one included.' );
		self::assertSame( 9, $this->alerts()->repository()->counts( '1970-01-01 00:00:00' )['waiting'] );
		self::assertNull( $this->alerts()->repository()->waiting_for( 'late@example.com', $id, $this->find( $id )->base->id ) );
	}

	public function test_the_admin_routes_list_delete_and_save(): void {
		$sold = $this->simple( 1000, 0 );
		$less = $this->simple( 1000, 0 );
		$this->alerts()->subscribe( $sold, 0, 'ada@example.com', 0 );
		$this->alerts()->subscribe( $sold, 0, 'grace@example.com', 0 );
		$alert = $this->alerts()->subscribe( $less, 0, 'ada@example.com', 0 );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, $this->send( 'GET', '/admin/stock-alerts' )->get_status() );
		self::assertSame( 403, $this->send( 'DELETE', '/admin/stock-alerts/' . $alert->id )->get_status() );
		self::assertSame( 403, $this->send( 'GET', '/admin/stock-alerts/settings' )->get_status() );
		self::assertSame( 403, $this->send( 'PATCH', '/admin/stock-alerts/settings', [ 'enabled' => false ] )->get_status() );
		self::assertSame( 403, $this->send( 'POST', '/admin/stock-alerts/test' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$list = $this->send( 'GET', '/admin/stock-alerts' )->get_data();
		self::assertSame( 3, $list['total'] );
		self::assertSame( 3, $list['stats']['waiting'] );
		self::assertTrue( $list['enabled'] );
		self::assertSame( $sold, $list['wanted'][0]['product_id'] );
		self::assertSame( 2, $list['wanted'][0]['waiting'] );
		self::assertFalse( $list['wanted'][0]['in_stock'] );
		self::assertSame( 1, $this->send( 'GET', '/admin/stock-alerts', [ 'product_id' => $less ] )->get_data()['total'] );
		self::assertSame( 0, $this->send( 'GET', '/admin/stock-alerts', [ 'status' => 'sent' ] )->get_data()['total'] );
		self::assertSame( 'ada@example.com', $list['items'][0]['email'] );

		self::assertSame( 200, $this->send( 'DELETE', '/admin/stock-alerts/' . $alert->id )->get_status() );
		self::assertSame( 404, $this->send( 'DELETE', '/admin/stock-alerts/' . $alert->id )->get_status() );

		$settings = $this->send( 'GET', '/admin/stock-alerts/settings' )->get_data();
		self::assertTrue( $settings['settings']['guests'] );
		self::assertSame( '{product} is back in stock', $settings['defaults']['subject'] );
		$saved = $this->send( 'PATCH', '/admin/stock-alerts/settings', [ 'subject' => 'Back: {product}' ] )->get_data();
		self::assertSame( 'Back: {product}', $saved['settings']['subject'] );
		self::assertTrue( $saved['settings']['enabled'], 'A partial save keeps the rest.' );

		$test = $this->send( 'POST', '/admin/stock-alerts/test', [ 'to' => 'owner@example.com' ] );
		self::assertSame( 200, $test->get_status(), (string) wp_json_encode( $test->get_data() ) );
		$mail = $this->mails();
		self::assertCount( 1, $mail );
		self::assertSame( 'owner@example.com', $mail[0]['to'] );
		self::assertSame( 'Back: ' . get_the_title( $sold ), $mail[0]['subject'], 'The test uses the most wanted product.' );
		self::assertSame( 400, $this->send( 'POST', '/admin/stock-alerts/test', [ 'to' => 'nope' ] )->get_status() );
	}

	public function test_privacy_exports_and_erases_the_signups(): void {
		Privacy::instance()->register();
		self::assertArrayHasKey( 'flexacommerce-stock-alerts', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		self::assertArrayHasKey( 'flexacommerce-stock-alerts', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
		$id = $this->simple( 1000, 0 );
		$this->alerts()->subscribe( $id, 0, 'ada@example.com', 0 );
		$other = $this->alerts()->subscribe( $id, 0, 'grace@example.com', 0 );

		$export = Privacy::instance()->export_stock_alerts( 'Ada@example.com' );
		self::assertCount( 1, $export['data'] );
		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		self::assertSame( get_the_title( $id ), $fields['Product'] );

		$erased = Privacy::instance()->erase_stock_alerts( 'Ada@example.com' );
		self::assertTrue( $erased['items_removed'] );
		self::assertSame( [], Privacy::instance()->export_stock_alerts( 'ada@example.com' )['data'] );
		self::assertNotNull( $this->alerts()->repository()->find( $other->id ) );
	}

	public function test_the_product_page_shows_the_form_where_it_helps(): void {
		$sold = $this->simple( 1000, 0 );
		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $sold . '"]' );
		self::assertStringContainsString( 'Out of stock', $html );
		self::assertStringContainsString( 'name="fxc_op" value="stock_alert"', $html );
		self::assertStringContainsString( 'type="email" name="email" required', $html );

		wp_set_current_user( self::factory()->user->create() );
		self::assertStringNotContainsString( 'name="email"', do_shortcode( '[flexacommerce_add_to_cart id="' . $sold . '"]' ), 'A signed-in shopper needs one click.' );
		wp_set_current_user( 0 );

		self::assertStringNotContainsString( 'fxc-stock-alert', do_shortcode( '[flexacommerce_add_to_cart id="' . $this->simple( 1000, 3 ) . '"]' ) );
		[ $id ] = $this->sized( 5 );
		$inline = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertStringContainsString( 'fxc-stock-alert--inline', $inline );
		self::assertStringContainsString( 'actions.notify', $inline );

		StockAlertSettings::update( [ 'enabled' => false ] );
		self::assertStringNotContainsString( 'fxc-stock-alert', do_shortcode( '[flexacommerce_add_to_cart id="' . $sold . '"]' ) );
		self::assertStringNotContainsString( 'fxc-stock-alert', do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' ) );
	}

	private function alerts(): StockAlertService {
		return Plugin::instance()->stock_alerts();
	}

	private function find( int $id ): Product {
		$product = Plugin::instance()->catalog()->products()->find( $id );
		self::assertNotNull( $product );
		return $product;
	}

	/** Moves the stock of the variant, the base row when 0, through the stock service. */
	private function restock( int $id, int $delta, int $variant_id = 0 ): void {
		$product = $this->find( $id );
		$row     = $variant_id > 0 ? $product->sellable( $variant_id ) : $product->base;
		self::assertNotNull( $row );
		Plugin::instance()->cart()->stock()->adjust( $product, $row, $delta, 'test' );
	}

	/** @param callable(StockAlertService):mixed $run */
	private function assert_refused( string $reason, callable $run ): void {
		try {
			$run( $this->alerts() );
		} catch ( SignupRefused $e ) {
			self::assertSame( $reason, $e->reason );
			return;
		}
		self::fail( "Expected the signup to be refused for {$reason}." );
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

	/** @param array<string,mixed> $body */
	private function store( array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/flexacommerce/v1/store/stock-alerts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'origin', home_url() );
		$request->set_header( 'x_fxc_nonce', Plugin::instance()->cart()->nonce()->create( 0, '', null ) );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
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
