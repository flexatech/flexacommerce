<?php

declare(strict_types=1);

namespace Flexa\Commerce\Notifications\Emails;

use Flexa\Commerce\Contracts\Orders\OrderView;

defined( 'ABSPATH' ) || exit;

/** Admin: an order was flagged for review, with the reason. */
final class NeedsAttention extends OrderEmail {
	public function id(): string {
		return 'needs_attention';
	}

	public function title(): string {
		return __( 'Order needs attention', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return false;
	}

	public function trigger_hooks(): array {
		return [ self::NEEDS_REVIEW => 2 ];
	}

	protected function applies( string $hook, array $args ): ?OrderView {
		unset( $hook );
		return self::view( $args );
	}

	protected function key( string $hook, array $args ): string {
		unset( $hook );
		return md5( self::reason( $args ) );
	}

	protected function extra( string $hook, array $args, OrderView $order ): array {
		unset( $hook, $order );
		return [ 'reason' => self::reason( $args ) ];
	}

	/** @param list<mixed> $args */
	private static function reason( array $args ): string {
		return is_string( $args[1] ?? null ) ? $args[1] : '';
	}

	protected function subject( OrderView $order ): string {
		unset( $order );
		return __( '[{site_title}]: Order #{order_number} needs attention', 'flexacommerce' );
	}

	protected function heading( OrderView $order ): string {
		unset( $order );
		return __( 'Order #{order_number} needs attention', 'flexacommerce' );
	}
}
