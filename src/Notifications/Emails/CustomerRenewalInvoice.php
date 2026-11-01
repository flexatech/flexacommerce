<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/**
 * Customer: a subscription made its renewal order (ADR-073), with the
 * link to pay it. Renewals are paid by hand, so this email is how the
 * shopper learns a payment is due.
 */
final class CustomerRenewalInvoice extends OrderEmail {
	/** NewOrder::$created_via of a renewal order. */
	public const CREATED_VIA = 'subscription';

	public function id(): string {
		return 'customer_renewal_invoice';
	}

	public function title(): string {
		return __( 'Subscription renewal', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [ self::CREATED => 1 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		$view = self::view( $args );
		return $hook === self::CREATED && $view !== null && $view->created_via === self::CREATED_VIA && $view->status === S::PENDING ? $view : null;
	}

	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook, $args );
		return [ 'pay_url' => $this->data->pay_url( $order ) ];
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( 'Your {site_title} subscription renewal is due', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Renewal due', 'flexacommerce' );
	}
}
