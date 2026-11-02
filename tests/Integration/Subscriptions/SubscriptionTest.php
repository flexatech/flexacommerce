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
use Flexa\Commerce\Tests\Integration\Payments\FakePayPal;
use Flexa\Commerce\Tests\Integration\Payments\FakeStripe;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalClient;
use Flexa\Commerce\Payments\Gateways\PayPal\PayPalGateway;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Payments\Gateways\Stripe\StripeGateway;
use Flexa\Commerce\Notifications\Emails\CustomerRenewalInvoice;
use Flexa\Commerce\Notifications\Emails\CustomerRenewalReminder;
use Flexa\Commerce\Notifications\EmailSettings;

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

	private bool $paypal = false;

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
		if ( $this->paypal ) {
			FakePayPal::uninstall();
			delete_option( GatewaySettings::PREFIX . PayPalGateway::ID );
			delete_option( PayPalGateway::STATUS_OPTION );
			delete_option( PayPalClient::TOKEN_OPTION );
			\Flexa\Commerce\Support\WalletPlaces::put( PayPalGateway::ID, [] );
			FakeStripe::forget( PayPalGateway::ID );
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

	public function test_paypal_vaults_the_account_on_the_first_order_and_charges_renewals_with_it(): void {
		$this->paypal();
		FakePayPal::$routes['POST v2/checkout/orders'] = [ 201, self::pp_order( 'PP1', 'PAYER_ACTION_REQUIRED' ) ];
		$order  = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1, [], PayPalGateway::ID );
		$source = FakePayPal::calls_to( 'POST', 'v2/checkout/orders' )[0]['body']['payment_source']['paypal'];
		self::assertSame(
			[
				'store_in_vault' => 'ON_SUCCESS',
				'usage_type'     => 'MERCHANT',
				'customer_type'  => 'CONSUMER',
			],
			$source['attributes']['vault']
		);
		FakePayPal::$routes['GET v2/checkout/orders/PP1']          = [ 200, self::pp_order( 'PP1', 'APPROVED' ) ];
		FakePayPal::$routes['POST v2/checkout/orders/PP1/capture'] = [ 201, self::pp_paid( 'PP1', 'APPROVED' ) ];
		$this->pay_return( $order, 'PP1' );
		$row = $this->only( $order );
		self::assertSame( SubscriptionStatus::ACTIVE, $row->status );

		// PayPal is still making the vault entry: the row is asked again later, not left to renew by hand.
		FakePayPal::$routes['GET v2/checkout/orders/PP1'] = [ 200, self::pp_paid( 'PP1', 'APPROVED' ) ];
		$this->subs()->run();
		self::assertNull( $this->subs()->find( $row->id )?->payment_token );

		FakePayPal::$routes['GET v2/checkout/orders/PP1'] = [ 200, self::pp_paid( 'PP1', 'VAULTED' ) ];
		$this->subs()->run();
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ PayPalGateway::ID, 'PPCUS1', 'VAULT1', 'PayPal (ada@example.com)', true ], [ $row?->payment_method, $row?->payment_customer, $row?->payment_token, $row?->payment_label, $row?->is_automatic() ] );

		self::assertNotNull( $row );
		$this->due( $row );
		FakePayPal::$routes['POST v2/checkout/orders'] = [ 201, self::pp_paid( 'PPR1', '' ) ];
		$sent = $this->watch( CustomerRenewalInvoice::DUE );
		self::assertSame( 1, $this->subs()->run() );
		$charge = array_slice( FakePayPal::calls_to( 'POST', 'v2/checkout/orders' ), -1 )[0];
		self::assertSame( [ 'vault_id' => 'VAULT1' ], $charge['body']['payment_source']['paypal'] );
		self::assertSame( '15.00', $charge['body']['purchase_units'][0]['amount']['value'] );
		self::assertNotSame( '', $charge['headers']['PayPal-Request-Id'] );
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 2 ], [ $row?->status, $row?->billed ] );
		self::assertNotNull( $row );
		self::assertNull( $this->subs()->unpaid_renewal( $row ) );
		self::assertSame( [], $sent(), 'No invoice for a renewal PayPal paid.' );
	}

	public function test_the_wallet_quote_says_when_the_cart_holds_a_plan(): void {
		$plugin  = Plugin::instance();
		$browser = new CartServices( $plugin->services(), $plugin->catalog(), new MemoryTokenStore() );
		$plain   = $this->simple( 800 );
		$browser->cart()->add( $this->user, $plain, $this->service()->get( $plain )->base->id, 1 );
		self::assertFalse( $this->checkout( $browser )->express_quote( $this->user, null, null, '', 'paypal' )['recurring'] );
		$plan = $this->plan_product( 1000, [ 'period' => 'month' ] );
		$browser->cart()->add( $this->user, $plan, $this->service()->get( $plan )->base->id, 1 );
		self::assertTrue( $this->checkout( $browser )->express_quote( $this->user, null, null, '', 'paypal' )['recurring'] );
	}

	public function test_a_declined_paypal_renewal_is_tried_again(): void {
		$row = $this->paypal_automatic();
		$this->due( $row );
		FakePayPal::$routes['POST v2/checkout/orders'] = [ 422, self::pp_declined() ];
		reset_phpmailer_instance();
		$this->subs()->run();
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ SubscriptionStatus::ON_HOLD, 1 ], [ $row?->status, $row?->retry_count ] );
		self::assertNotNull( $row );
		$order = $this->subs()->unpaid_renewal( $row );
		self::assertNotNull( $order );
		$failed = array_values( array_filter( $this->payments_of( $order->id ), static fn ( Transaction $t ): bool => $t->status === Transaction::FAILED ) );
		self::assertSame( 'instrument_declined', $failed[0]->failure_code ?? '' );
		self::assertCount( 1, $this->mails( 'subscription payment failed' ) );
	}

	public function test_the_customer_approves_another_paypal_account_and_the_unpaid_renewal_is_charged_to_it(): void {
		Pages::install();
		$row = $this->paypal_automatic();
		$this->due( $row );
		FakePayPal::$routes['POST v2/checkout/orders'] = [ 422, self::pp_declined() ];
		$this->subs()->run();

		wp_set_current_user( $this->user );
		$_GET = [ 'fxc_view_subscription' => (string) $row->id ];
		self::assertStringContainsString( 'PayPal (ada@example.com), charged automatically', $this->account()->render() );

		FakePayPal::$routes['POST v3/vault/setup-tokens'] = [
			201,
			[
				'id'     => 'SETUP1',
				'status' => 'PAYER_ACTION_REQUIRED',
				'links'  => [
					[
						'rel'  => 'approve',
						'href' => 'https://www.sandbox.paypal.com/agreements/approve?approval_session_id=SETUP1',
					],
				],
			],
		];
		$_POST                   = $this->subscription_post( $row, 'card', [] );
		$_SERVER['HTTP_REFERER'] = home_url( '/my-account/' );
		self::assertSame( 'https://www.sandbox.paypal.com/agreements/approve?approval_session_id=SETUP1', $this->redirected() );
		$setup = FakePayPal::calls_to( 'POST', 'v3/vault/setup-tokens' )[0]['body'];
		self::assertSame( [ 'id' => 'PPCUS1' ], $setup['customer'] );
		self::assertSame( [ 'MERCHANT', 'ada@example.com' ], [ $setup['payment_source']['paypal']['usage_type'], $setup['payment_source']['paypal']['email_address'] ] );
		parse_str( (string) wp_parse_url( $setup['payment_source']['paypal']['experience_context']['return_url'], PHP_URL_QUERY ), $back );
		$key = (string) ( $back['fxc_setup'] ?? '' );
		self::assertSame( (string) $row->id, $back['fxc_view_subscription'] ?? '' );
		self::assertMatchesRegularExpression( '/^[a-z0-9]{24}$/', $key );

		FakePayPal::$routes['POST v3/vault/payment-tokens'] = [
			201,
			[
				'id'             => 'VAULT2',
				'customer'       => [ 'id' => 'PPCUS1' ],
				'payment_source' => [ 'paypal' => [ 'email_address' => 'ada.new@example.com' ] ],
			],
		];
		FakePayPal::$routes['POST v2/checkout/orders']      = [ 201, self::pp_paid( 'PPR2', '' ) ];
		$_POST = [];
		$_GET  = [
			'fxc_view_subscription' => (string) $row->id,
			'fxc_setup'             => $key,
			'approval_token_id'     => 'SETUP1',
		];
		self::assertStringContainsString( 'subscription_card_paid', $this->redirected() );
		self::assertSame(
			[
				'id'   => 'SETUP1',
				'type' => 'SETUP_TOKEN',
			],
			FakePayPal::calls_to( 'POST', 'v3/vault/payment-tokens' )[0]['body']['payment_source']['token']
		);
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 'VAULT2', 'PayPal (ada.new@example.com)', 0 ], [ $row?->status, $row?->payment_token, $row?->payment_label, $row?->retry_count ] );
		self::assertSame( [ 'vault_id' => 'VAULT2' ], array_slice( FakePayPal::calls_to( 'POST', 'v2/checkout/orders' ), -1 )[0]['body']['payment_source']['paypal'] );

		// The key works once, and only for the subscription it was made for.
		FakePayPal::$calls = [];
		$this->account()->handle();
		set_transient(
			'fxc_paypal_setup_' . str_repeat( 'a', 24 ),
			[
				'token' => 'SETUP9',
				'tag'   => 'subscription-999',
				'mode'  => 'test',
			],
			HOUR_IN_SECONDS
		);
		$_GET['fxc_setup'] = str_repeat( 'a', 24 );
		$this->account()->handle();
		self::assertSame( [], FakePayPal::calls_to( 'POST', 'v3/vault/payment-tokens' ) );
		self::assertSame( 'VAULT2', $this->subs()->find( (int) $row?->id )?->payment_token );
		delete_transient( 'fxc_paypal_setup_' . str_repeat( 'a', 24 ) );
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
	public function test_a_reminder_goes_out_days_before_each_renewal_once(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );
		$this->renews_in( $row, 2 );
		reset_phpmailer_instance();
		$this->subs()->run();
		$mails = $this->mails( 'subscription renews on' );
		self::assertCount( 1, $mails );
		self::assertSame( 'ada@example.com', $mails[0]['to'][0][0] );
		self::assertStringContainsString( 'email you a link to pay', (string) $mails[0]['body'] );
		self::assertStringContainsString( 'Manage your subscription', (string) $mails[0]['body'] );
		$types = array_column( $this->subs()->repository()->events( $row->id, 20 ), 'type' );
		self::assertContains( 'reminder', $types );

		reset_phpmailer_instance();
		$this->subs()->run();
		self::assertSame( [], $this->mails( 'subscription renews on' ), 'Once per renewal date.' );

		// A new date is a new reminder; one past the notice waits for its turn.
		$this->renews_in( $row, 1 );
		$this->subs()->run();
		self::assertCount( 1, $this->mails( 'subscription renews on' ) );
		reset_phpmailer_instance();
		$this->renews_in( $row, 5 );
		$this->subs()->run();
		self::assertSame( [], $this->mails( 'subscription renews on' ) );
	}

	public function test_the_reminder_names_the_card_and_follows_the_email_settings(): void {
		$row      = $this->automatic();
		$settings = Plugin::instance()->notifications()->settings();
		$settings->update( CustomerRenewalReminder::ID, [ 'days' => 10 ] );
		$this->renews_in( $row, 8 );
		reset_phpmailer_instance();
		$this->subs()->run();
		$mails = $this->mails( 'subscription renews on' );
		self::assertCount( 1, $mails, 'Ten days ahead, as set.' );
		self::assertStringContainsString( 'We will charge Visa ending in 4242 on that day', (string) $mails[0]['body'] );

		$settings->update( CustomerRenewalReminder::ID, [ 'enabled' => false ] );
		$this->renews_in( $row, 7 );
		reset_phpmailer_instance();
		$this->subs()->run();
		self::assertSame( [], $this->mails( 'subscription renews on' ), 'Off.' );
		$row = $this->subs()->find( $row->id );
		self::assertNotSame( $row?->next_payment_at, $row?->reminded_for, 'Nothing claimed while off.' );
		delete_option( EmailSettings::PREFIX . CustomerRenewalReminder::ID );
	}

	public function test_a_trial_end_is_reminded_and_short_periods_are_not(): void {
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
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$trial = $this->only( $order );
		$this->renews_in( $trial, 2 );
		$ends = $this->subs()->find( $trial->id )?->next_payment_at;
		$this->subs()->repository()->update(
			$trial->id,
			[
				'start_at'     => gmdate( 'Y-m-d H:i:s', time() - 12 * DAY_IN_SECONDS ),
				'trial_end_at' => $ends,
			],
			gmdate( 'Y-m-d H:i:s' )
		);
		reset_phpmailer_instance();
		$this->subs()->run();
		$mails = $this->mails( 'free trial ends on' );
		self::assertCount( 1, $mails );
		self::assertStringContainsString( 'First payment', (string) $mails[0]['body'] );

		$daily = $this->buy( $this->user, $this->plan_product( 300, [ 'period' => 'day' ] ), 1 );
		$this->orders()->record_payment( $daily->id, $daily->total, TransitionContext::system() );
		$row = $this->only( $daily );
		self::assertNotNull( $row->next_payment_at );
		reset_phpmailer_instance();
		$this->subs()->run();
		self::assertSame( [], $this->mails( 'subscription renews on' ), 'A one-day period is shorter than the notice.' );
		self::assertSame( $row->next_payment_at, $this->subs()->find( $row->id )?->reminded_for, 'Claimed, so it is not read again.' );
	}

	public function test_the_emails_screen_shows_and_saves_the_reminder_days(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$list = (array) $this->send( 'GET', '/admin/emails' )->get_data();
		$days = array_column( (array) $list['emails'], 'days', 'id' );
		self::assertSame( [ 3, null ], [ $days[ CustomerRenewalReminder::ID ], $days['customer_renewal_invoice'] ] );
		self::assertTrue( array_key_exists( 'customer_renewal_invoice', $days ) );

		$saved = $this->send( 'PATCH', '/admin/emails/' . CustomerRenewalReminder::ID, [ 'days' => 7 ] );
		self::assertSame( 7, ( (array) $saved->get_data() )['days'] );
		self::assertSame( 7, Plugin::instance()->notifications()->days_before( CustomerRenewalReminder::ID ) );
		self::assertSame( 400, $this->send( 'PATCH', '/admin/emails/' . CustomerRenewalReminder::ID, [ 'days' => 0 ] )->get_status() );
		$other = $this->send( 'PATCH', '/admin/emails/customer_renewal_invoice', [ 'days' => 7 ] );
		self::assertNull( ( (array) $other->get_data() )['days'] );
		self::assertSame( 0, Plugin::instance()->notifications()->settings()->get( 'customer_renewal_invoice' )['days'] );
		delete_option( EmailSettings::PREFIX . CustomerRenewalReminder::ID );
		delete_option( EmailSettings::PREFIX . 'customer_renewal_invoice' );
	}

	/** Moves the next payment to this many days from now, less an hour. */
	public function test_the_customer_changes_quantities_priced_at_todays_prices(): void {
		$coffee = $this->plan_product( 1000, [ 'period' => 'month' ] );
		$filter = $this->plan_product( 500, [ 'period' => 'month' ] );
		$order  = $this->buy( $this->user, $coffee, 1, more: [ $filter => 1 ] );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );
		self::assertSame( 2000, $row->total, 'Ten and five, shipping five.' );
		self::assertTrue( $this->subs()->can_change( $row ) );
		$at = self::indexes( $row );

		$quote = $this->subs()->quote_change(
			$row,
			[
				$at[ $coffee ] => 2,
				$at[ $filter ] => 0,
			],
			null
		);
		self::assertSame( 2500, $quote->total, 'The filter is dropped.' );
		$row = $this->subs()->apply_change( $row, $quote, null, new TransitionContext( 'customer', $this->user ) );
		self::assertSame( [ 2500, 'Test product × 2' ], [ $row->total, $row->title() ] );
		$event = $this->subs()->repository()->events( $row->id, 1 )[0];
		self::assertSame( 'changed', $event['type'] );
		self::assertStringContainsString( 'Items changed to Test product × 2.', (string) $event['message'] );

		$this->save( $coffee, [ 'base' => [ 'regular_price' => 1200 ] ] );
		Plugin::instance()->cart()->pricing()->reset(); // Prices are kept for the request; a real change comes in a later one.
		self::assertSame( 2900, $this->subs()->quote_change( $row, [], null )->total, 'A change bills the price of today.' );

		try {
			$this->subs()->quote_change( $row, [ 0 => 0 ], null );
			self::fail( 'No item left.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( 'Keep at least one item', $e->getMessage() );
		}

		$this->save( $coffee, [ 'base' => [ 'subscription' => [ 'period' => 'week' ] ] ] );
		$this->expectExceptionMessage( 'The plan of this product changed' );
		$this->subs()->quote_change( $row, [ 0 => 3 ], null );
	}

	public function test_a_renewal_waiting_for_payment_keeps_what_it_holds(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1, [], 'cod' );
		$row   = $this->only( $order );
		$this->due( $row );
		$renewal = $this->subs()->renew( $row->id, TransitionContext::admin() );
		self::assertNotNull( $renewal );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertFalse( $this->subs()->can_change( $row ), 'On hold for the unpaid renewal.' );

		$this->orders()->record_payment( $renewal->id, $renewal->total, TransitionContext::system() );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		$row = $this->subs()->apply_change( $row, $this->subs()->quote_change( $row, [ 0 => 3 ], null ), null, TransitionContext::admin() );
		self::assertSame( 3500, $row->total );
		self::assertSame( 1500, $this->orders()->find( $renewal->id )?->total, 'The paid renewal is left alone.' );
	}

	public function test_the_account_page_previews_a_new_price_and_saves_on_confirm(): void {
		Pages::install();
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );
		wp_set_current_user( $this->user );
		$_SERVER['HTTP_REFERER'] = home_url( '/my-account/' );
		$_GET                    = [ 'fxc_view_subscription' => (string) $row->id ];
		$html                    = $this->account()->render();
		self::assertStringContainsString( 'name="subscription_qty[0]"', $html );
		self::assertStringContainsString( 'Update quantities', $html );
		self::assertStringContainsString( 'Save address', $html );
		self::assertStringContainsString( 'Pause subscription', $html );

		$_POST = $this->subscription_post( $row, 'change', [ 'subscription_qty' => [ 0 => '2' ] ] );
		$page  = $this->account();
		$page->handle();
		self::assertSame( [], $page->errors() );
		$html = $page->render();
		self::assertStringContainsString( 'Review your change', $html );
		self::assertStringContainsString( 'name="subscription_total" value="2500"', $html );
		self::assertSame( 1500, $this->subs()->find( $row->id )?->total, 'Nothing is saved before the confirm.' );

		$_POST = $this->subscription_post(
			$row,
			'change',
			[
				'subscription_qty'     => [ 0 => '2' ],
				'subscription_total'   => '2500',
				'subscription_confirm' => '1',
			]
		);
		self::assertStringContainsString( 'subscription_change', $this->redirected() );
		self::assertSame( 2500, $this->subs()->find( $row->id )?->total );

		// The same price: saved at once.
		$address = [
			'address_1' => '2 Main St',
			'city'      => 'Austin',
			'state'     => 'TX',
			'postcode'  => '73301',
		] + self::BILLING;
		$_POST   = $this->subscription_post( $row, 'change', [ 'subscription_address' => '1' ] + [ 'shipping' => $address ] );
		self::assertStringContainsString( 'subscription_change', $this->redirected() );
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ 'Austin', 'Cupertino', 2500 ], [ $row?->shipping?->city, $row?->billing?->city, $row?->total ], 'Only the shipping address moves.' );
		self::assertStringContainsString( 'Shipping address changed.', (string) $this->subs()->repository()->events( (int) $row?->id, 1 )[0]['message'] );

		$_POST = $this->subscription_post(
			$row ?? throw new \LogicException(),
			'change',
			[
				'subscription_address' => '1',
				'shipping'             => [
					'country'  => 'GB',
					'city'     => 'London',
					'postcode' => 'SW1A 1AA',
				] + $address,
			]
		);
		$page  = $this->account();
		$page->handle();
		self::assertSame( 'We cannot ship this subscription to that address.', $page->errors()['form'] ?? '' );

		$_POST = $this->subscription_post( $row, 'pause', [] );
		self::assertStringContainsString( 'subscription_pause', $this->redirected() );
		$_POST = [];
		$html  = $this->account()->render();
		self::assertStringContainsString( 'Resume subscription', $html );
		self::assertStringNotContainsString( 'Pause subscription', $html );
		$_POST = $this->subscription_post( $row, 'resume', [] );
		self::assertStringContainsString( 'subscription_resume', $this->redirected() );
		self::assertSame( SubscriptionStatus::ACTIVE, $this->subs()->find( $row->id )?->status );
		$_GET = [];
		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function test_a_paused_subscription_resumes_on_time_or_renews_from_today(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row  = $this->only( $order );
		$next = $row->next_payment_at;
		$ctx  = new TransitionContext( 'customer', $this->user );

		$row = $this->subs()->pause( $row->id, $ctx );
		self::assertSame( SubscriptionStatus::ON_HOLD, $row->status );
		self::assertTrue( $row->is_paused() );
		self::assertTrue( $this->subs()->can_change( $row ), 'A paused row can still be changed.' );
		self::assertNull( $this->subs()->resume( $row->id, $ctx ), 'Not due: nothing to bill.' );
		$row = $this->subs()->find( $row->id );
		self::assertSame( [ SubscriptionStatus::ACTIVE, $next, null ], [ $row?->status, $row?->next_payment_at, $row?->paused_at ] );

		self::assertNotNull( $row );
		$this->subs()->pause( $row->id, $ctx );
		$this->due( $row );
		self::assertSame( 0, $this->subs()->run(), 'A paused row does not renew.' );
		$renewal = $this->subs()->resume( $row->id, $ctx );
		self::assertNotNull( $renewal, 'The payment day passed: it renews now.' );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( (int) gmdate( 'j' ), $row->anchor_day, 'The next periods count from today.' );
		self::assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), (string) $row->next_payment_at );
		self::assertSame( [ 'paused', 'resumed', 'paused', 'resumed', 'renewal' ], array_slice( array_reverse( array_column( $this->subs()->repository()->events( $row->id, 20 ), 'type' ) ), 2 ) );
	}

	public function test_resuming_a_paused_stripe_row_charges_the_card(): void {
		$row = $this->automatic();
		$ctx = new TransitionContext( 'customer', $this->user );
		$this->subs()->pause( $row->id, $ctx );
		$this->due( $row );
		FakeStripe::$routes['POST payment_intents'] = [ 200, self::intent( 'pi_renew', 'succeeded', 1500 ) ];
		self::assertNotNull( $this->subs()->resume( $row->id, $ctx ) );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( [ SubscriptionStatus::ACTIVE, 2 ], [ $row->status, $row->billed ] );
		self::assertNull( $this->subs()->unpaid_renewal( $row ) );
	}

	public function test_a_merchant_hold_stays_with_the_merchant_and_resumes_once(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1, [], 'cod' );
		$row   = $this->only( $order );
		$row   = $this->subs()->set_hold( $row->id, true, TransitionContext::admin() );
		self::assertFalse( $row->is_paused() );
		self::assertFalse( $this->subs()->can_change( $row ) );
		try {
			$this->subs()->resume( $row->id, new TransitionContext( 'customer', $this->user ) );
			self::fail( 'The customer resumed a merchant hold.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( 'Only a subscription you paused', $e->getMessage() );
		}

		$this->due( $row );
		$this->subs()->set_hold( $row->id, false, TransitionContext::admin() );
		self::assertSame( 1, $this->subs()->run() );
		$renewal = $this->subs()->unpaid_renewal( $this->subs()->find( $row->id ) ?? $row );
		self::assertNotNull( $renewal );
		$this->orders()->record_payment( $renewal->id, $renewal->total, TransitionContext::system() );
		self::assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), (string) $this->subs()->find( $row->id )?->next_payment_at, 'No catching up on missed periods.' );
		self::assertSame( 0, $this->subs()->run() );
	}

	public function test_cancelling_a_paused_row_keeps_the_period_it_paid_for(): void {
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1 );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row  = $this->only( $order );
		$next = $row->next_payment_at;
		$ctx  = new TransitionContext( 'customer', $this->user );
		$this->subs()->pause( $row->id, $ctx );
		$row = $this->subs()->cancel( $row->id, true, $ctx );
		self::assertSame( [ SubscriptionStatus::PENDING_CANCEL, $next, null ], [ $row->status, $row->end_at, $row->paused_at ] );
	}

	public function test_an_upgrade_on_the_same_schedule_bills_the_difference_for_the_rest_of_the_period(): void {
		$monthly       = [ 'period' => 'month' ];
		[ $row, $ids ] = $this->sized_row(
			[
				'S' => [ 1000, $monthly ],
				'M' => [ 2000, $monthly ],
			],
			'S'
		);
		$next          = $row->next_payment_at;
		$customer      = new TransitionContext( 'customer', $this->user );
		$quote         = $this->subs()->quote_switch( $row, [ 0 => $ids['M'] ] );
		self::assertTrue( $quote->same_schedule );
		self::assertSame( 2500, $quote->snapshot->total );
		self::assertEqualsWithDelta( 1500, $quote->credit, 2, 'A whole period is left.' );
		self::assertEqualsWithDelta( 1000, $quote->charge, 2 );

		$order = $this->subs()->apply_switch( $row, $quote, $customer );
		self::assertNotNull( $order );
		self::assertSame( [ OrderStatus::PENDING, $quote->charge ], [ $order->status, $order->total ] );
		self::assertSame( [ 'Plan switch for the rest of the period' ], array_map( static fn ( $item ): string => $item->name, $order->items ) );
		self::assertSame( 1500, $this->subs()->find( $row->id )?->total, 'Nothing changes before it is paid.' );
		self::assertSame( $order->id, $this->subs()->pending_switch( $row )?->id );

		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$this->orders()->transition( $order->id, OrderStatus::COMPLETED, TransitionContext::admin() );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( [ 2500, $next, $ids['M'] ], [ $row->total, $row->next_payment_at, $row->snapshot?->items[0]->variant_id ] );
		self::assertNull( $this->subs()->pending_switch( $row ) );
		self::assertCount( 1, array_filter( $this->subs()->repository()->events( $row->id ), static fn ( array $e ): bool => $e['type'] === 'switched' ), 'Applied once.' );
		self::assertContains( 'switch', array_column( $this->subs()->repository()->orders( $row->id ), 'relation' ) );
	}

	public function test_a_downgrade_on_the_same_schedule_applies_now_and_bills_the_next_renewal(): void {
		$monthly       = [ 'period' => 'month' ];
		[ $row, $ids ] = $this->sized_row(
			[
				'S' => [ 1000, $monthly ],
				'M' => [ 2000, $monthly ],
			],
			'M'
		);
		$quote         = $this->subs()->quote_switch( $row, [ 0 => $ids['S'] ] );
		self::assertSame( 0, $quote->charge );
		self::assertNull( $this->subs()->apply_switch( $row, $quote, new TransitionContext( 'customer', $this->user ) ) );
		$after = $this->subs()->find( $row->id );
		self::assertSame( [ 1500, $row->next_payment_at, 'month' ], [ $after?->total, $after?->next_payment_at, $after?->plan->period ] );
	}

	public function test_another_schedule_starts_a_new_period_less_the_credit(): void {
		[ $row, $ids ] = $this->sized_row(
			[
				'S' => [ 1000, [ 'period' => 'month' ] ],
				'L' => [ 10000, [ 'period' => 'year' ] ],
			],
			'S'
		);
		$quote         = $this->subs()->quote_switch( $row, [ 0 => $ids['L'] ] );
		self::assertFalse( $quote->same_schedule );
		self::assertSame( 10500, $quote->snapshot->total );
		self::assertSame( 10500 - $quote->credit, $quote->charge );
		$order = $this->subs()->apply_switch( $row, $quote, new TransitionContext( 'customer', $this->user ) );
		self::assertNotNull( $order );
		$credit = array_values( array_filter( $order->items, static fn ( $item ): bool => ! empty( $item->meta['switch_credit'] ) ) );
		self::assertCount( 1, $credit );
		self::assertSame( -$quote->credit, $credit[0]->total );

		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( [ 'year', 1, 10500 ], [ $row->plan->period, $row->billed, $row->total ] );
		self::assertGreaterThan( gmdate( 'Y-m-d H:i:s', time() + 360 * DAY_IN_SECONDS ), (string) $row->next_payment_at );
	}

	public function test_a_credit_larger_than_the_new_price_moves_the_first_payment_out(): void {
		[ $row, $ids ] = $this->sized_row(
			[
				'S' => [ 1000, [ 'period' => 'month' ] ],
				'L' => [ 10000, [ 'period' => 'year' ] ],
			],
			'L'
		);
		$quote         = $this->subs()->quote_switch( $row, [ 0 => $ids['S'] ] );
		self::assertSame( 0, $quote->charge );
		self::assertNull( $this->subs()->apply_switch( $row, $quote, new TransitionContext( 'customer', $this->user ) ) );
		$row = $this->subs()->find( $row->id );
		self::assertNotNull( $row );
		self::assertSame( [ 'month', 0, 1500 ], [ $row->plan->period, $row->billed, $row->total ] );
		// 10500 of credit pays for seven periods of 1500.
		self::assertGreaterThan( gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ), (string) $row->next_payment_at );
		self::assertLessThan( gmdate( 'Y-m-d H:i:s', time() + 220 * DAY_IN_SECONDS ), (string) $row->next_payment_at );
	}

	public function test_a_switch_during_the_free_trial_costs_nothing(): void {
		$trial         = [
			'period'     => 'month',
			'trial_days' => 7,
		];
		[ $row, $ids ] = $this->sized_row(
			[
				'S' => [ 1000, $trial ],
				'M' => [ 2000, $trial ],
			],
			'S'
		);
		self::assertTrue( $row->in_trial( gmdate( 'Y-m-d H:i:s' ) ) );
		$quote = $this->subs()->quote_switch( $row, [ 0 => $ids['M'] ] );
		self::assertSame( [ 0, 0 ], [ $quote->credit, $quote->charge ] );
		self::assertNull( $this->subs()->apply_switch( $row, $quote, new TransitionContext( 'customer', $this->user ) ) );
		$after = $this->subs()->find( $row->id );
		self::assertSame( [ 2500, $row->next_payment_at ], [ $after?->total, $after?->next_payment_at ] );
	}

	public function test_a_renewal_cancels_an_unpaid_switch(): void {
		$monthly       = [ 'period' => 'month' ];
		[ $row, $ids ] = $this->sized_row(
			[
				'S' => [ 1000, $monthly ],
				'M' => [ 2000, $monthly ],
			],
			'S'
		);
		$order         = $this->subs()->apply_switch( $row, $this->subs()->quote_switch( $row, [ 0 => $ids['M'] ] ), new TransitionContext( 'customer', $this->user ) );
		self::assertNotNull( $order );
		$this->due( $row );
		self::assertSame( 1, $this->subs()->run() );
		self::assertSame( OrderStatus::CANCELLED, $this->orders()->find( $order->id )?->status );
		self::assertSame( 1500, $this->subs()->find( $row->id )?->total );
	}

	public function test_a_saved_card_pays_the_switch_at_once(): void {
		$this->stripe();
		$monthly                                    = [ 'period' => 'month' ];
		[ $product, $ids ]                          = $this->plan_sizes(
			[
				'S' => [ 1000, $monthly ],
				'M' => [ 2000, $monthly ],
			]
		);
		FakeStripe::$routes['POST payment_intents'] = [ 200, self::intent( 'pi_first', 'succeeded', 1500 ) ];
		$order                                      = $this->buy( $this->user, $product, 1, [], StripeGateway::ID, self::CARD, [], $ids['S'] );
		$this->subs()->run();
		$row = $this->subs()->find( $this->only( $order )->id );
		self::assertNotNull( $row );
		self::assertTrue( $row->is_automatic() );

		$quote                                      = $this->subs()->quote_switch( $row, [ 0 => $ids['M'] ] );
		FakeStripe::$routes['POST payment_intents'] = [ 200, self::intent( 'pi_switch', 'succeeded', $quote->charge ) ];
		$switch                                     = $this->subs()->apply_switch( $row, $quote, new TransitionContext( 'customer', $this->user ) );
		self::assertNotNull( $switch );
		self::assertSame( 0, $switch->balance_due() );
		$charge = array_slice( FakeStripe::calls_to( 'POST', 'payment_intents' ), -1 )[0]['body'];
		self::assertSame( [ 'pm_visa', (string) $quote->charge ], [ $charge['payment_method'], (string) $charge['amount'] ] );
		self::assertSame( 2500, $this->subs()->find( $row->id )?->total );
	}

	public function test_one_line_cannot_switch_to_another_schedule_than_the_others(): void {
		[ $product, $ids ] = $this->plan_sizes(
			[
				'S' => [ 1000, [ 'period' => 'month' ] ],
				'L' => [ 10000, [ 'period' => 'year' ] ],
			]
		);
		$other             = $this->plan_product( 700, [ 'period' => 'month' ] );
		$order             = $this->buy( $this->user, $product, 1, [], 'bacs', [], [ $other => 1 ], $ids['S'] );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->only( $order );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'billed on another schedule' );
		$this->subs()->quote_switch( $row, [ self::indexes( $row )[ $product ] => $ids['L'] ] );
	}

	public function test_the_account_page_quotes_a_switch_and_sends_the_charge_to_the_pay_page(): void {
		Pages::install();
		$monthly       = [ 'period' => 'month' ];
		[ $row, $ids ] = $this->sized_row(
			[
				'S' => [ 1000, $monthly ],
				'M' => [ 2000, $monthly ],
				'L' => [ 500, $monthly ],
			],
			'S'
		);
		wp_set_current_user( $this->user );
		$_SERVER['HTTP_REFERER'] = home_url( '/my-account/' );
		$_GET                    = [ 'fxc_view_subscription' => (string) $row->id ];
		$html                    = $this->account()->render();
		self::assertStringContainsString( 'name="subscription_variant[0]"', $html );
		self::assertStringContainsString( 'Review the switch', $html );
		self::assertStringContainsString( 'value="' . $ids['M'] . '"', $html );

		$_POST = $this->subscription_post( $row, 'switch', [ 'subscription_variant' => [ 0 => (string) $ids['M'] ] ] );
		$page  = $this->account();
		$page->handle();
		self::assertSame( [], $page->errors() );
		$html  = $page->render();
		$quote = $this->subs()->quote_switch( $row, [ 0 => $ids['M'] ] );
		self::assertStringContainsString( 'Review your switch', $html );
		self::assertStringContainsString( 'To pay now', $html );
		self::assertStringContainsString( 'name="subscription_quote" value="2500:' . $quote->charge . '"', $html );

		$_POST   = $this->subscription_post(
			$row,
			'switch',
			[
				'subscription_variant' => [ 0 => (string) $ids['M'] ],
				'subscription_quote'   => '2500:' . $quote->charge,
				'subscription_confirm' => '1',
			]
		);
		$pending = $this->redirected();
		$order   = $this->subs()->pending_switch( $row );
		self::assertNotNull( $order );
		self::assertSame( Plugin::instance()->checkout()->pages()->pay_url( $order ), $pending );

		$_POST = [];
		$html  = $this->account()->render();
		self::assertStringContainsString( 'Pay for the switch', $html );
		self::assertStringContainsString( 'Plan switch', $html );

		// A downgrade is saved at once.
		$_POST = $this->subscription_post(
			$row,
			'switch',
			[
				'subscription_variant' => [ 0 => (string) $ids['L'] ],
				'subscription_quote'   => '1000:0',
				'subscription_confirm' => '1',
			]
		);
		self::assertStringContainsString( 'subscription_switch', $this->redirected() );
		self::assertSame( 1000, $this->subs()->find( $row->id )?->total );
		self::assertSame( OrderStatus::CANCELLED, $this->orders()->find( $order->id )?->status, 'The new switch replaces the unpaid one.' );
	}

	/** @return array<int,int> product id => index of its item in the renewals */
	private static function indexes( Subscription $row ): array {
		$out = [];
		foreach ( $row->snapshot->items ?? [] as $index => $item ) {
			if ( $item->product_id !== null ) {
				$out[ $item->product_id ] = $index;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private function subscription_post( Subscription $row, string $do, array $fields ): array {
		return $fields + [
			AccountPage::ACTION => 'subscription',
			AccountPage::NONCE  => wp_create_nonce( AccountPage::ACTION . '_subscription' ),
			'subscription_id'   => (string) $row->id,
			'subscription_do'   => $do,
		];
	}

	private function renews_in( Subscription $row, int $days ): void {
		$this->subs()->repository()->update( $row->id, [ 'next_payment_at' => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS - HOUR_IN_SECONDS ) ], gmdate( 'Y-m-d H:i:s' ) );
	}

	/** @return list<array<string,mixed>> sent mails whose subject has this text */
	private function mails( string $subject ): array {
		return array_values( array_filter( tests_retrieve_phpmailer_instance()->mock_sent, static fn ( array $m ): bool => str_contains( (string) $m['subject'], $subject ) ) );
	}

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

	private function paypal(): void {
		$this->paypal = true;
		FakePayPal::install();
		FakePayPal::connectable();
		Plugin::instance()->payments()->gateways()->settings()->save(
			Plugin::instance()->payments()->gateways()->get( PayPalGateway::ID ) ?? throw new \LogicException( 'No PayPal.' ),
			[
				'enabled'            => true,
				'mode'               => 'test',
				'test_client_id'     => 'AbcClientId',
				'test_client_secret' => 'EfgSecret',
			]
		);
	}

	/** An active row paid with PayPal, its vault entry read by the cron. */
	private function paypal_automatic(): Subscription {
		$this->paypal();
		FakePayPal::$routes['POST v2/checkout/orders']             = [ 201, self::pp_order( 'PP1', 'PAYER_ACTION_REQUIRED' ) ];
		FakePayPal::$routes['POST v2/checkout/orders/PP1/capture'] = [ 201, self::pp_paid( 'PP1', 'VAULTED' ) ];
		$order = $this->buy( $this->user, $this->plan_product( 1000, [ 'period' => 'month' ] ), 1, [], PayPalGateway::ID );
		FakePayPal::$routes['GET v2/checkout/orders/PP1'] = [ [ 200, self::pp_order( 'PP1', 'APPROVED' ) ], [ 200, self::pp_paid( 'PP1', 'VAULTED' ) ] ];
		$this->pay_return( $order, 'PP1' );
		$this->subs()->run();
		$row = $this->subs()->find( $this->only( $order )->id );
		self::assertNotNull( $row );
		self::assertTrue( $row->is_automatic() );
		return $row;
	}

	/** The shopper back from PayPal: the order is captured. */
	private function pay_return( Order $order, string $paypal_order ): void {
		$txn = $this->payments_of( $order->id )[0];
		self::assertSame( 'applied', Plugin::instance()->payments()->service()->handle_return( $txn->id, [ 'token' => $paypal_order ] ) );
	}

	/** @return list<Transaction> */
	private function payments_of( int $order_id ): array {
		return Plugin::instance()->payments()->service()->for_order( $order_id );
	}

	/** @return array<string,mixed> */
	private static function pp_order( string $id, string $status ): array {
		return [
			'id'             => $id,
			'intent'         => 'CAPTURE',
			'status'         => $status,
			'purchase_units' => [
				[
					'reference_id' => 'default',
					'amount'       => [
						'currency_code' => 'USD',
						'value'         => '15.00',
					],
				],
			],
			'links'          => [
				[
					'rel'  => 'payer-action',
					'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . $id,
				],
			],
		];
	}

	/**
	 * A captured PayPal order; `$vault` is the status of its vault entry, '' for none.
	 *
	 * @return array<string,mixed>
	 */
	private static function pp_paid( string $id, string $vault ): array {
		$order = self::pp_order( $id, 'COMPLETED' );
		$order['purchase_units'][0]['payments']['captures'] = [
			[
				'id'                 => 'CAP-' . $id,
				'status'             => 'COMPLETED',
				'amount'             => [
					'currency_code' => 'USD',
					'value'         => '15.00',
				],
				'supplementary_data' => [ 'related_ids' => [ 'order_id' => $id ] ],
			],
		];
		$order['payment_source']                            = [ 'paypal' => [ 'email_address' => 'ada@example.com' ] ];
		if ( $vault !== '' ) {
			$order['payment_source']['paypal']['attributes']['vault'] = [
				'id'       => 'VAULT1',
				'status'   => $vault,
				'customer' => [ 'id' => 'PPCUS1' ],
			];
		}
		return $order;
	}

	/** @return array<string,mixed> */
	private static function pp_declined(): array {
		return [
			'name'    => 'UNPROCESSABLE_ENTITY',
			'details' => [
				[
					'issue'       => 'INSTRUMENT_DECLINED',
					'description' => 'The instrument presented was declined.',
				],
			],
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

	/**
	 * A variable product with sizes S, M and L, each on its own price and plan.
	 *
	 * @param array<string,array{int,array<string,mixed>}> $sizes size => [ price, plan ]
	 * @return array{int,array<string,int>} product id, size => variant id
	 */
	private function plan_sizes( array $sizes ): array {
		$id      = $this->variable_product( array_keys( $sizes ) );
		$product = $this->service()->generate_variants( $id )->product;
		$rows    = [];
		$ids     = [];
		foreach ( $product->variations as $variation ) {
			$size         = strtoupper( (string) $variation->combination->get( 'l:size' ) );
			$rows[]       = [
				'id'            => $variation->id,
				'regular_price' => $sizes[ $size ][0],
				'subscription'  => $sizes[ $size ][1],
			];
			$ids[ $size ] = $variation->id;
		}
		$this->save( $id, [ 'variations' => $rows ] );
		return [ $id, $ids ];
	}

	/**
	 * A paid subscription to one size of a plan_sizes() product.
	 *
	 * @param array<string,array{int,array<string,mixed>}> $sizes
	 * @return array{Subscription,array<string,int>}
	 */
	private function sized_row( array $sizes, string $size ): array {
		[ $product, $ids ] = $this->plan_sizes( $sizes );
		$order             = $this->buy( $this->user, $product, 1, [], 'bacs', [], [], $ids[ $size ] );
		$this->orders()->record_payment( $order->id, $order->total, TransitionContext::system() );
		$row = $this->subs()->find( $this->only( $order )->id );
		self::assertNotNull( $row );
		return [ $row, $ids ];
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
	 * @param array<int,int>       $more  other products in the cart, id => quantity
	 * @param int                  $variant the variation of `$product_id`, or 0 for its base row
	 */
	private function buy( int $user_id, int $product_id, int $qty, array $billing = [], string $method = 'bacs', array $input = [], array $more = [], int $variant = 0 ): Order {
		$plugin  = Plugin::instance();
		$browser = new CartServices( $plugin->services(), $plugin->catalog(), new MemoryTokenStore() );
		foreach ( [ $product_id => $qty ] + $more as $id => $quantity ) {
			$browser->cart()->add( $user_id, $id, $id === $product_id && $variant > 0 ? $variant : $this->service()->get( $id )->base->id, $quantity );
		}
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
