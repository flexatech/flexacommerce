<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/** Customer: the order is paid, or needs no payment, and is being prepared. */
final class CustomerProcessingOrder extends OrderEmail {
	public function id(): string {
		return 'customer_processing_order';
	}

	public function title(): string {
		return __( 'Processing order', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [
			self::CREATED => 1,
			self::STATUS  => 5,
		];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		if ( $hook === self::CREATED ) {
			$view = self::view( $args );
			return $view !== null && $view->status === S::PROCESSING ? $view : null;
		}
		[ $view, , $to ] = self::status_args( $args );
		return $to === S::PROCESSING ? $view : null;
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( 'Your {site_title} order #{order_number} is being processed', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Thank you for your order', 'flexacommerce' );
	}
}
