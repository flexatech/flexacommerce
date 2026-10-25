<?php

declare(strict_types=1);

namespace Flexa\Commerce\Orders;

defined( 'ABSPATH' ) || exit;

/** Who moves an order and why; written to order_history. */
final class TransitionContext {
	public const ACTORS = [ 'system', 'customer', 'admin', 'gateway', 'cron', 'migration', 'cli' ];

	/** @param array<string,mixed> $context */
	public function __construct(
		public readonly string $actor_type = 'system',
		public readonly int $actor_id = 0,
		public readonly string $message = '',
		public readonly bool $customer_visible = false,
		public readonly array $context = [],
	) {
		if ( ! in_array( $actor_type, self::ACTORS, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown actor type "%s".', $actor_type ) );
		}
	}

	public static function admin( string $message = '' ): self {
		return new self( 'admin', get_current_user_id(), $message );
	}

	public static function system( string $message = '' ): self {
		return new self( 'system', 0, $message );
	}

	public function with_message( string $message ): self {
		return new self( $this->actor_type, $this->actor_id, $message, $this->customer_visible, $this->context );
	}
}
