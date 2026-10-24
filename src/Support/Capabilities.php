<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Every permission check goes through here. Each capability passes a
 * `flexacommerce.capabilities.*` filter so sites and add-ons can re-gate it.
 */
final class Capabilities {
	public const MANAGE        = 'manage_flexacommerce';
	public const VIEW_ORDERS   = 'view_fxc_orders';
	public const EDIT_ORDERS   = 'edit_fxc_orders';
	public const REFUND_ORDERS = 'refund_fxc_orders';

	/** @var list<string> */
	public const ALL = [ self::MANAGE, self::VIEW_ORDERS, self::EDIT_ORDERS, self::REFUND_ORDERS ];

	public static function can_manage(): bool {
		return self::check( 'manage', self::MANAGE );
	}

	public static function can_view_orders(): bool {
		return self::check( 'view_orders', self::VIEW_ORDERS );
	}

	public static function can_edit_orders(): bool {
		return self::check( 'edit_orders', self::EDIT_ORDERS );
	}

	public static function can_refund_orders(): bool {
		return self::check( 'refund_orders', self::REFUND_ORDERS );
	}

	private static function check( string $name, string $capability ): bool {
		$filtered = apply_filters( 'flexacommerce.capabilities.' . $name, $capability );
		return current_user_can( is_string( $filtered ) && $filtered !== '' ? $filtered : $capability );
	}
}
