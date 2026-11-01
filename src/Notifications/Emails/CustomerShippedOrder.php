<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\Shipment;

defined( 'ABSPATH' ) || exit;

/** Customer: a package left, with its tracking (ADR-067). Sent when the admin ticks "Email the customer". */
final class CustomerShippedOrder extends OrderEmail {
	public const SHIPPED = 'flexacommerce.order.shipped';

	public function id(): string {
		return 'customer_shipped_order';
	}

	public function title(): string {
		return __( 'Shipped order', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [ self::SHIPPED => 3 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		unset( $hook );
		$context = $args[2] ?? [];
		return ( $args[1] ?? null ) instanceof Shipment && is_array( $context ) && ! empty( $context['notify'] ) ? self::view( $args ) : null;
	}

	protected function key( string $hook, array $args ): string {
		unset( $hook );
		return ( $args[1] ?? null ) instanceof Shipment ? (string) $args[1]->id : '';
	}

	protected function context( string $hook, array $args ): array {
		unset( $hook );
		return ( $args[1] ?? null ) instanceof Shipment ? [ 'shipment_id' => $args[1]->id ] : [];
	}

	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook );
		return [ 'shipment' => ( $args[1] ?? null ) instanceof Shipment ? $args[1] : null ];
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( 'Your {site_title} order #{order_number} has shipped', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Your order is on its way', 'flexacommerce' );
	}
}
