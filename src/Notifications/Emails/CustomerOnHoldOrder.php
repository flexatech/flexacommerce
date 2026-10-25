<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/** Customer: the order waits for a payment made outside the site, with the bank details. */
final class CustomerOnHoldOrder extends OrderEmail {
	public function id(): string {
		return 'customer_on_hold_order';
	}

	public function title(): string {
		return __( 'Order on hold', 'flexacommerce' );
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
			return $view !== null && $view->status === S::ON_HOLD ? $view : null;
		}
		[ $view, $from, $to, $actor ] = self::status_args( $args );
		return $from === S::PENDING && $to === S::ON_HOLD && $actor !== 'admin' ? $view : null;
	}

	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook, $args );
		return [ 'instructions' => $this->data->instructions( $order ) ];
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( 'Your {site_title} order has been received', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Thank you for your order', 'flexacommerce' );
	}
}
