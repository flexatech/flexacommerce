<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/** Customer: the order is complete. */
final class CustomerCompletedOrder extends OrderEmail {
	public function id(): string {
		return 'customer_completed_order';
	}

	public function title(): string {
		return __( 'Completed order', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [ self::STATUS => 5 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		unset( $hook );
		[ $view, , $to ] = self::status_args( $args );
		return $to === S::COMPLETED ? $view : null;
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( 'Your {site_title} order #{order_number} is complete', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Thank you for shopping with us', 'flexacommerce' );
	}
}
