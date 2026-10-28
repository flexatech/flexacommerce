<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * An error answer from Stripe, or no answer at all (`status` 0). Card
 * errors carry a message written for the shopper; the others do not.
 */
final class StripeError extends \RuntimeException {
	/** @param array<string,mixed> $error the `error` object Stripe sent */
	public function __construct(
		string $message,
		public readonly int $status = 0,
		public readonly array $error = [],
	) {
		parent::__construct( $message );
	}

	public function type(): string {
		return is_string( $this->error['type'] ?? null ) ? $this->error['type'] : ( $this->status === 0 ? 'network' : 'api_error' );
	}

	/** The decline code when there is one, else Stripe's error code. */
	public function reason(): string {
		foreach ( [ 'decline_code', 'code' ] as $key ) {
			if ( is_string( $this->error[ $key ] ?? null ) && $this->error[ $key ] !== '' ) {
				return $this->error[ $key ];
			}
		}
		return $this->type();
	}

	public function is_card_error(): bool {
		return $this->type() === 'card_error';
	}
}
