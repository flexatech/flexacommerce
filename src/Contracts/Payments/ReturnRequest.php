<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * The customer coming back from the provider. `query` is the raw query
 * string as an array; a gateway that does not sign returns should ignore it.
 *
 * @api
 */
final class ReturnRequest {
	/** @param array<string,mixed> $query */
	public function __construct(
		public readonly int $transaction_id,
		public readonly ?string $gateway_reference,
		public readonly array $query,
	) {}
}
