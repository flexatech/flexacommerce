<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

defined( 'ABSPATH' ) || exit;

/** The one shipping write WooCommerce has no CRUD method for. */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- WooCommerce has no API to turn a zone method on or off; its REST controller runs the same update.
final class WooShippingRepository {
	public function __construct( private readonly \wpdb $wpdb ) {}

	public function set_enabled( int $instance_id, bool $enabled ): void {
		$this->wpdb->update( $this->wpdb->prefix . 'woocommerce_shipping_zone_methods', [ 'is_enabled' => $enabled ? 1 : 0 ], [ 'instance_id' => $instance_id ] );
	}
}
