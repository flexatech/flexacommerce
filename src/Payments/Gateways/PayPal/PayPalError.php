<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments\Gateways\PayPal;

defined( 'ABSPATH' ) || exit;

/**
 * An error answer from PayPal, or no answer at all (`status` 0). `body`
 * is what PayPal sent: `name`, `message`, `details` with an `issue` each,
 * `debug_id`.
 */
final class PayPalError extends \RuntimeException {
	/** @param array<string,mixed> $body */
	public function __construct(
		string $message,
		public readonly int $status = 0,
		public readonly array $body = [],
	) {
		parent::__construct( $message );
	}

	/** The first detail's issue, like INSTRUMENT_DECLINED, else the error name. */
	public function issue(): string {
		$details = is_array( $this->body['details'] ?? null ) ? $this->body['details'] : [];
		$first   = is_array( $details[0] ?? null ) ? $details[0] : [];
		foreach ( [ $first['issue'] ?? null, $this->body['name'] ?? null, $this->body['error'] ?? null ] as $issue ) {
			if ( is_string( $issue ) && $issue !== '' ) {
				return $issue;
			}
		}
		return $this->status === 0 ? 'NETWORK' : 'API_ERROR';
	}

	/** PayPal said no to this request, as opposed to failing to answer. */
	public function refused(): bool {
		return $this->status >= 400 && $this->status < 500 && $this->status !== 429;
	}
}
