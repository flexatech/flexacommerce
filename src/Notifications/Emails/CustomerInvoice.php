<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;

defined( 'ABSPATH' ) || exit;

/**
 * Customer: the order's details, sent by hand from the order screen
 * (ADR-061). No hook sends it; an order the admin made for a customer who
 * still has to pay carries the link to the pay page.
 */
final class CustomerInvoice extends OrderEmail {
	public const MANUAL = 'manual';

	private int $sends = 0;

	public function id(): string {
		return 'customer_invoice';
	}

	public function title(): string {
		return __( 'Order details', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		return $hook === self::MANUAL ? self::view( $args ) : null;
	}

	/** Each click is meant: a second send in the same request is not a duplicate hook. */
	protected function key( string $hook, array $args ): string {
		unset( $hook, $args );
		return (string) ++$this->sends;
	}

	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook, $args );
		return [ 'pay_url' => $this->data->pay_url( $order ) ];
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( 'Details of your {site_title} order #{order_number}', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Your order', 'flexacommerce' );
	}
}
