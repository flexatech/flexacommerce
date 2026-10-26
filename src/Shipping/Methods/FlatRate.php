<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping\Methods;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;

defined( 'ABSPATH' ) || exit;

/** A fixed cost per order plus an optional cost per item. */
final class FlatRate implements ShippingMethod {
	public function type(): string {
		return 'flat_rate';
	}

	public function title(): string {
		return __( 'Flat rate', 'flexacommerce' );
	}

	public function settings_fields(): array {
		return [
			new SettingField( 'title', 'text', __( 'Name shown at checkout', 'flexacommerce' ), __( 'Flat rate', 'flexacommerce' ) ),
			new SettingField( 'cost', 'money', __( 'Cost per order', 'flexacommerce' ), 0 ),
			new SettingField( 'cost_per_item', 'money', __( 'Cost per item', 'flexacommerce' ), 0 ),
			Label::tax_status(),
		];
	}

	public function rates( ShippingPackage $package, array $settings, int $instance_id ): array {
		$cost = max( 0, (int) ( $settings['cost'] ?? 0 ) ) + max( 0, (int) ( $settings['cost_per_item'] ?? 0 ) ) * $package->item_count();
		return [
			new ShippingRate(
				'flat_rate:' . $instance_id,
				Label::of( $settings, $this->title() ),
				new Money( $cost, $package->currency() ),
				[],
				Label::taxable( $settings ),
			),
		];
	}
}
