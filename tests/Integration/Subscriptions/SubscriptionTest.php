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
use Flexa\Commerce\Tests\Integration\Payments\FakeStripe;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeGateway;
use Flexa\Commerce\Notifications\Emails\CustomerRenewalInvoice;

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

	private bool $stripe = false;

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
		if ( $this->stripe ) {
			FakeStripe::uninstall();
			delete_option( GatewaySettings::PREFIX . StripeGateway::ID );
			delete_option( StripeGateway::STATUS_OPTION );
			\Flexa\Commerce\Support\WalletPlaces::put( StripeGateway::ID, [] );
			FakeStripe::forget( StripeGateway::ID );
		}
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

	public function test_stripe_saves_the_card_on_the_first_order_and_charges_renewals_with_it(): void {
		$this->stripe();
		FakeStripe::$routes['POST payment_intents'] = [ 200, self::intent( 'pi_first', 'succeeded', 1500 ) ];
		$order                                      = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1, [], StripeGateway::ID, self::CARD );
		$first                                      = FakeStripe::calls_to( 'POST', 'payment_intents' )[0]['body'];
		self::assertSame( [ 'cus_ada', 'off_session' ], [ $first['customer'], $first['setup_future_usage'] ] );
		self::assertSame( 'cus_ada', get_user_option( StripeGateway::CUSTOMER_OPTION . 'test', $this->user ), 'The customer is kept for the next order.' );
		$row = $this->only( $order );
		self::assertSame( SubscriptionStatus::ACTIVE, $row->status );

		// The cron reads the card the first payment saved.
		FakeStripe::$calls = [];
		self::assertSame( 0, $this->subs()->run() );
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ 'cus_ada', 'pm_visa', 'Visa ending in 4242', true ], [ $row?->payment_customer, $row?->payment_token, $row?->payment_label, $row?->is_automatic() ] );
		self::assertSame( [ 'payment_method' ], FakeStripe::calls_to( 'GET', 'payment_intents/pi_first' )[0]['query']['expand'] );

		self::assertNotNull( $row );
		$this->due( $row );
		FakeStripe::$routes['POST payment_intents'] = [ 200, self::intent( 'pi_renew', 'succeeded', 1500 ) ];
		$sent                                       = $this->watch( CustomerRenewalInvoice::DUE );
		self::assertSame( 1, $this->subs()->run() );
		$charge = array_slice( FakeStripe::calls_to( 'POST', 'payment_intents' ), -1 )[0]['body'];
		self::assertSame( [ 'cus_ada', 'pm_visa', 'true', 'true', '1' ], [ $charge['customer'], $charge['payment_method'], $charge['off_session'], $charge['confirm'], $charge['metadata']['fxc_renewal'] ] );
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 2, 'pm_visa' ], [ $row?->status, $row?->billed, $row?->payment_token ] );
		self::assertNotNull( $row );
		self::assertNull( $this->subs()->unpaid_renewal( $row ) );
		self::assertSame( [], $sent(), 'No invoice for a renewal the card paid.' );
	}

	public function test_a_declined_renewal_is_tried_again_then_left_to_the_customer(): void {
		$row                                        = $this->automatic();
		$sent                                       = $this->watch( CustomerRenewalInvoice::DUE );
		FakeStripe::$routes['POST payment_intents'] = [ 402, self::declined() ];
		$this->due( $row );
		reset_phpmailer_instance();
		self::assertSame( 1, $this->subs()->run() );
		$mails = array_values( array_filter( tests_retrieve_phpmailer_instance()->mock_sent, static fn ( array $m ): bool => str_contains( (string) $m['subject'], 'subscription payment failed' ) ) );
		self::assertCount( 1, $mails );
		self::assertSame( 'ada@example.com', $mails[0]['to'][0][0] );
		self::assertStringContainsString( 'Your card was declined', (string) $mails[0]['body'] );
		self::assertStringContainsString( 'Change the payment method', (string) $mails[0]['body'] );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( [ SubscriptionStatus::ON_HOLD, 1 ], [ $row->status, $row->retry_count ] );
		self::assertNotNull( $row->retry_at );
		self::assertSame( 1, $sent()[0]['attempt'] );
		self::assertStringContainsString( 'Your card was declined', (string) $sent()[0]['failed'] );
		self::assertFalse( $sent()[0]['final'] );
		$renewal = $this->subs()->unpaid_renewal( $row );
		self::assertNotNull( $renewal );

		// The second and third tries fail quietly; the fourth is the last and says so.
		foreach ( [ 2, 3, 4 ] as $attempt ) {
			$this->subs()->repository()->update( $row->id, [ 'retry_at' => '2020-01-01 00:00:00' ], '2020-01-01 00:00:00' );
			self::assertSame( 1, $this->subs()->run() );
			$row = $this->subs()->find( $row->id );
			self::assertNotNull( $row );
			self::assertSame( $attempt, $row->retry_count );
		}
		self::assertNull( $row->retry_at, 'No more tries.' );
		self::assertSame( [ 1, 4 ], array_column( $sent(), 'attempt' ) );
		self::assertTrue( $sent()[1]['final'] );
		self::assertSame( 0, $this->subs()->run(), 'Nothing left to try.' );

		// The merchant charges it again from the screen, and the card goes through this time.
		FakeStripe::$routes['POST payment_intents'] = [ 200, self::intent( 'pi_late', 'succeeded', 1500 ) ];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$done = $this->send( 'POST', '/admin/subscriptions/' . $row->id . '/actions', [ 'action' => 'retry' ] );
		self::assertSame( 200, $done->get_status() );
		$body = (array) $done->get_data();
		self::assertSame( [ 'succeeded', 'active', 0, null ], [ $body['charge']['result'], $body['status'], $body['retry_count'], $body['retry_at'] ] );
		self::assertSame( OrderStatus::PROCESSING, $this->orders()->find( $renewal->id )?->status );
	}

	public function test_a_charge_cut_off_before_its_answer_is_sent_again_with_the_same_key(): void {
		global $wpdb;
		$row                                        = $this->automatic();
		FakeStripe::$routes['POST payment_intents'] = [ 402, self::declined() ];
		$this->due( $row );
		$this->subs()->run();
		$renewal = $this->subs()->unpaid_renewal( $this->subs()->find( $row->id ) ?? $row );
		self::assertNotNull( $renewal );
		$payments = Plugin::instance()->payments()->transactions();
		$cut      = array_slice( $payments->for_order( $renewal->id ), -1 )[0];
		// As if the request died after Stripe took the money: the row stays pending, no reference.
		$wpdb->update( Schema::table( 'transactions' ), [ 'status' => 'pending' ], [ 'id' => $cut->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		FakeStripe::$routes['POST payment_intents'] = [ 200, self::intent( 'pi_late', 'succeeded', 1500 ) ];
		self::assertSame( 'succeeded', $this->subs()->retry_now( $row->id, new TransitionContext( 'admin', 1 ) )->type );
		self::assertSame( $cut->idempotency_key, array_slice( FakeStripe::calls_to( 'POST', 'payment_intents' ), -1 )[0]['headers']['Idempotency-Key'] );
		$rows = $payments->for_order( $renewal->id );
		self::assertCount( count( $rows ), array_filter( $rows, static fn ( $t ): bool => $t->id <= $cut->id ), 'No new payment row.' );
		self::assertSame( [ 'succeeded', 'pi_late' ], [ $payments->find( $cut->id )?->status, $payments->find( $cut->id )?->gateway_reference ] );
	}

	public function test_a_retry_that_goes_through_puts_the_subscription_back(): void {
		$row                                        = $this->automatic();
		FakeStripe::$routes['POST payment_intents'] = [ [ 402, self::declined() ], [ 200, self::intent( 'pi_ok', 'succeeded', 1500 ) ] ];
		$this->due( $row );
		$this->subs()->run();
		$this->subs()->repository()->update( $row->id, [ 'retry_at' => '2020-01-01 00:00:00' ], '2020-01-01 00:00:00' );
		self::assertSame( 1, $this->subs()->run() );
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 0, null, 'pm_visa' ], [ $row?->status, $row?->retry_count, $row?->retry_at, $row?->payment_token ] );
		$types = array_column( $this->subs()->repository()->events( (int) $row?->id, 20 ), 'type' );
		self::assertContains( 'payment-failed', $types );
		self::assertSame( 'renewal-paid', $types[0] );
	}

	public function test_the_customer_saves_a_new_card_and_the_unpaid_renewal_is_charged_to_it(): void {
		Pages::install();
		$row                                        = $this->automatic();
		FakeStripe::$routes['POST payment_intents'] = [ 402, self::declined() ];
		$this->due( $row );
		$this->subs()->run();

		wp_set_current_user( $this->user );
		$_GET = [ 'fxc_view_subscription' => (string) $row->id ];
		$html = $this->account()->render();
		self::assertStringContainsString( 'Visa ending in 4242, charged automatically', $html );
		self::assertStringContainsString( 'The automatic payment for your renewal failed. We will try again on', $html );
		self::assertStringContainsString( 'Change payment method', $html );

		FakeStripe::$routes['POST checkout/sessions'] = [
			200,
			[
				'id'  => 'cs_setup',
				'url' => 'https://checkout.stripe.com/c/pay/cs_setup',
			],
		];
		$_POST                                        = [
			AccountPage::ACTION => 'subscription',
			AccountPage::NONCE  => wp_create_nonce( AccountPage::ACTION . '_subscription' ),
			'subscription_id'   => (string) $row->id,
			'subscription_do'   => 'card',
		];
		$_SERVER['HTTP_REFERER']                      = home_url( '/my-account/' );
		self::assertSame( 'https://checkout.stripe.com/c/pay/cs_setup', $this->redirected() );
		$setup = FakeStripe::calls_to( 'POST', 'checkout/sessions' )[0]['body'];
		self::assertSame( [ 'setup', 'cus_ada', 'subscription-' . $row->id ], [ $setup['mode'], $setup['customer'], $setup['client_reference_id'] ] );
		self::assertStringContainsString( 'fxc_setup={CHECKOUT_SESSION_ID}', $setup['success_url'] );

		FakeStripe::$routes['GET checkout/sessions/cs_setup'] = [
			200,
			[
				'id'                  => 'cs_setup',
				'mode'                => 'setup',
				'status'              => 'complete',
				'client_reference_id' => 'subscription-' . $row->id,
				'customer'            => 'cus_ada',
				'setup_intent'        => [
					'id'             => 'seti_1',
					'status'         => 'succeeded',
					'payment_method' => self::method( 'pm_master', 'mastercard', '4444' ),
				],
			],
		];
		FakeStripe::$routes['POST payment_intents']           = [ 200, self::intent( 'pi_new_card', 'succeeded', 1500 ) ];
		$_POST = [];
		$_GET  = [
			'fxc_view_subscription' => (string) $row->id,
			'fxc_setup'             => 'cs_setup',
		];
		self::assertStringContainsString( 'subscription_card_paid', $this->redirected() );
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 'pm_master', 'Mastercard ending in 4444', 0 ], [ $row?->status, $row?->payment_token, $row?->payment_label, $row?->retry_count ] );
		self::assertSame( 'pm_master', array_slice( FakeStripe::calls_to( 'POST', 'payment_intents' ), -1 )[0]['body']['payment_method'] );

		// A session for another subscription is refused.
		$_GET = [
			'fxc_view_subscription' => (string) $row?->id,
			'fxc_setup'             => 'cs_other',
		];
		FakeStripe::$routes['GET checkout/sessions/cs_other'] = [ 200, [ 'client_reference_id' => 'subscription-999' ] + FakeStripe::$routes['GET checkout/sessions/cs_setup'][1] ];
		$this->account()->handle();
		self::assertSame( 'pm_master', $this->subs()->find( (int) $row?->id )?->payment_token );
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

	private const CARD = [
		'payment_method'      => 'pm_visa',
		'payment_method_type' => 'card',
	];

	/** Stripe in test mode against FakeStripe, with a customer to make and the card the first payment saved. */
	private function stripe(): void {
		$this->stripe = true;
		FakeStripe::install();
		FakeStripe::connectable();
		Plugin::instance()->payments()->gateways()->settings()->save(
			Plugin::instance()->payments()->gateways()->get( StripeGateway::ID ) ?? throw new \LogicException( 'No Stripe.' ),
			[
				'enabled'              => true,
				'mode'                 => 'test',
				'test_publishable_key' => 'pk_test_abc123',
				'test_secret_key'      => 'sk_test_abc123',
			]
		);
		FakeStripe::$routes['POST customers']               = [ 200, [ 'id' => 'cus_ada' ] ];
		FakeStripe::$routes['GET payment_intents/pi_first'] = [
			200,
			self::intent(
				'pi_first',
				'succeeded',
				1500,
				[
					'customer'       => 'cus_ada',
					'payment_method' => self::method( 'pm_visa', 'visa', '4242' ),
				]
			),
		];
	}

	/** An active row paid by Stripe, its card read by the cron. */
	private function automatic(): Subscription {
		$this->stripe();
		FakeStripe::$routes['POST payment_intents'] = [ 200, self::intent( 'pi_first', 'succeeded', 1500 ) ];
		$order                                      = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1, [], StripeGateway::ID, self::CARD );
		$this->subs()->run();
		$row = $this->subs()->find( $this->only( $order )->id );
		self::assertNotNull( $row );
		self::assertTrue( $row->is_automatic() );
		return $row;
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private static function intent( string $id, string $status, int $amount, array $extra = [] ): array {
		return $extra + [
			'id'              => $id,
			'object'          => 'payment_intent',
			'status'          => $status,
			'amount'          => $amount,
			'amount_received' => $status === 'succeeded' ? $amount : 0,
			'currency'        => 'usd',
			'livemode'        => false,
		];
	}

	/** @return array<string,mixed> */
	private static function method( string $id, string $brand, string $last4 ): array {
		return [
			'id'       => $id,
			'object'   => 'payment_method',
			'type'     => 'card',
			'customer' => 'cus_ada',
			'card'     => [
				'brand' => $brand,
				'last4' => $last4,
			],
		];
	}

	/** @return array<string,mixed> */
	private static function declined(): array {
		return [
			'error' => [
				'type'         => 'card_error',
				'code'         => 'card_declined',
				'decline_code' => 'insufficient_funds',
				'message'      => 'Your card was declined.',
			],
		];
	}

	/**
	 * Collects the context of each call to a hook.
	 *
	 * @return \Closure(): list<array<string,mixed>>
	 */
	private function watch( string $hook ): \Closure {
		$seen = [];
		add_action(
			$hook,
			static function ( mixed $order, array $context ) use ( &$seen ): void {
				unset( $order );
				$seen[] = $context;
			},
			10,
			2
		);
		return static function () use ( &$seen ): array {
			return $seen;
		};
	}

	/** Where AccountPage::handle() sends the browser. */
	private function redirected(): string {
		$stop = static function ( string $location ): never {
			throw new \RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $stop );
		try {
			$this->account()->handle();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $stop );
		}
		self::fail( 'No redirect.' );
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

	/**
	 * @param array<string,string> $billing
	 * @param array<string,string> $input
	 */
	private function buy( int $user_id, int $product_id, int $qty, array $billing = [], string $method = 'bacs', array $input = [] ): Order {
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
					'gateway_input'  => $input,
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
