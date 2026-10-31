<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

/**
 * A product type an extension adds, which no FlexaCommerce type matches, so
 * the migration rejects it (the golden store's rejected product).
 */
final class BundleProduct extends \WC_Product_Simple {
	public const TYPE = 'bundle';

	public function get_type() {
		return self::TYPE;
	}

	public static function register(): void {
		add_filter( 'woocommerce_product_class', static fn ( string $classname, string $type ): string => $type === self::TYPE ? self::class : $classname, 10, 2 );
	}
}
