<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * A core gateway that talks to its provider when the merchant saves its
 * settings: it checks the keys and sets up the webhook, so there is
 * nothing to copy by hand. Internal for now, like OfflineGateway.
 */
interface ConnectedGateway {
	/**
	 * Called with the sanitized values before they are stored. Returns the
	 * values to store, with anything the provider handed back.
	 *
	 * @param array<string,mixed> $values
	 * @param array<string,mixed> $before the values stored until now
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException with a message for the merchant when the keys are wrong
	 */
	public function connect( array $values, array $before ): array;

	/**
	 * What the settings card tells the merchant: the account it reached,
	 * the webhook, what is missing.
	 *
	 * @return list<array{tone:string,text:string}>
	 */
	public function notes(): array;
}
