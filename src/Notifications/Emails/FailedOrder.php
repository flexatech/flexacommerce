<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/** Admin: the payment of a pending order failed. */
final class FailedOrder extends OrderEmail {
	public function id(): string {
		return 'failed_order';
	}

	public function title(): string {
		return __( 'Failed payment', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return false;
	}

	public function trigger_hooks(): array {
		return [ self::STATUS => 5 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		unset( $hook );
		[ $view, $from, $to ] = self::status_args( $args );
		return $from === S::PENDING && $to === S::FAILED ? $view : null;
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( '[{site_title}]: Payment for order #{order_number} failed', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Payment failed: #{order_number}', 'flexacommerce' );
	}
}
