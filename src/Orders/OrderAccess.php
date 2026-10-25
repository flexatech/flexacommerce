<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Who may see an order (doc 02 §7.5): the holder of its key, the logged-in
 * owner, or staff with view_fxc_orders.
 */
final class OrderAccess {
	public static function new_key(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- random token, not obfuscation.
	}

	public static function key_matches( Order $order, string $key ): bool {
		return $key !== '' && hash_equals( $order->order_key, $key );
	}

	public static function is_owner( Order $order, int $user_id ): bool {
		return $user_id > 0 && $order->customer_id === $user_id;
	}

	public static function can_view( Order $order, string $key, int $user_id ): bool {
		return self::key_matches( $order, $key ) || self::is_owner( $order, $user_id ) || ( $user_id > 0 && Capabilities::can_view_orders() );
	}

	/** Customers may cancel only pending orders (doc 02 §7.10). */
	public static function can_customer_cancel( Order $order, string $key, int $user_id ): bool {
		return $order->status === OrderStatus::PENDING
			&& ( self::key_matches( $order, $key ) || self::is_owner( $order, $user_id ) );
	}
}
