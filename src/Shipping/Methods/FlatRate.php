<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping\Methods;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Contracts\Shipping\ShippingMethod;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;
use Flexa\Commerce\Shipping\ShippingClasses;
use Flexa\Commerce\Support\RateTables;

defined( 'ABSPATH' ) || exit;

/**
 * A fixed cost per order plus an optional cost per item, plus a cost per
 * shipping class in the cart (ADR-068), as WooCommerce's flat rate does:
 * each class adds its own cost, or only the most expensive one counts.
 */
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
			new SettingField(
				'class_costs',
				'class_costs',
				__( 'Shipping class costs', 'flexacommerce' ),
				[
					'calculation' => 'per_class',
					'costs'       => [],
				],
				__( 'Added to the cost above for the items of each class in the cart.', 'flexacommerce' )
			),
			Label::tax_status(),
		];
	}

	public function rates( ShippingPackage $package, array $settings, int $instance_id ): array {
		$cost = max( 0, (int) ( $settings['cost'] ?? 0 ) ) + max( 0, (int) ( $settings['cost_per_item'] ?? 0 ) ) * $package->item_count();
		return [
			new ShippingRate(
				'flat_rate:' . $instance_id,
				Label::of( $settings, $this->title() ),
				new Money( $cost + self::class_cost( $package, $settings['class_costs'] ?? null ), $package->currency() ),
				[],
				Label::taxable( $settings ),
			),
		];
	}

	/** Nothing while the store has no shipping classes, so a cost left from removed classes never applies. */
	private static function class_cost( ShippingPackage $package, mixed $value ): int {
		if ( ! ShippingClasses::any() ) {
			return 0;
		}
		$settings = RateTables::class_costs( $value );
		$sum      = 0;
		$highest  = 0;
		foreach ( ShippingClasses::quantities( $package ) as $class => $qty ) {
			$entry = $settings['costs'][ $class ] ?? null;
			if ( $entry === null ) {
				continue;
			}
			$cost    = $entry['cost'] + $entry['per_item'] * $qty;
			$sum    += $cost;
			$highest = max( $highest, $cost );
		}
		return $settings['calculation'] === 'per_order' ? $highest : $sum;
	}
}
