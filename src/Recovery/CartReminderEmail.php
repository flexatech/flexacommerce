<?php

declare(strict_types=1);

namespace Flexa\Commerce\Recovery;

use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Contracts\Email\EmailMessage;

defined( 'ABSPATH' ) || exit;

/**
 * The cart reminder (ADR-063). The recovery cron builds and sends it, so
 * it has no trigger hook and is not listed with the order emails; its
 * texts live in the abandoned cart settings. The email filters see the id
 * `cart_reminder`.
 */
final class CartReminderEmail implements Email {
	public const ID = 'cart_reminder';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Cart reminder', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return true;
	}

	public function trigger_hooks(): array {
		return [];
	}

	public function render( string $hook, array $args ): ?EmailMessage {
		unset( $hook, $args );
		return null;
	}
}
