<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Orders;

use Flexa\Commerce\Contracts\Exceptions\OrderTotalsMismatch;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Orders\OrderStateMachine;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Tests\Unit\TestCase;

final class OrderStateMachineTest extends TestCase {
	/** Doc 02 §7.6, written out pair by pair so a change to the table shows up here. */
	private const ALLOWED = [
		'pending>on-hold',
		'pending>processing',
		'pending>cancelled',
		'pending>failed',
		'on-hold>processing',
		'on-hold>cancelled',
		'processing>on-hold',
		'processing>completed',
		'processing>cancelled',
		'processing>refunded',
		'completed>processing',
		'completed>refunded',
		'failed>pending',
		'failed>processing',
		'failed>cancelled',
	];

	/** Pairs whose stock effect is not 0: -1 takes stock again, 1 gives it back. */
	private const STOCK = [
		'pending>cancelled'    => 1,
		'pending>failed'       => 1,
		'on-hold>cancelled'    => 1,
		'processing>cancelled' => 1,
		'failed>pending'       => -1,
		'failed>processing'    => -1,
	];

	public function test_every_pair_of_statuses(): void {
		foreach ( S::ALL as $from ) {
			foreach ( S::ALL as $to ) {
				$pair = "{$from}>{$to}";
				self::assertSame( in_array( $pair, self::ALLOWED, true ), OrderStateMachine::allows( $from, $to ), $pair );
				if ( OrderStateMachine::allows( $from, $to ) ) {
					self::assertSame( self::STOCK[ $pair ] ?? 0, OrderStateMachine::stock_effect( $from, $to ), $pair );
				}
			}
		}
	}

	public function test_terminal_statuses(): void {
		$terminal = array_values( array_filter( S::ALL, [ OrderStateMachine::class, 'is_terminal' ] ) );
		self::assertSame( [ S::CANCELLED, S::REFUNDED ], $terminal );
		self::assertFalse( OrderStateMachine::allows( 'unknown', S::PENDING ) );
	}

	public function test_refunded_never_moves_stock(): void {
		foreach ( S::ALL as $from ) {
			self::assertSame( 0, OrderStateMachine::stock_effect( $from, S::REFUNDED ), $from );
		}
	}

	public function test_payment_status(): void {
		self::assertSame( S::UNPAID, S::payment_status( 1000, 0, 0 ) );
		self::assertSame( S::PARTIALLY_PAID, S::payment_status( 1000, 400, 0 ) );
		self::assertSame( S::PAID, S::payment_status( 1000, 1000, 0 ) );
		self::assertSame( S::PARTIALLY_REFUNDED, S::payment_status( 1000, 1000, 300 ) );
		self::assertSame( S::FULLY_REFUNDED, S::payment_status( 1000, 1000, 1000 ) );
	}

	public function test_totals_must_add_up(): void {
		$item = NewOrderItem::product( 1, 1, 1, 'Mug', null, [], 2, 500 );
		$ship = NewOrderItem::shipping( 'flat_rate:1', 'Flat rate', 300 );
		( new NewOrder( S::PENDING, 'USD', 2, [ $item, $ship ], 1000, 300, 1300, 'a@example.com' ) )->assert_totals();

		$bad = [
			'total'    => new NewOrder( S::PENDING, 'USD', 2, [ $item, $ship ], 1000, 300, 1301, 'a@example.com' ),
			'subtotal' => new NewOrder( S::PENDING, 'USD', 2, [ $item, $ship ], 900, 300, 1200, 'a@example.com' ),
			'shipping' => new NewOrder( S::PENDING, 'USD', 2, [ $item ], 1000, 300, 1300, 'a@example.com' ),
			'line'     => new NewOrder( S::PENDING, 'USD', 2, [ new NewOrderItem( 'product', 'Mug', 2, 500, 999, 999 ) ], 999, 0, 999, 'a@example.com' ),
		];
		foreach ( $bad as $case => $order ) {
			try {
				$order->assert_totals();
				self::fail( "Expected a totals mismatch for {$case}." );
			} catch ( OrderTotalsMismatch ) {
				self::assertTrue( true );
			}
		}
	}
}
