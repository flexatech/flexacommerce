<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Migration\Sources\WooEnumerationRepository;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Subscriptions\SubscriptionStatus;

/**
 * WooCommerce Subscriptions migration (ADR-079), with the plugin off as at
 * cutover: the product plans, the subscription rows with their schedule,
 * saved card and linked orders, held until billing starts here.
 */
final class SubscriptionMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;

	/** @var list<array<string,mixed>> the last job's preflight */
	private array $preflight = [];

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		foreach ( [ 'subscription_events', 'subscription_orders', 'subscriptions' ] as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_subscriptions_come_over_held_with_their_schedule_card_and_orders(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Coffee plan' );
		$product->set_regular_price( '12' );
		$product_id = $product->save();
		wp_set_object_terms( $product_id, 'subscription', 'product_type' );
		foreach ( [
			'_subscription_price'           => '12',
			'_subscription_period'          => 'month',
			'_subscription_period_interval' => '1',
			'_subscription_length'          => '12',
			'_subscription_trial_length'    => '0',
			'_subscription_sign_up_fee'     => '5',
		] as $key => $value ) {
			update_post_meta( $product_id, $key, $value );
		}

		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'pm_visa' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $this->store->customer );
		$token->save();

		$parent = $this->order( $product_id, 'completed' );
		$sub    = $this->subscription( $product_id, $parent, 'active', [ '_stripe_source_id' => 'pm_visa' ] );
		$this->order( $product_id, 'processing', [ '_subscription_renewal' => (string) $sub ] );
		$manual = $this->subscription( $product_id, 0, 'on-hold', [ '_requires_manual_renewal' => 'true' ], 'bacs' );
		$this->subscription( $product_id, 0, 'cancelled', [ '_schedule_next_payment' => '0' ] );

		$payments = ( new WooEnumerationRepository( $GLOBALS['wpdb'] ) )->subscription_payments();
		$this->assertSame( [ 1, 0 ], [ $payments['manual'], $payments['paypal_plans'] ] );

		$job = $this->run_job();
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 3, $job->counter( 'subscriptions', 'created' ), (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$codes = array_column( $this->preflight, 'code' );
		$this->assertContains( 'subscriptions_held', $codes );
		$this->assertContains( 'manual_renewals', $codes );

		$fxcm_product = $this->local_id( 'product', $product_id );
		$plan         = Plugin::instance()->catalog()->products()->find( $fxcm_product )?->base->plan();
		$this->assertNotNull( $plan );
		$this->assertSame( [ 'month', 1, 12, 0, 500 ], [ $plan->period, $plan->interval, $plan->length, $plan->trial_days, $plan->signup_fee ] );

		$subs = Plugin::instance()->subscriptions();
		$row  = $subs->find( $this->local_id( 'subscription', $sub ) );
		$this->assertNotNull( $row );
		$this->assertTrue( $row->billing_held );
		$this->assertSame( [ SubscriptionStatus::ACTIVE, $this->store->customer, 'stripe', 'cus_ana', 'pm_visa', 2 ], [ $row->status, $row->customer_id, $row->payment_method, $row->payment_customer, $row->payment_token, $row->billed ] );
		$this->assertSame( [ 1200, 'Visa ending in 4242' ], [ $row->total, $row->payment_label ] );
		$this->assertSame( [ 'imported' ], array_column( $subs->repository()->events( $row->id ), 'type' ) );
		$this->assertSame( 12, $row->plan->length, 'Twelve months in all.' );
		$this->assertSame( '2026-12-15 10:00:00', $row->next_payment_at );
		$this->assertSame( $this->local_id( 'order', $parent ), $row->parent_order_id );
		$this->assertSame( [ 'parent', 'renewal' ], array_column( $subs->repository()->orders( $row->id ), 'relation' ) );
		$this->assertSame( 0, $subs->run(), 'Held rows do not renew.' );

		$held = $subs->find( $this->local_id( 'subscription', $manual ) );
		$this->assertSame( [ SubscriptionStatus::ON_HOLD, 'bacs', null ], [ $held?->status, $held?->payment_method, $held?->payment_token ] );
		$this->assertSame( 3, $subs->held_count() );

		// A second run leaves held rows as they are, a released one is never written again.
		$again = $this->run_job();
		$this->assertSame( 0, $again->counter( 'subscriptions', 'created' ) );
		$this->assertSame( 3, $subs->start_billing( TransitionContext::admin() ) );
		update_post_meta( $sub, '_billing_interval', '2' );
		$this->modified( $sub );
		$third = $this->run_job();
		$this->assertSame( 1, $subs->find( $row->id )?->plan->interval, 'Billing started here: the row is ours now.' );
		$this->assertGreaterThanOrEqual( 1, $third->counter( 'subscriptions', 'skipped' ) + $third->counter( 'subscriptions', 'unchanged' ) );
	}

	/** @param array<string,string> $meta */
	private function order( int $product_id, string $status, array $meta = [] ): int {
		$order = new \WC_Order();
		$order->set_customer_id( $this->store->customer );
		$order->set_billing_email( 'ana@example.com' );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_payment_method( 'stripe' );
		$order->calculate_totals( false );
		$order->set_date_paid( time() );
		$order->set_status( $status );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		return $order->save();
	}

	/**
	 * A subscription as WooCommerce Subscriptions stores it: an order row
	 * of its own type and a status WooCommerce does not know.
	 *
	 * @param array<string,string> $meta
	 */
	private function subscription( int $product_id, int $parent, string $status, array $meta, string $method = 'stripe' ): int {
		$sub = new \WC_Order();
		$sub->set_customer_id( $this->store->customer );
		$sub->set_billing_email( 'ana@example.com' );
		$sub->set_billing_first_name( 'Ana' );
		$sub->set_billing_country( 'US' );
		$sub->add_product( wc_get_product( $product_id ), 1 );
		$sub->set_payment_method( $method );
		$sub->set_payment_method_title( $method === 'stripe' ? 'Credit card' : 'Bank transfer' );
		$sub->set_parent_id( $parent );
		$sub->calculate_totals( false );
		foreach ( $meta + [
			'_billing_period'        => 'month',
			'_billing_interval'      => '1',
			'_schedule_start'        => '2026-10-15 10:00:00',
			'_schedule_trial_end'    => '0',
			'_schedule_next_payment' => '2026-12-15 10:00:00',
			'_schedule_end'          => '2027-10-15 10:00:00',
			'_stripe_customer_id'    => 'cus_ana',
		] as $key => $value ) {
			$sub->update_meta_data( $key, $value );
		}
		$id = $sub->save();

		global $wpdb;
		if ( WooEnumerationRepository::hpos() ) {
			$wpdb->update(
				$wpdb->prefix . 'wc_orders',
				[
					'type'   => 'shop_subscription',
					'status' => 'wc-' . $status,
				],
				[ 'id' => $id ]
			);
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = 'shop_subscription', post_status = %s WHERE ID = %d AND post_type = 'shop_order'", 'wc-' . $status, $id ) );
		wp_cache_flush();
		return $id;
	}

	private function modified( int $id ): void {
		global $wpdb;
		$later = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		if ( WooEnumerationRepository::hpos() ) {
			$wpdb->update( $wpdb->prefix . 'wc_orders', [ 'date_updated_gmt' => $later ], [ 'id' => $id ] );
		}
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => $later ], [ 'ID' => $id ] );
		wp_cache_flush();
	}

	private function run_job(): Job {
		$job = $this->service->create(
			Direction::WC_TO_FXC,
			[
				'backup_confirmed' => true,
				'stages'           => [ 'settings', 'terms', 'attributes', 'products', 'customers', 'orders', 'subscriptions' ],
			],
			false,
			1
		);
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->preflight = $job->report['preflight'];
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}

	private function local_id( string $entity, int $wc_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT local_id FROM %i WHERE entity = %s AND remote_id = %s', Schema::table( 'id_map' ), $entity, (string) $wc_id ) );
	}
}
