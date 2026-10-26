<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping\Methods;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;

defined( 'ABSPATH' ) || exit;

/**
 * Free, optionally only from a minimum contents total or with a coupon that
 * gives free shipping. With no requirement chosen the minimum applies when
 * it is set, as before the setting existed. The minimum is compared with the
 * total after coupon discounts unless `ignore_discounts` is on.
 */
final class FreeShipping implements ShippingMethod {
	public const REQUIRES = [ '', 'coupon', 'either', 'both' ];

	public function type(): string {
		return 'free_shipping';
	}

	public function title(): string {
		return __( 'Free shipping', 'flexacommerce' );
	}

	public function settings_fields(): array {
		return [
			new SettingField( 'title', 'text', __( 'Name shown at checkout', 'flexacommerce' ), __( 'Free shipping', 'flexacommerce' ) ),
			new SettingField(
				'requires',
				'select',
				__( 'Free shipping requires', 'flexacommerce' ),
				'',
				__( 'A coupon gives free shipping when its "Free shipping" box is ticked.', 'flexacommerce' ),
				[
					''       => __( 'The minimum order amount, if set', 'flexacommerce' ),
					'coupon' => __( 'A free-shipping coupon', 'flexacommerce' ),
					'either' => __( 'A free-shipping coupon or the minimum amount', 'flexacommerce' ),
					'both'   => __( 'A free-shipping coupon and the minimum amount', 'flexacommerce' ),
				]
			),
			new SettingField( 'min_amount', 'money', __( 'Minimum order amount', 'flexacommerce' ), 0, __( 'Leave at 0 to offer free shipping on every order.', 'flexacommerce' ) ),
			new SettingField( 'ignore_discounts', 'checkbox', __( 'Check the minimum before coupon discounts', 'flexacommerce' ), false, __( 'Off: a coupon that brings the total under the minimum removes free shipping.', 'flexacommerce' ) ),
		];
	}

	public function rates( ShippingPackage $package, array $settings, int $instance_id ): array {
		$min      = max( 0, (int) ( $settings['min_amount'] ?? 0 ) );
		$requires = (string) ( $settings['requires'] ?? '' );
		$coupon   = $package->free_shipping;
		$total    = ! empty( $settings['ignore_discounts'] ) ? $package->subtotal_before_discounts() : $package->contents_total;
		$spent    = $total->amount >= $min;
		$free     = match ( $requires ) {
			'coupon' => $coupon,
			'either' => $coupon || $spent,
			'both'   => $coupon && $spent,
			default  => $spent,
		};
		if ( ! $free ) {
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
