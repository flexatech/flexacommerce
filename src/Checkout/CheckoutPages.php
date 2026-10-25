<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * URLs of the checkout page and the order pages hung off it. The order
 * confirmation and order-pay views are query arguments on the checkout
 * page, so they work with any permalink structure and need no rewrite flush.
 */
final class CheckoutPages {
	public const RECEIVED = 'order-received';
	public const PAY      = 'order-pay';

	public function checkout_url(): string {
		$page = Settings::int( 'checkout_page_id' );
		$url  = $page > 0 ? get_permalink( $page ) : false;
		return is_string( $url ) ? $url : home_url( '/' );
	}

	public function confirmation_url( Order $order ): string {
		return add_query_arg(
			[
				self::RECEIVED => $order->id,
				'key'          => $order->order_key,
			],
			$this->checkout_url()
		);
	}

	public function pay_url( Order $order ): string {
		return add_query_arg(
			[
				self::PAY => $order->id,
				'key'     => $order->order_key,
			],
			$this->checkout_url()
		);
	}

	public function terms_url(): string {
		$page = Settings::int( 'terms_page_id' );
		$url  = $page > 0 ? get_permalink( $page ) : false;
		return is_string( $url ) ? $url : '';
	}
}
