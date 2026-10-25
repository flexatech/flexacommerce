<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Gateways are registered as factories, so a gateway class is only loaded
 * when checkout, a webhook or the settings screen needs it (doc 10 §3.2, O7).
 *
 * @api
 */
interface GatewayRegistry {
	/**
	 * @param callable():PaymentGateway $factory
	 * @throws \Flexa\Commerce\Contracts\Exceptions\DuplicateRegistration
	 */
	public function register( string $id, callable $factory ): void;

	public function has( string $id ): bool;

	/** Builds the gateway on first use; null when the id is unknown. */
	public function get( string $id ): ?PaymentGateway;

	/** @return list<string> in registration order */
	public function ids(): array;
}
