<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping\Methods;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;

defined( 'ABSPATH' ) || exit;

/** The customer collects the order. No shipping address is needed. */
final class LocalPickup implements ShippingMethod {
	public function type(): string {
		return 'local_pickup';
	}

	public function title(): string {
		return __( 'Local pickup', 'flexacommerce' );
	}

	public function settings_fields(): array {
		return [
			new SettingField( 'title', 'text', __( 'Name shown at checkout', 'flexacommerce' ), __( 'Local pickup', 'flexacommerce' ) ),
			new SettingField( 'cost', 'money', __( 'Cost', 'flexacommerce' ), 0 ),
			new SettingField( 'location', 'textarea', __( 'Pickup address and hours', 'flexacommerce' ), '' ),
		];
	}

	public function rates( ShippingPackage $package, array $settings, int $instance_id ): array {
		$location = trim( (string) ( $settings['location'] ?? '' ) );
		return [
			new ShippingRate(
				'local_pickup:' . $instance_id,
				Label::of( $settings, $this->title() ),
				new Money( max( 0, (int) ( $settings['cost'] ?? 0 ) ), $package->currency() ),
				$location !== '' ? [ $location ] : [],
				true,
				false,
			),
		];
	}
}
