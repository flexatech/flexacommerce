<?php

declare(strict_types=1);

namespace Flexa\Commerce\StockAlerts;

use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Contracts\Email\EmailMessage;

defined( 'ABSPATH' ) || exit;

/**
 * The back-in-stock email (ADR-065). The stock alert cron builds and sends
 * it, so it has no trigger hook; its texts live in the stock alert
 * settings. The email filters see the id `back_in_stock`.
 */
final class BackInStockEmail implements Email {
	public const ID = 'back_in_stock';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Back in stock', 'flexacommerce' );
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
