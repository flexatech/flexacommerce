<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * What extensions receive on `flexacommerce.loaded` (doc 04 §2, ADR-017).
 *
 * The accessors for payment gateways, shipping methods, orders and the
 * catalog join this interface in the phases that build those modules. The
 * contract freezes at 1.0, not before.
 *
 * @api
 */
interface Extensions {
	public const API_VERSION = '1.0';

	public function api_version(): string;

	/** @throws Exceptions\IncompatibleApi when the running API is older than $min */
	public function require_api( string $min ): void;

	public function money(): MoneyFactory;

	/** @param string $extension_id [a-z0-9_]{1,64} */
	public function settings( string $extension_id ): ExtensionSettings;

	public function logger( string $channel ): Logger;
}
