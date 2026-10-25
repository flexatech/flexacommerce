<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/** Admin: an order was cancelled, by anyone, including the unpaid order expiry. */
final class CancelledOrder extends OrderEmail {
	public function id(): string {
		return 'cancelled_order';
	}

	public function title(): string {
		return __( 'Cancelled order', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return false;
	}

	public function trigger_hooks(): array {
		return [ self::STATUS => 5 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		unset( $hook );
		[ $view, , $to ] = self::status_args( $args );
		return $to === S::CANCELLED ? $view : null;
	}

	protected function template(): string {
		return 'cancelled-order';
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( '[{site_title}]: Order #{order_number} has been cancelled', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Order cancelled: #{order_number}', 'flexacommerce' );
	}
}
