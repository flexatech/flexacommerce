<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Orders;

use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Cart\CartTestCase;

/**
 * OrderService commits its own transactions, so order rows survive the
 * test like sessions do and are deleted in tear_down.
 */
abstract class OrderTestCase extends CartTestCase {
	private const TABLES = [ 'order_history', 'order_meta', 'order_addresses', 'order_items', 'transactions', 'webhook_events', 'orders' ];

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		foreach ( self::TABLES as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
		}
		// Rate limit counters written before a mid-test commit outlive the rollback.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fxc\\_rl\\_%'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		$wpdb->query( 'COMMIT' );
	}

	protected function orders(): OrderService {
		return Plugin::instance()->orders()->service();
	}

	protected function stock_of( int $product_id ): ?int {
		return $this->service()->get( $product_id )->base->stock_quantity;
	}

	/**
	 * A pending order for the given simple products, one line each.
	 *
	 * @param array<int,int> $lines product id => quantity
	 */
	protected function place( array $lines, int $shipping = 0, ?string $token = null, string $status = OrderStatus::PENDING, string $method = 'cod' ): Order {
		$items    = [];
		$subtotal = 0;
		foreach ( $lines as $product_id => $qty ) {
			$product   = $this->service()->get( $product_id );
			$price     = (int) $product->base->regular_price;
			$items[]   = NewOrderItem::product( $product_id, $product->base->id, $product->base->id, 'Product ' . $product_id, null, [], $qty, $price );
			$subtotal += $price * $qty;
		}
		if ( $shipping > 0 ) {
			$items[] = NewOrderItem::shipping( 'flat_rate:1', 'Flat rate', $shipping );
		}
		$billing = new AddressView( type: 'billing', first_name: 'Ada', last_name: 'Lovelace', country: 'US', email: 'ada@example.com' );
		$new     = new NewOrder(
			status: $status,
			currency: 'USD',
			currency_exponent: 2,
			items: $items,
			subtotal: $subtotal,
			shipping_total: $shipping,
			total: $subtotal + $shipping,
			customer_email: 'ada@example.com',
			billing: $billing,
			payment_method: $method,
			payment_method_title: $method,
			checkout_token: $token ?? wp_generate_password( 32, false ),
		);
		return $this->orders()->create( $new, $this->orders()->next_number(), new TransitionContext( 'customer' ) );
	}
}
