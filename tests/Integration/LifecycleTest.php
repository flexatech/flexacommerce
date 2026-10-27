<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Maintenance\Uninstaller;
use Flexa\Commerce\Setup\Activator;
use Flexa\Commerce\Setup\Roles;
use Flexa\Commerce\Support\Capabilities;
use Flexa\Commerce\Support\Settings;

final class LifecycleTest extends \WP_UnitTestCase {
	public function test_activation_grants_capabilities_and_role(): void {
		$admin = get_role( 'administrator' );
		self::assertNotNull( $admin );
		foreach ( Capabilities::ALL as $cap ) {
			self::assertTrue( $admin->has_cap( $cap ), $cap );
		}
		$manager = get_role( Roles::STORE_MANAGER );
		self::assertNotNull( $manager );
		self::assertTrue( $manager->has_cap( Capabilities::VIEW_ORDERS ) );
		self::assertFalse( $manager->has_cap( Capabilities::MANAGE ) );
	}

	public function test_activation_schedules_daily_cron(): void {
		self::assertNotFalse( wp_next_scheduled( 'flexacommerce.cron.daily' ) );
	}

	public function test_uninstall_keeps_data_unless_opted_in(): void {
		Uninstaller::run();
		self::assertSame( [], Schema::missing_tables() );
		self::assertNotFalse( get_option( Settings::OPTION ) );
	}

	public function tear_down(): void {
		// The erase test drops real tables; put the site back for later tests.
		if ( Schema::missing_tables() !== [] || get_role( Roles::STORE_MANAGER ) === null ) {
			Activator::activate_site();
		}
		// DDL ends the test transaction early, so the rollback in parent::tear_down() would
		// otherwise undo the reinstall's option and sequence rows.
		self::commit_transaction();
		parent::tear_down();
	}

	public function test_opted_in_uninstall_removes_everything_then_reinstall_works(): void {
		// WP_UnitTestCase rewrites DROP TABLE to DROP TEMPORARY TABLE; this test needs the real thing.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		Settings::update( [ 'remove_data_on_uninstall' => true ] );
		Uninstaller::run();

		self::assertCount( 19, Schema::missing_tables() );
		self::assertFalse( get_option( Settings::OPTION ) );
		self::assertFalse( get_option( Schema::DB_VERSION_OPTION ) );
		self::assertNull( get_role( Roles::STORE_MANAGER ) );
		self::assertFalse( wp_next_scheduled( 'flexacommerce.cron.daily' ) );

		Activator::activate_site();
		self::assertSame( [], Schema::missing_tables() );
		self::assertFalse( Settings::bool( 'remove_data_on_uninstall' ) );
	}
}
