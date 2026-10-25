<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Customers;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Privacy\Privacy;

/** The saved addresses (doc 03 §7) and their export and erasure. */
final class CustomerProfileTest extends \WP_UnitTestCase {
	private CustomerProfile $profile;

	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		$this->profile = new CustomerProfile();
		$this->user    = self::factory()->user->create( [ 'user_email' => 'ada@example.com' ] );
	}

	public function test_an_address_round_trips(): void {
		self::assertNull( $this->profile->address( $this->user, 'billing' ) );
		$this->profile->save( $this->user, $this->billing() );

		$saved = $this->profile->address( $this->user, 'billing' );
		self::assertNotNull( $saved );
		self::assertSame( $this->billing()->to_array(), $saved->to_array() );
		self::assertSame( [ 'billing' ], array_keys( $this->profile->addresses( $this->user ) ) );
	}

	public function test_a_shipping_address_keeps_no_email(): void {
		$this->profile->save( $this->user, new AddressView( type: 'shipping', city: 'O\'Fallon', email: 'ada@example.com' ) );
		$saved = $this->profile->address( $this->user, 'shipping' );
		self::assertSame( '', $saved?->email );
		self::assertSame( 'O\'Fallon', $saved?->city, 'slashes survive the meta API' );
	}

	public function test_addresses_are_kept_per_site(): void {
		global $wpdb;
		$this->profile->save( $this->user, $this->billing() );
		self::assertNotSame( '', (string) get_user_meta( $this->user, $wpdb->get_blog_prefix() . 'fxc_billing', true ) );
		self::assertSame( '', (string) get_user_meta( $this->user, 'fxc_billing', true ) );
	}

	public function test_the_eraser_drops_every_address_of_the_site(): void {
		$other = self::factory()->user->create();
		$this->profile->save( $this->user, $this->billing() );
		$this->profile->save( $other, new AddressView( type: 'shipping', city: 'Reno' ) );
		CustomerProfile::delete_all();
		self::assertSame( [], $this->profile->addresses( $this->user ) );
		self::assertSame( [], $this->profile->addresses( $other ) );
	}

	public function test_privacy_export_and_erasure(): void {
		Privacy::instance()->register();
		self::assertArrayHasKey( 'flexacommerce-addresses', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		self::assertArrayHasKey( 'flexacommerce-addresses', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );

		self::assertSame( [], Privacy::instance()->export_addresses( 'ada@example.com' )['data'] );
		$this->profile->save( $this->user, $this->billing() );

		$export = Privacy::instance()->export_addresses( 'ada@example.com' );
		self::assertTrue( $export['done'] );
		self::assertSame( [ 'address-billing' ], array_column( $export['data'], 'item_id' ) );
		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		self::assertSame( 'Billing address', $fields['Type'] );
		self::assertStringContainsString( 'Sacramento', $fields['Address'] );
		self::assertSame( '555-0100', $fields['Phone'] );

		self::assertSame( [], Privacy::instance()->export_addresses( 'nobody@example.com' )['data'] );
		self::assertFalse( Privacy::instance()->erase_addresses( 'nobody@example.com' )['items_removed'] );
		self::assertTrue( Privacy::instance()->erase_addresses( 'ada@example.com' )['items_removed'] );
		self::assertSame( [], $this->profile->addresses( $this->user ) );
	}

	private function billing(): AddressView {
		return new AddressView(
			type: 'billing',
			first_name: 'Ada',
			last_name: 'Lovelace',
			address_1: '12 St James Square',
			city: 'Sacramento',
			state: 'CA',
			postcode: '95814',
			country: 'US',
			email: 'ada@example.com',
			phone: '555-0100',
		);
	}
}
