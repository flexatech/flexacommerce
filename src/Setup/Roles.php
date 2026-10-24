<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Roles {
	public const STORE_MANAGER = 'fxc_store_manager';

	/** Raise when the capability set changes; Activator::maybe_upgrade() then reinstalls. */
	public const VERSION = 2;

	public const VERSION_OPTION = 'flexacommerce_roles_version';

	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin !== null ) {
			foreach ( Capabilities::ALL as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$manager_caps = [
			'read'                      => true,
			'upload_files'              => true,
			Capabilities::VIEW_ORDERS   => true,
			Capabilities::EDIT_ORDERS   => true,
			Capabilities::REFUND_ORDERS => true,
		];
		foreach ( Capabilities::PRODUCT_CAPS as $cap ) {
			$manager_caps[ $cap ] = true;
		}

		$manager = get_role( self::STORE_MANAGER );
		if ( $manager === null ) {
			add_role( self::STORE_MANAGER, __( 'Store Manager', 'flexacommerce' ), $manager_caps );
		} else {
			foreach ( array_keys( $manager_caps ) as $cap ) {
				$manager->add_cap( $cap );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public static function uninstall(): void {
		delete_option( self::VERSION_OPTION );
		remove_role( self::STORE_MANAGER );
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( Capabilities::ALL as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
