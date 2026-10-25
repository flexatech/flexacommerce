<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/** Admin: an order arrived as processing or on hold, or a pending one was paid. */
final class NewOrder extends OrderEmail {
	public function id(): string {
		return 'new_order';
	}

	public function title(): string {
		return __( 'New order', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return false;
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
			return $view !== null && in_array( $view->status, [ S::PROCESSING, S::ON_HOLD ], true ) ? $view : null;
		}
		[ $view, $from, $to, $actor ] = self::status_args( $args );
		if ( $from !== S::PENDING ) {
			return null;
		}
		// A customer paying again with bank transfer holds the order like a new one does.
		return $to === S::PROCESSING || ( $to === S::ON_HOLD && $actor !== 'admin' ) ? $view : null;
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( '[{site_title}]: New order #{order_number}', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'New order: #{order_number}', 'flexacommerce' );
	}
}
