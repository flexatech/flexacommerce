<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Shipping;

defined( 'ABSPATH' ) || exit;

/** @api */
interface ShippingMethodRegistry {
	/**
	 * @param callable():ShippingMethod $factory built on first use
	 * @throws \Flexa\Commerce\Contracts\Exceptions\DuplicateRegistration
	 */
	public function register( string $type, callable $factory ): void;

	public function has( string $type ): bool;

	public function get( string $type ): ?ShippingMethod;

	/** @return list<string> */
	public function types(): array;
}
