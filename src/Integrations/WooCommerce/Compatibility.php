<?php

declare(strict_types=1);

namespace Flexa\Commerce\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * FlexaCommerce never reads WooCommerce order storage at runtime, so it is
 * compatible with HPOS. The declaration keeps WooCommerce from warning when
 * both plugins are active during a migration.
 */
final class Compatibility {
	public static function declare(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FLEXACOMMERCE_FILE, true );
		}
	}
}
