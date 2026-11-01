<?php

declare(strict_types=1);

namespace Flexa\Commerce\Subscriptions;

use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Contracts\Email\EmailMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the store that a subscription was cancelled (ADR-073). The
 * subscription service sends it, so it has no trigger hook. The email
 * filters see the id `cancelled_subscription`.
 */
final class CancelledSubscriptionEmail implements Email {
	public const ID = 'cancelled_subscription';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Cancelled subscription', 'flexacommerce' );
	}

	public function to_customer(): bool {
		return false;
	}

	public function trigger_hooks(): array {
		return [];
	}

	public function render( string $hook, array $args ): ?EmailMessage {
		unset( $hook, $args );
		return null;
	}
}
