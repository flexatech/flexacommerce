<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Notifications;

use Flexa\Commerce\Notifications\EmailSettings;
use Flexa\Commerce\Maintenance\Cron;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** The order emails of doc 02 §7.9, sent through the WordPress test mailer. */
final class EmailsTest extends OrderTestCase {
	private string $admin_email = '';

	public function set_up(): void {
		parent::set_up();
		$this->admin_email = (string) get_option( 'admin_email' );
		update_option( 'admin_email', 'shop@example.com' );
		reset_phpmailer_instance();
	}

	/** Order writes commit mid-test, so the options are cleaned after the rollback and committed. */
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		foreach ( Plugin::instance()->notifications()->emails() as $email ) {
			delete_option( EmailSettings::PREFIX . $email->id() );
		}
		delete_option( 'flexacommerce_gateway_bacs' );
		update_option( 'admin_email', $this->admin_email );
		Settings::update( [ 'defer_emails' => false ] );
		wp_unschedule_hook( Cron::SEND_EMAIL );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fxc\\_mail\\_%'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_an_order_created_as_processing_tells_the_admin_and_the_customer(): void {
		$order = $this->place( [ $this->simple( 1500 ) => 2 ], 0, null, S::PROCESSING );

		$sent = $this->sent();
		self::assertSame( [ 'shop@example.com', 'ada@example.com' ], array_column( $sent, 'to' ) );
		self::assertStringContainsString( 'New order #' . $order->order_number, $sent[0]['subject'] );
		self::assertStringContainsString( 'is being processed', $sent[1]['subject'] );
		self::assertStringContainsString( 'Product ', $sent[1]['body'] );
		self::assertStringContainsString( '$30.00', $sent[1]['body'] );
		self::assertStringContainsString( 'order-received=' . $order->id, $sent[1]['body'] );
		self::assertStringContainsString( 'page=flexacommerce-orders', $sent[0]['body'] );

		$rows = $this->email_rows( $order->id );
		self::assertSame( [ 'customer_processing_order', 'new_order' ], array_column( array_column( $rows, 'context' ), 'email' ) );
		self::assertTrue( $rows[0]['context']['sent'] );
	}

	public function test_a_pending_order_sends_nothing_until_it_is_paid(): void {
		$order = $this->place( [ $this->simple( 1000 ) => 1 ] );
		self::assertSame( [], $this->sent() );

		$this->orders()->record_payment( $order->id, 1000, new TransitionContext( 'gateway' ) );
		self::assertSame( [ 'shop@example.com', 'ada@example.com' ], array_column( $this->sent(), 'to' ) );

		reset_phpmailer_instance();
		$this->orders()->transition( $order->id, S::COMPLETED, TransitionContext::admin() );
		$sent = $this->sent();
		self::assertCount( 1, $sent );
		self::assertStringContainsString( 'is complete', $sent[0]['subject'] );
	}

	public function test_bank_transfer_orders_get_the_bank_details(): void {
		$gateways = Plugin::instance()->payments()->service()->gateways();
		$bacs     = $gateways->get( 'bacs' );
		self::assertNotNull( $bacs );
		$gateways->settings()->save(
			$bacs,
			[
				'enabled'        => true,
				'account_name'   => 'Example Shop Ltd',
				'account_number' => 'GB00 1234',
			]
		);
		try {
			$order = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::ON_HOLD, 'bacs' );
		} finally {
			$gateways->settings()->save( $bacs, [ 'enabled' => false ] );
		}

		$sent = $this->sent();
		self::assertSame( [ 'shop@example.com', 'ada@example.com' ], array_column( $sent, 'to' ) );
		self::assertStringContainsString( 'has been received', $sent[1]['subject'] );
		self::assertStringContainsString( 'GB00 1234', $sent[1]['body'] );
		self::assertStringContainsString( 'Reference: ' . $order->order_number, $sent[1]['body'] );
	}

	public function test_cancelling_tells_the_customer_only_when_paid_or_asked(): void {
		$quiet = $this->place( [ $this->simple( 1000 ) => 1 ] );
		$this->orders()->cancel( $quiet->id, TransitionContext::admin() );
		self::assertSame( [ 'shop@example.com' ], array_column( $this->sent(), 'to' ) );

		reset_phpmailer_instance();
		$told = $this->place( [ $this->simple( 1000 ) => 1 ] );
		$this->orders()->transition( $told->id, S::CANCELLED, new TransitionContext( 'admin', 0, '', false, [ 'notify_customer' => true ] ) );
		self::assertSame( [ 'shop@example.com', 'ada@example.com' ], array_column( $this->sent(), 'to' ) );
		self::assertStringContainsString( 'has been cancelled', $this->sent()[1]['subject'] );
	}

	public function test_failed_payment_and_needs_attention_go_to_the_admin(): void {
		$order = $this->place( [ $this->simple( 1000 ) => 1 ] );
		$this->orders()->record_payment_failed( $order->id, 'Card declined', new TransitionContext( 'gateway' ) );
		$sent = $this->sent();
		self::assertSame( [ 'shop@example.com' ], array_column( $sent, 'to' ) );
		self::assertStringContainsString( 'Payment for order #' . $order->order_number . ' failed', $sent[0]['subject'] );

		reset_phpmailer_instance();
		$this->orders()->flag_attention( $order->id, 'Amount does not match.', TransitionContext::system() );
		$sent = $this->sent();
		self::assertSame( [ 'shop@example.com' ], array_column( $sent, 'to' ) );
		self::assertStringContainsString( 'Amount does not match.', $sent[0]['body'] );
	}

	public function test_each_refund_sends_one_email(): void {
		$order    = $this->place( [ $this->simple( 1000 ) => 2 ], 0, null, S::PROCESSING );
		$payments = Plugin::instance()->payments()->service();
		$this->orders()->record_payment( $order->id, 2000, TransitionContext::system() );
		reset_phpmailer_instance();

		$payments->refund( $order->id, 500, [], '', false, TransitionContext::admin() );
		$payments->refund( $order->id, 1500, [], '', false, TransitionContext::admin() );

		$subjects = array_column( $this->sent(), 'subject' );
		self::assertCount( 2, $subjects );
		self::assertStringContainsString( 'partially refunded', $subjects[0] );
		self::assertStringContainsString( 'has been refunded', $subjects[1] );
		self::assertStringContainsString( '$5.00', $this->sent()[0]['body'] );
	}

	public function test_settings_and_filters_shape_the_email(): void {
		$settings = Plugin::instance()->notifications()->settings();
		$settings->update( 'customer_processing_order', [ 'enabled' => false ] );
		$settings->update(
			'new_order',
			[
				'recipients' => 'orders@example.com, not-an-email',
				'subject'    => 'Order {order_number} at {site_title}',
			]
		);
		self::assertSame( 'orders@example.com', $settings->get( 'new_order' )['recipients'] );
		$heading = static fn (): string => 'Filtered heading';
		add_filter( 'flexacommerce.email.heading', $heading );

		$order = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );

		$sent = $this->sent();
		self::assertCount( 1, $sent );
		self::assertSame( 'orders@example.com', $sent[0]['to'] );
		self::assertSame( 'Order ' . $order->order_number . ' at ' . get_option( 'blogname' ), $sent[0]['subject'] );
		self::assertStringContainsString( 'Filtered heading', $sent[0]['body'] );
		self::assertIsArray( get_option( 'flexacommerce_email_new_order' ) );
		self::assertArrayNotHasKey( 'flexacommerce_email_new_order', wp_load_alloptions() );
	}

	public function test_suspended_emails_send_nothing_and_record_nothing(): void {
		add_filter( 'flexacommerce.emails.suspended', '__return_true' );
		$order = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		self::assertSame( [], $this->sent() );
		self::assertSame( [], $this->email_rows( $order->id ) );
	}

	public function test_a_failed_send_is_recorded(): void {
		$fail = static function (): bool {
			return false;
		};
		add_filter( 'pre_wp_mail', $fail );
		$order = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		$rows  = $this->email_rows( $order->id );
		self::assertCount( 2, $rows );
		self::assertFalse( $rows[0]['context']['sent'] );
		self::assertStringContainsString( 'could not be sent', $rows[0]['message'] );
	}

	public function test_a_theme_template_overrides_ours(): void {
		global $wp_stylesheet_path, $wp_template_path;
		$saved              = [ $wp_stylesheet_path, $wp_template_path ];
		$theme              = get_temp_dir() . 'fxc-theme-' . wp_generate_password( 6, false );
		$wp_stylesheet_path = $theme; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- locate_template() reads both.
		$wp_template_path   = $theme; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_mkdir_p( $theme . '/flexacommerce/emails' );
		file_put_contents( $theme . '/flexacommerce/emails/new-order.php', '<?php echo "THEME COPY " . esc_html( $args["order"]->number );' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		try {
			$order = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		} finally {
			[ $wp_stylesheet_path, $wp_template_path ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			unlink( $theme . '/flexacommerce/emails/new-order.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		self::assertStringContainsString( 'THEME COPY ' . $order->order_number, $this->sent()[0]['body'] );
		self::assertStringNotContainsString( 'THEME COPY', $this->sent()[1]['body'] );
	}


	public function test_deferred_emails_are_built_now_and_sent_by_cron(): void {
		Settings::update( [ 'defer_emails' => true ] );
		$order = $this->place( [ $this->simple( 1500 ) => 1 ], 0, null, S::PROCESSING );

		self::assertSame( [], $this->sent() );
		self::assertSame( [], $this->email_rows( $order->id ) );
		$queued = $this->queued();
		self::assertCount( 2, $queued );

		foreach ( $queued as $queue_id ) {
			Cron::instance()->send_email( $queue_id );
		}
		$sent = $this->sent();
		self::assertSame( [ 'shop@example.com', 'ada@example.com' ], array_column( $sent, 'to' ) );
		self::assertStringContainsString( 'is being processed', $sent[1]['subject'] );
		$emails = array_column( array_column( $this->email_rows( $order->id ), 'context' ), 'email' );
		self::assertContains( 'new_order', $emails );
		self::assertContains( 'customer_processing_order', $emails );

		// A second run of the same event is a no-op.
		reset_phpmailer_instance();
		Cron::instance()->send_email( $queued[0] );
		Cron::instance()->send_email( '../bad' );
		self::assertSame( [], $this->sent() );
	}

	public function test_deferred_emails_send_at_once_when_scheduling_fails(): void {
		Settings::update( [ 'defer_emails' => true ] );
		$refuse = static fn (): \WP_Error => new \WP_Error( 'no', 'no' );
		add_filter( 'pre_schedule_event', $refuse );
		try {
			$this->place( [ $this->simple( 1500 ) => 1 ], 0, null, S::PROCESSING );
		} finally {
			remove_filter( 'pre_schedule_event', $refuse );
		}
		self::assertSame( [ 'shop@example.com', 'ada@example.com' ], array_column( $this->sent(), 'to' ) );
		self::assertSame( [], $this->queued() );
	}

	/** @return list<string> queue ids of the scheduled email events */
	private function queued(): array {
		$ids = [];
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ Cron::SEND_EMAIL ] ?? [] ) as $event ) {
				$ids[] = (string) $event['args'][0];
			}
		}
		return $ids;
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

	/** @return list<array<string,mixed>> newest first */
	private function email_rows( int $order_id ): array {
		return array_values(
			array_filter(
				Plugin::instance()->orders()->history()->for_order( $order_id ),
				static fn ( array $row ): bool => $row['type'] === 'email'
			)
		);
	}
}
