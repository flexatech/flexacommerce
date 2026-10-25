<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping\Methods;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;

defined( 'ABSPATH' ) || exit;

/** Free, optionally only from a minimum contents total. */
final class FreeShipping implements ShippingMethod {
	public function type(): string {
		return 'free_shipping';
	}

	public function title(): string {
		return __( 'Free shipping', 'flexacommerce' );
	}

	public function settings_fields(): array {
		return [
			new SettingField( 'title', 'text', __( 'Name shown at checkout', 'flexacommerce' ), __( 'Free shipping', 'flexacommerce' ) ),
			new SettingField( 'min_amount', 'money', __( 'Minimum order amount', 'flexacommerce' ), 0, __( 'Leave at 0 to offer free shipping on every order.', 'flexacommerce' ) ),
		];
	}

	public function rates( ShippingPackage $package, array $settings, int $instance_id ): array {
		$min = max( 0, (int) ( $settings['min_amount'] ?? 0 ) );
		if ( $package->contents_total->amount < $min ) {
			return [];
		}
		return [
			new ShippingRate(
				'free_shipping:' . $instance_id,
				Label::of( $settings, $this->title() ),
				Money::zero( $package->currency() ),
			),
		];
	}
}
