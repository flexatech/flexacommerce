<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Privacy;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Privacy\PersonalData;
use Flexa\Commerce\Privacy\Privacy;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/** The core personal data export and erasure for orders and carts (doc 06 §2.8). */
final class PrivacyTest extends OrderTestCase {
	public function set_up(): void {
		parent::set_up();
		Privacy::instance()->register();
	}

	public function test_the_tools_are_listed_with_core(): void {
		self::assertArrayHasKey( 'flexacommerce-orders', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		self::assertArrayHasKey( 'flexacommerce-cart', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		self::assertArrayHasKey( 'flexacommerce-orders', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
		self::assertArrayHasKey( 'flexacommerce-cart', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
	}

	public function test_export_finds_guest_orders_by_email_and_account_orders_by_user(): void {
		$user  = self::factory()->user->create( [ 'user_email' => 'ada@example.com' ] );
		$guest = $this->detailed( S::COMPLETED );
		$other = $this->place( [ $this->simple( 500 ) => 1 ] );
		Plugin::instance()->orders()->orders()->update(
			$other->id,
			[
				'customer_email' => 'old-address@example.com',
				'customer_id'    => $user,
			]
		);
		$this->place( [ $this->simple( 500 ) => 1 ] );
		Plugin::instance()->orders()->orders()->update( $this->latest(), [ 'customer_email' => 'someone@example.com' ] );

		$export = Privacy::instance()->export_orders( 'ada@example.com', 1 );

		self::assertTrue( $export['done'] );
		self::assertSame( [ 'order-' . $other->id, 'order-' . $guest->id ], array_column( $export['data'], 'item_id' ) );
		$fields = array_column( $export['data'][1]['data'], 'value', 'name' );
		self::assertSame( $guest->order_number, $fields['Order number'] );
		self::assertSame( '$10.00', $fields['Total'] );
		self::assertSame( '203.0.113.9', $fields['IP address'] );
		self::assertSame( 'Leave it at the door', $fields['Order note'] );
		self::assertSame( 'Ada Lovelace, United States', $fields['Billing address'] );
		self::assertStringContainsString( '1 Main St', $fields['Shipping address'] );
		self::assertSame( '555-0100', $fields['Shipping phone'] );
		self::assertSame( 'Your parcel is on its way.', $fields['Messages from the store'] );
		self::assertStringContainsString( ' × 1', $fields['Items'] );
	}

	public function test_export_pages_through_many_orders(): void {
		for ( $i = 0; $i < PersonalData::PAGE + 2; $i++ ) {
			$this->place( [ $this->simple( 100 ) => 1 ] );
		}
		$first  = Privacy::instance()->export_orders( 'ada@example.com', 1 );
		$second = Privacy::instance()->export_orders( 'ada@example.com', 2 );
		self::assertFalse( $first['done'] );
		self::assertCount( PersonalData::PAGE, $first['data'] );
		self::assertTrue( $second['done'] );
		self::assertCount( 2, $second['data'] );
	}

	public function test_erasure_anonymizes_finished_orders_and_keeps_the_amounts(): void {
		$order = $this->detailed( S::COMPLETED );
		Plugin::instance()->orders()->history()->add( $order->id, 'email', 'Completed email sent to ada@example.com.', '2026-01-01 00:00:00' );
		$seen = [];
		add_action(
			'flexacommerce.order.anonymized',
			static function ( OrderView $view ) use ( &$seen ): void {
				$seen[] = $view->id;
			}
		);

		$result = Privacy::instance()->erase_orders( 'ada@example.com', 1 );

		self::assertTrue( $result['items_removed'] );
		self::assertFalse( $result['items_retained'] );
		self::assertTrue( $result['done'] );
		self::assertSame( [ $order->id ], $seen );

		$after = $this->orders()->get( $order->id );
		self::assertNotNull( $after->anonymized_at );
		self::assertSame( '', $after->customer_email );
		self::assertSame( '', $after->customer_name );
		self::assertSame( '', $after->customer_ip );
		self::assertSame( '', $after->customer_note );
		self::assertSame( 1000, $after->total );
		self::assertCount( 1, $after->items_of_type( 'product' ) );
		self::assertNotNull( $after->billing );
		self::assertSame( [ '', '', '', 'US' ], [ $after->billing->first_name, $after->billing->last_name, $after->billing->email, $after->billing->country ] );
		self::assertNotNull( $after->shipping );
		self::assertSame( [ '', '', '' ], [ $after->shipping->address_1, $after->shipping->phone, $after->shipping->postcode ] );

		$history = Plugin::instance()->orders()->history()->for_order( $order->id );
		self::assertSame( 'Personal data removed.', $history[0]['message'] );
		foreach ( $history as $row ) {
			if ( in_array( $row['type'], [ 'note', 'email' ], true ) || $row['customer_visible'] ) {
				self::assertSame( '', $row['message'], $row['type'] );
			}
		}
		self::assertSame( [], Privacy::instance()->export_orders( 'ada@example.com', 1 )['data'] );

		$again = Privacy::instance()->erase_orders( 'ada@example.com', 1 );
		self::assertFalse( $again['items_removed'] );
		self::assertSame( [ $order->id ], $seen );
	}

	public function test_orders_in_progress_are_kept_with_a_message(): void {
		$open = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		$done = $this->place( [ $this->simple( 1000 ) => 1 ] );
		$this->orders()->cancel( $done->id, TransitionContext::admin() );

		$result = Privacy::instance()->erase_orders( 'ada@example.com', 1 );

		self::assertTrue( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertSame( [ 'Order #' . $open->order_number . ' was kept because it is still in progress. Erase again once it is completed or cancelled.' ], $result['messages'] );
		self::assertNull( $this->orders()->get( $open->id )->anonymized_at );
		self::assertNotNull( $this->orders()->get( $done->id )->anonymized_at );

		add_filter( 'flexacommerce.privacy.kept_statuses', '__return_empty_array' );
		$forced = Privacy::instance()->erase_orders( 'ada@example.com', 1 );
		self::assertTrue( $forced['items_removed'] );
		self::assertFalse( $forced['items_retained'] );
		self::assertNotNull( $this->orders()->get( $open->id )->anonymized_at );
	}

	public function test_erasure_works_through_more_orders_than_one_batch(): void {
		$ids = [];
		for ( $i = 0; $i < PersonalData::PAGE + 2; $i++ ) {
			$ids[] = $this->place( [ $this->simple( 100 ) => 1 ], 0, null, S::PROCESSING )->id;
		}
		add_filter( 'flexacommerce.privacy.kept_statuses', '__return_empty_array' );
		$page = 1;
		do {
			$result = Privacy::instance()->erase_orders( 'ada@example.com', $page++ );
		} while ( ! $result['done'] && $page < 10 );

		self::assertSame( 3, $page );
		foreach ( $ids as $id ) {
			self::assertNotNull( $this->orders()->get( $id )->anonymized_at );
		}
	}

	public function test_the_account_cart_is_exported_and_erased(): void {
		$user    = self::factory()->user->create( [ 'user_email' => 'grace@example.com' ] );
		$product = $this->simple( 1000 );
		$rows    = Plugin::instance()->cart()->session_rows();
		$rows->insert(
			hash( 'sha256', 'grace' ),
			$user,
			[
				'lines'       => [
					[
						'key'        => 'k1',
						'product_id' => $product,
						'variant_id' => $this->service()->get( $product )->base->id,
						'qty'        => 2,
					],
				],
				'destination' => [
					'country'  => 'US',
					'state'    => 'CA',
					'postcode' => '94110',
				],
			],
			'2099-01-01 00:00:00',
			'2026-01-01 00:00:00'
		);

		$export = Privacy::instance()->export_cart( 'grace@example.com', 1 );
		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		self::assertSame( get_the_title( $product ) . ' × 2', $fields['Cart items'] );
		self::assertSame( '94110 CA US', $fields['Shipping estimate address'] );
		self::assertSame( [], Privacy::instance()->export_cart( 'nobody@example.com', 1 )['data'] );

		self::assertTrue( Privacy::instance()->erase_cart( 'grace@example.com', 1 )['items_removed'] );
		self::assertNull( $rows->find_by_user( $user ) );
		self::assertFalse( Privacy::instance()->erase_cart( 'grace@example.com', 1 )['items_removed'] );
	}

	public function test_the_policy_text_follows_the_settings(): void {
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_init' );
		Privacy::instance()->register();
		do_action( 'admin_init' );
		$policies = \WP_Privacy_Policy_Content::get_suggested_policy_text();
		$ours     = array_values( array_filter( $policies, static fn ( array $p ): bool => $p['plugin_name'] === 'FlexaCommerce' ) );
		self::assertCount( 1, $ours );
		self::assertStringContainsString( '48 hours for guests and 14 days', $ours[0]['policy_text'] );
		self::assertStringContainsString( 'IP address the order was placed from', $ours[0]['policy_text'] );
	}

	/** A finished order with an IP, a note, a shipping address and a message to the customer. */
	private function detailed( string $status ): Order {
		$order = $this->place( [ $this->simple( 1000 ) => 1 ], 0, null, S::PROCESSING );
		$repo  = Plugin::instance()->orders()->orders();
		$repo->update(
			$order->id,
			[
				'customer_ip'   => '203.0.113.9',
				'customer_note' => 'Leave it at the door',
			]
		);
		$repo->save_addresses( $order->id, [ new AddressView( type: 'shipping', first_name: 'Ada', last_name: 'Lovelace', address_1: '1 Main St', city: 'Springfield', postcode: '12345', country: 'US', phone: '555-0100' ) ] );
		$this->orders()->add_note( $order->id, 'Your parcel is on its way.', true, TransitionContext::admin() );
		$this->orders()->add_note( $order->id, 'Customer called about the delivery.', false, TransitionContext::admin() );
		if ( $status !== S::PROCESSING ) {
			$this->orders()->transition( $order->id, $status, TransitionContext::admin() );
		}
		return $this->orders()->get( $order->id );
	}

	private function latest(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . \Flexa\Commerce\Database\Schema::table( 'orders' ) ); // phpcs:ignore WordPress.DB
	}
}
