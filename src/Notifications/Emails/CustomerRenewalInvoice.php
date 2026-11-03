<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderStatus as S;

defined( 'ABSPATH' ) || exit;

/**
 * Customer: a subscription's renewal order waits for them (ADR-073), with
 * the link to pay it. Sent when there is no saved method to charge, and
 * when the automatic charge failed the first time and the last time
 * (ADR-074), with the reason, the next try and a link to change the
 * payment method.
 */
final class CustomerRenewalInvoice extends OrderEmail {
	/** NewOrder::$created_via of a renewal order. */
	public const CREATED_VIA = 'subscription';

	/** SubscriptionService::RENEWAL_DUE; a string, so emails do not load subscriptions. */
	public const DUE = 'flexacommerce.subscription.renewal_due';

	/** @var array<string,mixed> the context of the call being rendered */
	private array $why = [];

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
		return [ self::DUE => 2 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		$view      = self::view( $args );
		$this->why = is_array( $args[1] ?? null ) ? $args[1] : [];
		return $hook === self::DUE && $view !== null && $view->created_via === self::CREATED_VIA && $view->status === S::PENDING ? $view : null;
	}

	protected function key( string $hook, array $args ): string {
		unset( $hook );
		$why = is_array( $args[1] ?? null ) ? $args[1] : [];
		return (string) ( is_scalar( $why['attempt'] ?? null ) ? $why['attempt'] : 0 );
	}

	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook, $args );
		$retry = is_string( $this->why['retry_at'] ?? null ) ? $this->why['retry_at'] : '';
		return [
			'pay_url'          => $this->data->pay_url( $order ),
			'failed'           => is_string( $this->why['failed'] ?? null ) ? $this->why['failed'] : '',
			'retry_on'         => $retry !== '' ? (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $retry . ' UTC' ) ) : '',
			'final'            => (bool) ( $this->why['final'] ?? false ),
			'subscription_url' => is_string( $this->why['subscription_url'] ?? null ) ? $this->why['subscription_url'] : '',
		];
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return ( $this->why['failed'] ?? '' ) !== ''
			? __( 'Your {site_title} subscription payment failed', 'flexacommerce' )
			: __( 'Your {site_title} subscription renewal is due', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return ( $this->why['failed'] ?? '' ) !== '' ? __( 'Payment failed', 'flexacommerce' ) : __( 'Renewal due', 'flexacommerce' );
	}

	protected function sample( OrderView $order ): array {
		$this->why = [
			'attempt'          => 1,
			'subscription_url' => home_url( '/' ),
		];
		return [ $order, $this->why ];
	}
}
