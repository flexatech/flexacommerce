<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Shipping;

use Flexa\Commerce\Contracts\SettingField;

defined( 'ABSPATH' ) || exit;

/**
 * A shipping method type. Each zone holds instances of it with their own
 * settings. rates() must be fast and side-effect free (doc 04 §4).
 *
 * @api
 */
interface ShippingMethod {
	public function type(): string;

	public function title(): string;

	/** @return list<SettingField> per zone instance */
	public function settings_fields(): array;

	/**
	 * @param array<string,mixed> $settings the instance's stored values
	 * @return list<ShippingRate>
	 */
	public function rates( ShippingPackage $package, array $settings, int $instance_id ): array;
}
