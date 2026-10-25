<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Contracts\Payments\TransactionView;

defined( 'ABSPATH' ) || exit;

/** Customer: a refund succeeded, full or partial. */
final class CustomerRefundedOrder extends OrderEmail {
	public function id(): string {
		return 'customer_refunded_order';
	}

	public function title(): string {
		return __( 'Refunded order', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [ self::REFUNDED => 2 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		unset( $hook );
		return ( $args[1] ?? null ) instanceof TransactionView ? self::view( $args ) : null;
	}

	protected function key( string $hook, array $args ): string {
		unset( $hook );
		return ( $args[1] ?? null ) instanceof TransactionView ? (string) $args[1]->id : '';
	}

	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook );
		return [
			'refund' => $args[1] ?? null,
			'full'   => self::full( $order ),
		];
	}

	private static function full( OrderView $order ): bool {
		return $order->amount_refunded->amount >= $order->amount_paid->amount;
	}

	protected function subject( OrderView $order ): string {
		return self::full( $order )
			? __( 'Your {site_title} order #{order_number} has been refunded', 'flexacommerce' )
			: __( 'Your {site_title} order #{order_number} has been partially refunded', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		return self::full( $order )
			? __( 'Your order was refunded', 'flexacommerce' )
			: __( 'Your order was partially refunded', 'flexacommerce' );
	}
}
