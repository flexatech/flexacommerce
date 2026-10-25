<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Setup\Activator;

/**
 * Runs only under the multisite config: `phpunit -c tests/phpunit-multisite.xml.dist`.
 */
final class MultisiteTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		// Site tables must be real so INFORMATION_SCHEMA sees them.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	public function test_network_activation_installs_every_site(): void {
		$site_id = $this->site_without_plugin();
		Activator::activate( true );
		$this->assert_installed( $site_id );
		wp_delete_site( $site_id );
	}

	public function test_new_site_on_network_active_install_gets_tables_and_deletion_drops_them(): void {
		$active = (array) get_site_option( 'active_sitewide_plugins', [] );
		update_site_option( 'active_sitewide_plugins', $active + [ FLEXACOMMERCE_BASENAME => time() ] );

		$site_id = (int) self::factory()->blog->create();
		$this->assert_installed( $site_id );

		switch_to_blog( $site_id );
		$orders = Schema::table( 'orders' );
		restore_current_blog();

		wp_delete_site( $site_id );
		global $wpdb;
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders ) ) );

		update_site_option( 'active_sitewide_plugins', $active );
	}

	public function test_site_created_while_plugin_off_installs_on_first_admin_visit(): void {
		$site_id = $this->site_without_plugin();
		switch_to_blog( $site_id );
		self::assertSame( 0, Schema::installed_version() );
		Activator::maybe_upgrade();
		restore_current_blog();
		$this->assert_installed( $site_id );
		wp_delete_site( $site_id );
	}

	private function site_without_plugin(): int {
		$site_id = (int) self::factory()->blog->create();
		switch_to_blog( $site_id );
		$missing = count( Schema::missing_tables() );
		restore_current_blog();
		self::assertSame( count( Schema::tables() ), $missing );
		return $site_id;
	}

	private function assert_installed( int $site_id ): void {
		switch_to_blog( $site_id );
		self::assertSame( [], Schema::missing_tables() );
		self::assertSame( (int) Schema::DB_VERSION, Schema::installed_version() );
		self::assertSame( [], Schema::migrate() );
		restore_current_blog();
	}

	public function tear_down(): void {
		// Real DDL commits the test transaction, so commit the rest too for a clean state.
		self::commit_transaction();
		parent::tear_down();
	}
}
