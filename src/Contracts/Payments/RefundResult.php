<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

defined( 'ABSPATH' ) || exit;

/** @api */
final class RefundResult {
	private function __construct(
		public readonly string $type,
		public readonly ?string $reference = null,
		public readonly string $failure_code = '',
		public readonly string $failure_message = '',
	) {}

	public static function succeeded( string $reference ): self {
		return new self( 'succeeded', $reference );
	}

	/** The provider accepted the refund; a refund_succeeded event follows. */
	public static function pending( string $reference ): self {
		return new self( 'pending', $reference );
	}

	public static function failed( string $code, string $message ): self {
		return new self( 'failed', null, sanitize_key( $code ), $message );
	}
}
