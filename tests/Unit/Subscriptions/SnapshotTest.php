<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Subscriptions;

use Flexa\Commerce\Catalog\SubscriptionPlan;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Subscriptions\Snapshot;
use Flexa\Commerce\Tests\Unit\TestCase;

final class SnapshotTest extends TestCase {
	public function test_round_trip(): void {
		$snapshot = new Snapshot(
			new SubscriptionPlan( SubscriptionPlan::MONTH, 1, 6, 0, 0 ),
			[
				new NewOrderItem( OrderItem::PRODUCT, 'Coffee beans', 2, 1000, 2000, 1800, 7, 8, discount_total: 200, meta: [ 'subscription' => [ 'period' => 'month' ] ] ),
				new NewOrderItem( OrderItem::PRODUCT, 'Filter papers', 1, 0, 0, 0, 9, 10, meta: [ 'bundled_by' => 0 ] ),
				new NewOrderItem( OrderItem::SHIPPING, 'Flat rate', 1, 500, 500, 500 ),
			],
			1800,
			500,
			230,
			2530,
			false,
			200,
			0
		);
		$back     = Snapshot::from_json( $snapshot->to_json() );
		self::assertNotNull( $back );
		self::assertSame( $snapshot->to_array(), $back->to_array() );
		self::assertCount( 2, $back->products() );
		self::assertSame( 'Coffee beans × 2', $back->title() );
	}

	public function test_bad_json_is_no_snapshot(): void {
		self::assertNull( Snapshot::from_json( null ) );
		self::assertNull( Snapshot::from_json( '' ) );
		self::assertNull( Snapshot::from_json( '{"plan":{"period":"hour"}}' ) );
		self::assertNull( Snapshot::from_json( 'nope' ) );
	}
}
