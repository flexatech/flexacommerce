<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/**
 * Customer: a cancelled order they had paid for, or one the admin chose to
 * tell them about (`notify_customer` in the transition context).
 */
final class CustomerCancelledOrder extends OrderEmail {
	public function id(): string {
		return 'customer_cancelled_order';
	}

	public function title(): string {
		return __( 'Cancelled order (customer)', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [ self::STATUS => 5 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		unset( $hook );
		[ $view, , $to, , $context ] = self::status_args( $args );
		if ( $view === null || $to !== S::CANCELLED ) {
			return null;
		}
		return $view->amount_paid->amount > 0 || ! empty( $context['notify_customer'] ) ? $view : null;
	}

	protected function template(): string {
		return 'cancelled-order';
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( 'Your {site_title} order #{order_number} has been cancelled', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Your order was cancelled', 'flexacommerce' );
	}
}
