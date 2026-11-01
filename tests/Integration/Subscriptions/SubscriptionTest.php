<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Subscriptions;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Privacy\Privacy;
use Flexa\Commerce\Setup\Pages;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Storefront\AccountPage;
use Flexa\Commerce\Subscriptions\Subscription;
use Flexa\Commerce\Subscriptions\SubscriptionService;
use Flexa\Commerce\Subscriptions\SubscriptionStatus;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * Subscriptions with manual renewals (ADR-073): checkout makes a pending
 * row, the paid first order starts it, each due day makes a renewal order
 * that holds the row until it is paid, and cancelling or running out of
 * payments ends it. Rows and users commit, so they are deleted after the
 * rollback.
 */
final class SubscriptionTest extends OrderTestCase {
	private const BILLING = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Infinite Loop',
		'city'       => 'Cupertino',
		'state'      => 'CA',
		'postcode'   => '95014',
		'country'    => 'US',
		'email'      => 'ada@example.com',
	];

	private string $rate_id = '';

	private int $user = 0;

	private int $last_user = 0;

	public function set_up(): void {
		parent::set_up();
		$gateways = Plugin::instance()->payments()->service()->gateways();
		$cod      = $gateways->get( 'cod' );
		self::assertNotNull( $cod );
		$gateways->settings()->save( $cod, [ 'enabled' => true ] );
		$bacs = $gateways->get( 'bacs' );
		self::assertNotNull( $bacs );
		$gateways->settings()->save( $bacs, [ 'enabled' => true ] );
		$zones         = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		$this->rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
		global $wpdb;
		$this->last_user = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB
		$this->user      = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'ada@example.com',
			]
		);
	}

	public function tear_down(): void {
		global $wpdb;
		delete_option( GatewaySettings::PREFIX . 'cod' );
		delete_option( GatewaySettings::PREFIX . 'bacs' );
		wp_set_current_user( 0 );
		$_POST = [];
		$_GET  = [];
		parent::tear_down();
		delete_option( ZoneRepository::OPTION );
		foreach ( [ 'subscription_events', 'subscription_orders', 'subscriptions' ] as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id > %d", $this->last_user ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE ID > %d", $this->last_user ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_a_paid_order_starts_the_subscription_and_each_renewal_waits_for_payment(): void {
		$product = $this->plan_product(
			1000,
			[
				'period'     => 'month',
				'signup_fee' => 500,
			]
		);
		$order   = $this->buy( $this->user, $product, 2 );
		self::assertSame( 3500, $order->total, 'Two periods at 10, two sign-up fees at 5, shipping 5.' );
		self::assertArrayHasKey( 'subscription', $order->items[0]->meta );

		$row = $this->only( $order );
		self::assertSame( [ SubscriptionStatus::PENDING, $this->user, 2500, 'month', 1 ], [ $row->status, $row->customer_id, $row->total, $row->plan->period, $row->plan->interval ] );
		self::assertNull( $row->next_payment_at );
		self::assertTrue( SubscriptionService::has_any( $this->user ) );
		self::assertSame( 'Test product × 2', $row->title() );

		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 1 ], [ $row->status, $row->billed ] );
		self::assertNotNull( $row->next_payment_at );
		self::assertSame( $row->plan->after( (string) $row->start_at, 1, $row->anchor_day ), $row->next_payment_at );

		$this->due( $row );
		self::assertSame( 1, $this->subs()->run() );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( [ SubscriptionStatus::ON_HOLD, 2 ], [ $row->status, $row->billed ] );
		$renewal = $this->subs()->unpaid_renewal( $row );
		self::assertNotNull( $renewal );
		self::assertSame( [ OrderStatus::PENDING, 2500, SubscriptionService::CREATED_VIA, 'bacs', $this->user ], [ $renewal->status, $renewal->total, $renewal->created_via, $renewal->payment_method, $renewal->customer_id ] );
		self::assertSame( 2000, $renewal->subtotal, 'No sign-up fee on a renewal.' );
		self::assertSame( 0, $this->subs()->run(), 'Nothing more is due.' );

		$this->orders()->record_payment( $renewal->id, $renewal->total, TransitionContext::system() );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( SubscriptionStatus::ACTIVE, $row->status );
		self::assertNull( $this->subs()->unpaid_renewal( $row ) );
		self::assertSame( [ 'created', 'active', 'renewal', 'renewal-paid' ], array_reverse( array_column( $this->subs()->repository()->events( $row->id, 20 ), 'type' ) ) );
	}

	public function test_a_cancelled_first_order_cancels_the_subscription(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'week' ] ), 1, [], 'cod' );
		self::assertSame( SubscriptionStatus::ACTIVE, $this->only( $order )->status, 'Cash on delivery starts it at once.' );
		$this->orders()->cancel( $order->id, TransitionContext::admin() );
		self::assertSame( SubscriptionStatus::CANCELLED, $this->only( $order )->status );
	}

	public function test_a_trial_starts_free_and_bills_when_it_ends(): void {
		$order = $this->buy(
			$this->user,
			$this->plan_product(
				1000,
				[
					'period'     => 'month',
					'trial_days' => 14,
				]
			),
			1
		);
		self::assertSame( 500, $order->total, 'Only shipping is paid now.' );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 0 ], [ $row->status, $row->billed ] );
		self::assertSame( $row->trial_end_at, $row->next_payment_at );
		self::assertTrue( $row->in_trial( (string) $row->start_at ) );

		$this->due( $row );
		$renewal = $this->subs()->renew( $row->id, TransitionContext::admin() );
		self::assertNotNull( $renewal );
		self::assertSame( 1500, $renewal->total );
	}

	public function test_a_plan_with_a_length_expires_after_the_last_payment(): void {
		$order = $this->buy(
			$this->user,
			$this->plan_product(
				1000,
				[
					'period' => 'day',
					'length' => 2,
				]
			),
			1
		);
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );
		$this->due( $row );
		$renewal = $this->subs()->renew( $row->id, TransitionContext::admin() );
		self::assertNotNull( $renewal );
		$this->orders()->record_payment( $renewal->id, $renewal->total, TransitionContext::system() );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 2 ], [ $row->status, $row->billed ] );
		self::assertNull( $row->next_payment_at, 'Both payments are made.' );
		self::assertNotNull( $row->end_at );

		$this->subs()->repository()->update( $row->id, [ 'end_at' => '2020-01-01 00:00:00' ], '2020-01-01 00:00:00' );
		self::assertSame( 1, $this->subs()->run() );
		self::assertSame( SubscriptionStatus::EXPIRED, $this->subs()->find( $row->id )?->status );
	}

	public function test_cancel_at_period_end_can_be_undone_and_otherwise_ends_on_time(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row  = $this->only( $order );
		$next = $row->next_payment_at;

		$row = $this->subs()->cancel( $row->id, true, new TransitionContext( 'customer', $this->user ) );
		self::assertSame( [ SubscriptionStatus::PENDING_CANCEL, null, $next ], [ $row->status, $row->next_payment_at, $row->end_at ] );
		$row = $this->subs()->reactivate( $row->id, TransitionContext::admin() );
		self::assertSame( [ SubscriptionStatus::ACTIVE, $next, null ], [ $row->status, $row->next_payment_at, $row->end_at ] );

		$row = $this->subs()->cancel( $row->id, true, TransitionContext::admin() );
		$this->subs()->repository()->update( $row->id, [ 'end_at' => '2020-01-01 00:00:00' ], '2020-01-01 00:00:00' );
		$this->subs()->run();
		self::assertSame( SubscriptionStatus::CANCELLED, $this->subs()->find( $row->id )?->status );
	}

	public function test_cancelling_now_cancels_the_unpaid_renewal(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );
		$this->due( $row );
		$renewal = $this->subs()->renew( $row->id, TransitionContext::admin() );
		self::assertNotNull( $renewal );

		$row = $this->subs()->cancel( $row->id, false, TransitionContext::admin() );
		self::assertSame( SubscriptionStatus::CANCELLED, $row->status );
		self::assertSame( OrderStatus::CANCELLED, $this->orders()->find( $renewal->id )?->status );
		$this->expectException( \InvalidArgumentException::class );
		$this->subs()->cancel( $row->id, false, TransitionContext::admin() );
	}

	public function test_a_guest_gets_an_account_unless_the_email_has_one(): void {
		$product = $this->plan_product( 1000, [ 'period' => 'month' ] );
		try {
			$this->buy( 0, $product, 1 );
			self::fail( 'A guest bought with the email of an account.' );
		} catch ( InvalidCheckout $e ) {
			self::assertArrayHasKey( 'billing.email', $e->errors );
		}

		$order = $this->buy( 0, $product, 1, [ 'email' => 'grace@example.com' ] );
		$user  = get_user_by( 'email', 'grace@example.com' );
		self::assertInstanceOf( \WP_User::class, $user );
		self::assertSame( $user->ID, $order->customer_id );
		self::assertSame( $user->ID, $this->only( $order )->customer_id );
		self::assertSame( 'grace', $user->user_login );
	}

	public function test_a_reschedule_must_be_in_the_future(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->subs()->reschedule( $this->only( $order )->id, '2099-03-31 10:00:00', TransitionContext::admin() );
		self::assertSame( [ '2099-03-31 10:00:00', 31 ], [ $row->next_payment_at, $row->anchor_day ] );
		self::assertSame( '2099-04-30 10:00:00', $row->plan->after( (string) $row->next_payment_at, 1, $row->anchor_day ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->subs()->reschedule( $row->id, '2001-01-01 00:00:00', TransitionContext::admin() );
	}

	public function test_the_admin_screen_lists_shows_moves_and_acts(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );

		$response = $this->send( 'GET', '/admin/subscriptions' );
		self::assertSame( 401, $response->get_status(), 'Guests are refused.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$list = $this->send( 'GET', '/admin/subscriptions', [ 'search' => 'ada@' ] )->get_data();
		self::assertSame( 1, $list['total'] );
		self::assertSame( [ $row->id, 'active', get_userdata( $this->user )->display_name ], [ $list['items'][0]['id'], $list['items'][0]['status'], $list['items'][0]['customer_name'] ] );
		self::assertSame( 1, $list['counts']->active );
		self::assertSame( 0, $this->send( 'GET', '/admin/subscriptions', [ 'status' => 'cancelled' ] )->get_data()['total'] );

		$detail = $this->send( 'GET', '/admin/subscriptions/' . $row->id )->get_data();
		self::assertSame(
			[
				[
					'name'     => 'Test product',
					'quantity' => 1,
					'total'    => 1000,
				],
			],
			array_map( static fn ( array $item ): array => array_diff_key( $item, [ 'product_id' => 0 ] ), $detail['items'] )
		);
		self::assertSame( [ 'parent' ], array_column( $detail['orders'], 'relation' ) );
		self::assertSame( 'Lovelace', $detail['billing']['last_name'] );
		self::assertSame( 404, $this->send( 'GET', '/admin/subscriptions/999999' )->get_status() );

		$moved = $this->send( 'PATCH', '/admin/subscriptions/' . $row->id, [ 'next_payment_on' => '2099-06-15' ] );
		self::assertSame( 200, $moved->get_status() );
		self::assertSame( '2099-06-15', $moved->get_data()['next_payment_on'] );
		self::assertSame( substr( (string) $row->next_payment_at, 11 ), substr( (string) $this->subs()->find( $row->id )?->next_payment_at, 11 ), 'The time of day stays.' );
		self::assertSame( 400, $this->send( 'PATCH', '/admin/subscriptions/' . $row->id, [ 'next_payment_on' => '2001-01-01' ] )->get_status() );

		$renewed = $this->send( 'POST', '/admin/subscriptions/' . $row->id . '/actions', [ 'action' => 'renew' ] )->get_data();
		self::assertSame( 'on-hold', $renewed['status'] );
		self::assertNotNull( $renewed['unpaid_order_id'] );
		self::assertSame( [ 'renewal', 'parent' ], array_column( $renewed['orders'], 'relation' ) );
		$refused = $this->send( 'POST', '/admin/subscriptions/' . $row->id . '/actions', [ 'action' => 'reactivate' ] );
		self::assertSame( 400, $refused->get_status() );
		self::assertSame( 'cancelled', $this->send( 'POST', '/admin/subscriptions/' . $row->id . '/actions', [ 'action' => 'cancel' ] )->get_data()['status'] );
		self::assertSame( OrderStatus::CANCELLED, $this->orders()->get( (int) $renewed['unpaid_order_id'] )->status );

		wp_set_current_user( $this->user );
		self::assertSame( 403, $this->send( 'GET', '/admin/subscriptions' )->get_status() );
	}

	public function test_the_customer_sees_and_cancels_their_subscription_in_the_account(): void {
		Pages::install();
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row   = $this->only( $order );
		$other = self::factory()->user->create();

		wp_set_current_user( $this->user );
		$_GET = [ 'fxc_subscriptions' => '1' ];
		$html = $this->account()->render();
		self::assertStringContainsString( 'aria-current="page">Subscriptions', $html );
		self::assertStringContainsString( 'fxc_view_subscription=' . $row->id, $html );

		$_GET = [ 'fxc_view_subscription' => (string) $row->id ];
		$html = $this->account()->render();
		self::assertStringContainsString( 'Test product', $html );
		self::assertStringContainsString( 'subscription_do', $html );

		$_POST                   = [
			AccountPage::ACTION => 'subscription',
			AccountPage::NONCE  => wp_create_nonce( AccountPage::ACTION . '_subscription' ),
			'subscription_id'   => (string) $row->id,
			'subscription_do'   => 'cancel',
		];
		$_SERVER['HTTP_REFERER'] = home_url( '/my-account/' );
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new \RuntimeException( $location );
			}
		);
		try {
			$this->account()->handle();
			self::fail( 'No redirect.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'subscription_cancel', $e->getMessage() );
		}
		self::assertSame( SubscriptionStatus::PENDING_CANCEL, $this->subs()->find( $row->id )?->status, 'A customer cancels at the end of the paid period.' );

		wp_set_current_user( $other );
		$_POST = [];
		self::assertStringNotContainsString( 'subscription_do', $this->account()->render(), 'Another account cannot see it.' );
		$_GET = [];
		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function test_privacy_exports_and_erases_only_ended_subscriptions(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );

		Privacy::instance()->register();
		self::assertArrayHasKey( 'flexacommerce-subscriptions', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		self::assertArrayHasKey( 'flexacommerce-subscriptions', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
		$export = Privacy::instance()->export_subscriptions( 'ada@example.com', 1 );
		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		self::assertSame( '#' . $row->id . ' Test product', $fields['Subscription'] );
		self::assertSame( 'Active', $fields['Status'] );

		$kept = Privacy::instance()->erase_subscriptions( 'ada@example.com', 1 );
		self::assertSame( [ false, true ], [ $kept['items_removed'], $kept['items_retained'] ] );
		self::assertSame( 'ada@example.com', $this->subs()->find( $row->id )?->customer_email );

		$this->subs()->cancel( $row->id, false, TransitionContext::admin() );
		$gone = Privacy::instance()->erase_subscriptions( 'ada@example.com', 1 );
		self::assertSame( [ true, false ], [ $gone['items_removed'], $gone['items_retained'] ] );
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ '', null ], [ $row?->customer_email, $row?->billing ] );
	}

	private function account(): AccountPage {
		( new \ReflectionProperty( AccountPage::class, 'instance' ) )->setValue( null, null );
		return AccountPage::instance();
	}

	/** @param array<string,mixed> $params */
	private function send( string $method, string $path, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		if ( $method === 'GET' ) {
			$request->set_query_params( $params );
		} elseif ( $params !== [] ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function subs(): SubscriptionService {
		return Plugin::instance()->subscriptions();
	}

	/** @param array<string,mixed> $plan */
	private function plan_product( int $price, array $plan ): int {
		$id = $this->simple( $price );
		$this->save( $id, [ 'base' => [ 'subscription' => $plan ] ] );
		return $id;
	}

	private function only( Order $order ): Subscription {
		$rows = $this->subs()->repository()->for_parent( $order->id );
		self::assertCount( 1, $rows );
		return $rows[0];
	}

	private function due( Subscription $row ): void {
		$this->subs()->repository()->update( $row->id, [ 'next_payment_at' => '2020-01-01 00:00:00' ], '2020-01-01 00:00:00' );
	}

	/** @param array<string,string> $billing */
	private function buy( int $user_id, int $product_id, int $qty, array $billing = [], string $method = 'bacs' ): Order {
		$plugin  = Plugin::instance();
		$browser = new CartServices( $plugin->services(), $plugin->catalog(), new MemoryTokenStore() );
		$browser->cart()->add( $user_id, $product_id, $this->service()->get( $product_id )->base->id, $qty );
		$checkout = $this->checkout( $browser );
		$token    = $checkout->form_state( $user_id )['checkout_token'];
		return $checkout->place_order(
			CheckoutRequest::from_array(
				[
					'checkout_token' => $token,
					'billing'        => $billing + self::BILLING,
					'shipping_rate'  => $this->rate_id,
					'payment_method' => $method,
				],
				$user_id,
				'203.0.113.9'
			)
		)->order;
	}

	private function checkout( CartServices $browser ): CheckoutService {
		$plugin = Plugin::instance();
		return ( new CheckoutServices(
			$plugin->services(),
			$browser,
			new ShippingServices( $plugin->services() ),
			$plugin->orders(),
			$plugin->payments(),
			subscriptions: static fn (): SubscriptionService => Plugin::instance()->subscriptions()
		) )->service();
	}
}
