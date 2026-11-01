<?php

declare(strict_types=1);

namespace Flexa\Commerce\GiftCards;

use Flexa\Commerce\Contracts\Email\Email;
use Flexa\Commerce\Contracts\Email\EmailMessage;

defined( 'ABSPATH' ) || exit;

/**
 * The email that brings a gift card code to its recipient (ADR-069). The
 * gift card service sends it when the card is issued or its delivery day
 * comes, so it has no trigger hook. The email filters see the id
 * `customer_gift_card`.
 */
final class GiftCardEmail implements Email {
	public const ID = 'customer_gift_card';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Gift card', 'flexacommerce' );
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
