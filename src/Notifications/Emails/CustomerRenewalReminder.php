<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Notifications\SentAhead;

defined( 'ABSPATH' ) || exit;

/**
 * Customer: an active subscription renews in a few days (ADR-075), or its
 * free trial ends. Says the date, the price, and whether the saved card is
 * charged or a payment link follows, with a link to the subscription page.
 * The subscription service fires the hook once per renewal date, the days
 * setting ahead.
 */
final class CustomerRenewalReminder extends OrderEmail implements SentAhead {
	public const ID = 'customer_renewal_reminder';

	/** SubscriptionService::RENEWAL_REMINDER; a string, so emails do not load subscriptions. */
	public const HOOK = 'flexacommerce.subscription.renewal_reminder';

	public const DEFAULT_DAYS = 3;

	/** @var array<string,mixed> the context of the call being rendered */
	private array $why = [];

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Subscription renewal reminder', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [ self::HOOK => 2 ];
	}

	public function default_days(): int {
		return self::DEFAULT_DAYS;
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		$this->why = is_array( $args[1] ?? null ) ? $args[1] : [];
		return $hook === self::HOOK && is_string( $this->why['renews_at'] ?? null ) ? self::view( $args ) : null;
	}

	protected function key( string $hook, array $args ): string {
		unset( $hook );
		$why = is_array( $args[1] ?? null ) ? $args[1] : [];
		return ( is_scalar( $why['subscription_id'] ?? null ) ? (string) $why['subscription_id'] : '' ) . ':' . ( is_string( $why['renews_at'] ?? null ) ? $why['renews_at'] : '' );
	}

	protected function context( string $hook, array $args ): array {
		unset( $hook, $args );
		return [ 'subscription_id' => (int) ( is_numeric( $this->why['subscription_id'] ?? null ) ? $this->why['subscription_id'] : 0 ) ];
	}

	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook, $args, $order );
		$s = fn ( string $key ): string => is_string( $this->why[ $key ] ?? null ) ? $this->why[ $key ] : '';
		return [
			'title'            => $s( 'title' ),
			'price'            => $s( 'price' ),
			'renews_on'        => $this->date(),
			'trial'            => (bool) ( $this->why['trial'] ?? false ),
			'automatic'        => (bool) ( $this->why['automatic'] ?? false ),
			'payment_label'    => $s( 'payment_label' ),
			'subscription_url' => $s( 'subscription_url' ),
		];
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return ( $this->why['trial'] ?? false )
			/* translators: %s: date the trial ends. */
			? sprintf( __( 'Your {site_title} free trial ends on %s', 'flexacommerce' ), $this->date() )
			/* translators: %s: renewal date. */
			: sprintf( __( 'Your {site_title} subscription renews on %s', 'flexacommerce' ), $this->date() );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return ( $this->why['trial'] ?? false ) ? __( 'Your trial ends soon', 'flexacommerce' ) : __( 'Renewal coming up', 'flexacommerce' );
	}

	private function date(): string {
		$at = is_string( $this->why['renews_at'] ?? null ) ? $this->why['renews_at'] : '';
		return $at !== '' ? (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $at . ' UTC' ) ) : '';
	}
}
