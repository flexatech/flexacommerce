<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Database\Upgrades\V24LongerPrefix;
use Flexa\Commerce\Setup\Roles;
use Flexa\Commerce\Support\Capabilities;

/** DB_VERSION 24 (ADR-087): `fxc_` keys and tables move to `fxcm_`. */
final class LongerPrefixTest extends \WP_UnitTestCase {
	public function test_old_tables_are_renamed_and_new_ones_are_left_alone(): void {
		global $wpdb;
		// Real tables: the test case would make CREATE TABLE temporary, and RENAME TABLE skips those.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		$old = $wpdb->prefix . 'fxc_v24probe';
		$new = $wpdb->prefix . 'fxcm_v24probe';
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id int NOT NULL)', $old ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (id) VALUES (7)', $old ) );

		try {
			V24LongerPrefix::rename_tables( [ 'v24probe', 'v24missing' ] );
			self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) );
			self::assertSame( '7', $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i', $new ) ) );

			// Both present: the new table wins and the old one stays for a look by hand.
			$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id int NOT NULL)', $old ) );
			V24LongerPrefix::rename_tables( [ 'v24probe' ] );
			self::assertSame( $old, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $old, $new ) );
		}
	}

	public function test_keys_in_wordpress_tables_move_to_the_longer_prefix(): void {
		global $wpdb;
		$product = self::factory()->post->create();
		$page    = self::factory()->post->create();
		$term    = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		$user    = self::factory()->user->create();
		$wpdb->update( $wpdb->posts, [ 'post_type' => 'fxc_product' ], [ 'ID' => $product ] );
		$wpdb->update( $wpdb->term_taxonomy, [ 'taxonomy' => 'fxc_product_cat' ], [ 'term_id' => $term ] );
		add_post_meta( $product, '_fxc_type', 'simple' );
		add_post_meta( $page, '_menu_item_object', 'fxc_product_cat' );
		add_post_meta( $page, '_other_fxc_key', 'kept' );
		add_user_meta( $user, 'fxc_billing', [ 'city' => 'Lyon' ] );
		add_user_meta( $user, 'manageedit-fxc_productcolumnshidden', [ 'date' ] );
		$wpdb->insert( Schema::table( 'transactions' ), $this->transaction( 'fxc_gift_card' ) );
		$transaction = (int) $wpdb->insert_id;
		set_transient( 'fxc_rl_probe', 1, 60 );

		( new V24LongerPrefix() )->run();
		( new V24LongerPrefix() )->run();

		self::assertSame( PostTypes::PRODUCT, get_post_type( $product ) );
		self::assertSame( PostTypes::CATEGORY, get_term( $term )->taxonomy );
		self::assertSame( 'simple', get_post_meta( $product, '_fxcm_type', true ) );
		self::assertSame( '', get_post_meta( $product, '_fxc_type', true ) );
		self::assertSame( PostTypes::CATEGORY, get_post_meta( $page, '_menu_item_object', true ) );
		self::assertSame( 'kept', get_post_meta( $page, '_other_fxc_key', true ), 'Keys that only contain the prefix stay.' );
		self::assertSame( [ 'city' => 'Lyon' ], get_user_meta( $user, CustomerProfile::META['billing'], true ) );
		self::assertSame( [ 'date' ], get_user_meta( $user, 'manageedit-fxcm_productcolumnshidden', true ) );
		self::assertSame( 'fxcm_gift_card', $wpdb->get_var( $wpdb->prepare( 'SELECT gateway FROM %i WHERE id = %d', Schema::table( 'transactions' ), $transaction ) ) );
		self::assertFalse( get_transient( 'fxc_rl_probe' ) );
	}

	public function test_roles_and_capabilities_move_with_their_users(): void {
		$old = add_role(
			'fxc_store_manager',
			'Store Manager',
			[
				'read'            => true,
				'edit_fxc_orders' => true,
			]
		);
		self::assertNotNull( $old );
		$editor = get_role( 'editor' );
		$editor?->add_cap( 'view_fxc_orders' );
		$user = self::factory()->user->create( [ 'role' => 'fxc_store_manager' ] );

		( new V24LongerPrefix() )->run();

		self::assertNull( get_role( 'fxc_store_manager' ) );
		self::assertTrue( user_can( $user, Capabilities::EDIT_ORDERS ) );
		self::assertSame( [ Roles::STORE_MANAGER ], array_values( get_userdata( $user )->roles ) );
		self::assertTrue( get_role( 'editor' )?->has_cap( Capabilities::VIEW_ORDERS ) );
		self::assertFalse( get_role( 'editor' )?->has_cap( 'view_fxc_orders' ) );
		get_role( 'editor' )?->remove_cap( Capabilities::VIEW_ORDERS );
	}

	/** @return array<string,mixed> */
	private function transaction( string $gateway ): array {
		return [
			'order_id'        => 1,
			'gateway'         => $gateway,
			'type'            => 'charge',
			'amount'          => 100,
			'currency'        => 'USD',
			'status'          => 'succeeded',
			'idempotency_key' => wp_generate_password( 20, false ),
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
		];
	}
}
