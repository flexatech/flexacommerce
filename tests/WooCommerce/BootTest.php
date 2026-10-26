<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Automattic\WooCommerce\Utilities\OrderUtil;

final class BootTest extends \WP_UnitTestCase {
	public function test_storage_mode(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertSame( FXC_TESTS_WC_STORAGE !== 'legacy', OrderUtil::custom_orders_table_usage_is_enabled() );
		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->set_total( '10.00' );
		$order->save();
		$this->assertGreaterThan( 0, $order->get_id() );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}
}
