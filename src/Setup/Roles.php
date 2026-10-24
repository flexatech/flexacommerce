<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Roles {
	public const STORE_MANAGER = 'fxc_store_manager';

	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin !== null ) {
			foreach ( Capabilities::ALL as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		if ( get_role( self::STORE_MANAGER ) === null ) {
			add_role(
				self::STORE_MANAGER,
				__( 'Store Manager', 'flexacommerce' ),
				[
					'read'                      => true,
					Capabilities::VIEW_ORDERS   => true,
					Capabilities::EDIT_ORDERS   => true,
					Capabilities::REFUND_ORDERS => true,
				]
			);
		}
	}

	public static function uninstall(): void {
		remove_role( self::STORE_MANAGER );
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( Capabilities::ALL as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
