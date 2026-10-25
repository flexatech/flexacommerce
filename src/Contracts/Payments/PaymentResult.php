<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * What process_payment() returns. Build it with the named constructors.
 *
 * @api
 */
final class PaymentResult {
	public const REDIRECT        = 'redirect';
	public const SUCCEEDED       = 'succeeded';
	public const PENDING         = 'pending';
	public const AUTHORIZED      = 'authorized';
	public const REQUIRES_ACTION = 'requires_action';
	public const FAILED          = 'failed';

	/** @param array<string,mixed> $payload */
	private function __construct(
		public readonly string $type,
		public readonly ?string $reference = null,
		public readonly ?string $redirect_url = null,
		public readonly ?string $instructions = null,
		public readonly array $payload = [],
		public readonly string $failure_code = '',
		public readonly string $failure_message = '',
	) {}

	/** Send the customer to the provider; the outcome arrives by return or webhook. */
	public static function redirect( string $url, ?string $reference = null ): self {
		return new self( self::REDIRECT, $reference, $url );
	}

	public static function succeeded( string $reference ): self {
		return new self( self::SUCCEEDED, $reference );
	}

	/** Payment happens later (cash on delivery, bank transfer). */
	public static function pending( ?string $reference = null, ?string $instructions = null ): self {
		return new self( self::PENDING, $reference, null, $instructions );
	}

	public static function authorized( string $reference ): self {
		return new self( self::AUTHORIZED, $reference );
	}

	/** @param array<string,mixed> $payload handed to the gateway's script module */
	public static function requires_action( array $payload, ?string $reference = null ): self {
		return new self( self::REQUIRES_ACTION, $reference, null, null, $payload );
	}

	public static function failed( string $code, string $message ): self {
		return new self( self::FAILED, null, null, null, [], sanitize_key( $code ), $message );
	}
}
