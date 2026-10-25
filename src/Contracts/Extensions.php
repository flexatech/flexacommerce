<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * What extensions receive on `flexacommerce.loaded` (doc 04 §2, ADR-017).
 *
 * The catalog reader joins this interface with the storefront phase. The
 * contract freezes at 1.0, not before.
 *
 * @api
 */
interface Extensions {
	public const API_VERSION = '1.0';

	public function api_version(): string;

	/** @throws Exceptions\IncompatibleApi when the running API is older than $min */
	public function require_api( string $min ): void;

	public function payment_gateways(): Payments\GatewayRegistry;

	public function shipping_methods(): Shipping\ShippingMethodRegistry;

	public function money(): MoneyFactory;

	/** Read-only order lookups and extension order meta (doc 04 §5.3). */
	public function orders(): Orders\OrderReader;

	/** @param string $extension_id [a-z0-9_]{1,64} */
	public function settings( string $extension_id ): ExtensionSettings;

	public function logger( string $channel ): Logger;
}
