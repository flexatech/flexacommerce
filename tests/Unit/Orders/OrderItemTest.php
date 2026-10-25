<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Orders;

use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Tests\Unit\TestCase;

final class OrderItemTest extends TestCase {
	/**
	 * @param array<string,int> $taxes
	 * @param array<string,int> $refunded_taxes
	 */
	private static function item( int $total, array $taxes, int $refunded_total = 0, array $refunded_taxes = [] ): OrderItem {
		return new OrderItem(
			1, 1, OrderItem::PRODUCT, 0, 7, 7, '', 'Mug', null, [], 2, intdiv( $total, 2 ), $total, 0, array_sum( $taxes ), $total, 0, 0, $refunded_total, 0,
			[
				'taxes'          => $taxes,
				'refunded_taxes' => $refunded_taxes,
			]
		);
	}

	public function test_partial_refund_splits_in_proportion(): void {
		$split = self::item( 1000, [ '5' => 100 ] )->refund_split( 550 );
		self::assertSame( 500, $split['net'] );
		self::assertSame( [ '5' => 50 ], $split['taxes'] );
	}

	public function test_refunding_the_rest_returns_exactly_the_tax_left(): void {
		$item  = self::item( 999, [ '5' => 73, '9' => 11 ] );
		$first = $item->refund_split( 333 );
		$rest  = self::item( 999, [ '5' => 73, '9' => 11 ], 333, $first['taxes'] )->refund_split( 999 + 84 - 333 );
		self::assertSame( 73, ( $first['taxes']['5'] ?? 0 ) + ( $rest['taxes']['5'] ?? 0 ) );
		self::assertSame( 11, ( $first['taxes']['9'] ?? 0 ) + ( $rest['taxes']['9'] ?? 0 ) );
		self::assertSame( 999, $first['net'] + $rest['net'] );
	}

	public function test_a_line_without_tax_refunds_net_only(): void {
		self::assertSame( [ 'net' => 400, 'taxes' => [] ], self::item( 1000, [] )->refund_split( 400 ) );
		self::assertSame( [ 'net' => 0, 'taxes' => [] ], self::item( 1000, [ '5' => 100 ] )->refund_split( -5 ) );
	}
}
