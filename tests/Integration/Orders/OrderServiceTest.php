<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Orders;

use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidRefund;
use Flexa\Commerce\Contracts\Exceptions\InvalidTransition;
use Flexa\Commerce\Contracts\Orders\OrderView;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderStatus as S;
use Flexa\Commerce\Orders\RefundLine;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Plugin;

final class OrderServiceTest extends OrderTestCase {
	/** @var list<array{string,mixed}> */
	private array $events = [];

	public function set_up(): void {
		parent::set_up();
		$this->events = [];
		foreach ( [ 'created', 'status_changed', 'paid', 'refunded', 'needs_attention', 'note_added' ] as $event ) {
			add_action(
				"flexacommerce.order.{$event}",
				function ( OrderView $view, mixed ...$args ) use ( $event ): void {
					$this->events[] = [ $event, $args === [] ? $view->status : $args ];
				},
				10,
				4
			);
		}
	}

	/** @return list<string> */
	private function event_names(): array {
		return array_map( static fn ( array $e ): string => $e[0], $this->events );
	}

	public function test_create_takes_stock_once_per_checkout_token(): void {
		$a = $this->simple( 1500, 5 );
		$b = $this->simple( 700 );

		$order = $this->place(
			[
				$a => 2,
				$b => 1,
			],
			300,
			'token-' . $this->run
		);
		self::assertSame( S::PENDING, $order->status );
		self::assertSame( 4000, $order->total );
		self::assertSame( 3, $this->stock_of( $a ) );
		$lines = $order->items_of_type( OrderItem::PRODUCT );
		self::assertSame( [ 2, 0 ], [ $lines[0]->stock_reduced_qty, $lines[1]->stock_reduced_qty ] );
		self::assertSame( 43, strlen( $order->order_key ) );
		self::assertSame( [ 'created' ], $this->event_names() );

		// A double submit returns the same order and takes nothing more.
		$again = $this->place(
			[
				$a => 2,
				$b => 1,
			],
			300,
			'token-' . $this->run
		);
		self::assertSame( $order->id, $again->id );
		self::assertSame( 3, $this->stock_of( $a ) );
	}

	public function test_create_without_stock_writes_nothing(): void {
		global $wpdb;
		$a      = $this->simple( 1000, 1 );
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Flexa\Commerce\Database\Schema::table( 'orders' ) ); // phpcs:ignore WordPress.DB
		try {
			$this->place( [ $a => 2 ] );
			self::fail( 'Expected InsufficientStock.' );
		} catch ( InsufficientStock ) {
			self::assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Flexa\Commerce\Database\Schema::table( 'orders' ) ) ); // phpcs:ignore WordPress.DB
		}
		self::assertSame( 1, $this->stock_of( $a ) );
		self::assertSame( [], $this->events );
	}

	public function test_cancel_returns_stock_exactly_once(): void {
		$a     = $this->simple( 1000, 5 );
		$order = $this->place( [ $a => 3 ] );
		self::assertSame( 2, $this->stock_of( $a ) );

		$cancelled = $this->orders()->cancel( $order->id, TransitionContext::admin() );
		self::assertSame( S::CANCELLED, $cancelled->status );
		self::assertNotNull( $cancelled->cancelled_at );
		self::assertSame( 0, $cancelled->items_of_type( OrderItem::PRODUCT )[0]->stock_reduced_qty );
		self::assertSame( 5, $this->stock_of( $a ) );

		// Same status is a no-op; a terminal status goes nowhere.
		$this->orders()->cancel( $order->id, TransitionContext::admin() );
		self::assertSame( 5, $this->stock_of( $a ) );
		$this->expectException( InvalidTransition::class );
		$this->orders()->transition( $order->id, S::PROCESSING, TransitionContext::admin() );
	}

	public function test_failed_order_takes_stock_again_or_stays_failed(): void {
		$a     = $this->simple( 1000, 2 );
		$order = $this->place( [ $a => 2 ] );
		$this->orders()->record_payment_failed( $order->id, 'card declined', TransitionContext::system() );
		self::assertSame( 2, $this->stock_of( $a ) );
		self::assertSame( S::FAILED, $this->orders()->get( $order->id )->status );

		// Someone else bought the stock in the meantime.
		$this->place( [ $a => 1 ] );
		try {
			$this->orders()->transition( $order->id, S::PENDING, TransitionContext::admin() );
			self::fail( 'Expected InsufficientStock.' );
		} catch ( InsufficientStock ) {
			self::assertSame( S::FAILED, $this->orders()->get( $order->id )->status );
			self::assertSame( 1, $this->stock_of( $a ) );
		}
	}

	public function test_full_payment_moves_to_processing(): void {
		$a     = $this->simple( 1000, 5 );
		$order = $this->place( [ $a => 1 ], 500 );

		$part = $this->orders()->record_payment( $order->id, 400, TransitionContext::system() );
		self::assertSame( S::PENDING, $part->status );
		self::assertSame( S::PARTIALLY_PAID, $part->payment_status );

		$paid = $this->orders()->record_payment( $order->id, 1100, TransitionContext::system() );
		self::assertSame( S::PROCESSING, $paid->status );
		self::assertSame( S::PAID, $paid->payment_status );
		self::assertSame( 1500, $paid->amount_paid );
		self::assertNotNull( $paid->paid_at );
		self::assertSame( 4, $this->stock_of( $a ) );
	}

	public function test_late_payment_on_a_failed_order_without_stock_goes_on_hold(): void {
		$a     = $this->simple( 1000, 1 );
		$order = $this->place( [ $a => 1 ] );
		$this->orders()->record_payment_failed( $order->id, '', TransitionContext::system() );
		$this->place( [ $a => 1 ] );

		$late = $this->orders()->record_payment( $order->id, 1000, new TransitionContext( 'gateway' ) );
		self::assertSame( S::ON_HOLD, $late->status );
		self::assertTrue( $late->needs_attention );
		self::assertSame( S::PAID, $late->payment_status );
		self::assertSame( 0, $this->stock_of( $a ) );
		self::assertContains( 'needs_attention', $this->event_names() );
	}

	public function test_payment_on_a_cancelled_order_needs_attention(): void {
		$a     = $this->simple( 1000, 3 );
		$order = $this->place( [ $a => 1 ] );
		$this->orders()->cancel( $order->id, TransitionContext::system() );

		$late = $this->orders()->record_payment( $order->id, 1000, new TransitionContext( 'gateway' ) );
		self::assertSame( S::CANCELLED, $late->status );
		self::assertTrue( $late->needs_attention );
		self::assertSame( 3, $this->stock_of( $a ) );

		$cleared = $this->orders()->clear_attention( $order->id, TransitionContext::admin() );
		self::assertFalse( $cleared->needs_attention );
	}

	public function test_refunds_restock_by_line_and_end_in_refunded(): void {
		$a     = $this->simple( 1000, 5 );
		$order = $this->place( [ $a => 3 ] );
		$order = $this->orders()->record_payment( $order->id, 3000, TransitionContext::system() );
		$item  = $order->items_of_type( OrderItem::PRODUCT )[0];

		$part = $this->orders()->record_refund( $order->id, 1000, [ new RefundLine( $item->id, 1, 1000, true ) ], 'damaged', TransitionContext::admin() );
		self::assertSame( S::PROCESSING, $part->status );
		self::assertSame( S::PARTIALLY_REFUNDED, $part->payment_status );
		self::assertSame( 3, $this->stock_of( $a ) );
		$line = $part->item( $item->id );
		self::assertNotNull( $line );
		self::assertSame( [ 1, 2, 1 ], [ $line->refunded_qty, $line->stock_reduced_qty, $line->restocked_qty ] );

		try {
			$this->orders()->record_refund( $order->id, 2001, [], '', TransitionContext::admin() );
			self::fail( 'Expected InvalidRefund.' );
		} catch ( InvalidRefund ) {
			self::assertSame( 1000, $this->orders()->get( $order->id )->amount_refunded );
		}

		$full = $this->orders()->record_refund( $order->id, 2000, [ new RefundLine( $item->id, 2, 2000, false ) ], '', TransitionContext::admin() );
		self::assertSame( S::REFUNDED, $full->status );
		self::assertSame( S::FULLY_REFUNDED, $full->payment_status );
		self::assertSame( 3, $this->stock_of( $a ) );

		try {
			$this->orders()->record_refund( $order->id, 1, [], '', TransitionContext::admin() );
			self::fail( 'Expected InvalidRefund.' );
		} catch ( InvalidRefund ) {
			self::assertTrue( true );
		}
	}

	public function test_refund_lines_are_checked(): void {
		$a     = $this->simple( 1000, 5 );
		$order = $this->orders()->record_payment( $this->place( [ $a => 1 ] )->id, 1000, TransitionContext::system() );
		$item  = $order->items_of_type( OrderItem::PRODUCT )[0];
		foreach ( [ new RefundLine( $item->id, 2, 0 ), new RefundLine( $item->id + 999, 1, 0 ), new RefundLine( $item->id, 1, 1001 ) ] as $line ) {
			try {
				$this->orders()->record_refund( $order->id, 100, [ $line ], '', TransitionContext::admin() );
				self::fail( 'Expected InvalidRefund.' );
			} catch ( InvalidRefund ) {
				self::assertSame( 0, $this->orders()->get( $order->id )->amount_refunded );
			}
		}
	}

	public function test_filters_may_forbid_and_pick_the_initial_status(): void {
		$a = $this->simple( 1000 );
		add_filter( 'flexacommerce.order.initial_status', static fn (): string => S::ON_HOLD );
		$order = $this->place( [ $a => 1 ] );
		self::assertSame( S::ON_HOLD, $order->status );
		remove_all_filters( 'flexacommerce.order.initial_status' );

		add_filter( 'flexacommerce.order.initial_status', static fn (): string => S::COMPLETED );
		self::assertSame( S::PENDING, $this->place( [ $a => 1 ] )->status );

		add_filter( 'flexacommerce.order.can_transition', static fn ( bool $ok, string $from, string $to ): bool => $to !== S::CANCELLED, 10, 3 );
		$this->expectException( InvalidTransition::class );
		$this->orders()->cancel( $order->id, TransitionContext::admin() );
	}

	public function test_events_fire_after_commit_with_fresh_views(): void {
		$a            = $this->simple( 1000, 5 );
		$order        = $this->place( [ $a => 1 ] );
		$this->events = [];
		$this->orders()->add_note( $order->id, 'Left at the door.', true, TransitionContext::admin() );
		$this->orders()->transition( $order->id, S::PROCESSING, TransitionContext::admin() );
		$this->orders()->transition( $order->id, S::COMPLETED, TransitionContext::admin() );
		self::assertSame( [ 'note_added', 'status_changed', 'status_changed' ], $this->event_names() );
		self::assertSame( [ S::PROCESSING, S::COMPLETED, 'admin' ], $this->events[2][1] );

		$history = Plugin::instance()->orders()->history()->for_order( $order->id, true );
		self::assertSame( 'Left at the door.', $history[0]['message'] );
	}

	public function test_stale_pending_orders_expire(): void {
		$a     = $this->simple( 1000, 5 );
		$old   = $this->place( [ $a => 2 ] );
		$fresh = $this->place( [ $a => 1 ] );
		Plugin::instance()->orders()->orders()->update( $old->id, [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ] );

		self::assertSame( 1, $this->orders()->expire_pending() );
		self::assertSame( S::CANCELLED, $this->orders()->get( $old->id )->status );
		self::assertSame( S::PENDING, $this->orders()->get( $fresh->id )->status );
		self::assertSame( 4, $this->stock_of( $a ) );
	}

	public function test_reader_rejects_reserved_meta_keys(): void {
		$order  = $this->place( [ $this->simple( 1000 ) => 1 ] );
		$reader = Plugin::instance()->orders()->reader();
		$reader->set_meta( $order->id, 'acme_ref', [ 'a' => 1 ] );
		self::assertSame( '{"a":1}', $reader->get_meta( $order->id, 'acme_ref' ) );
		self::assertSame( $order->order_number, $reader->find_by_number( $order->order_number )?->number );
		$this->expectException( \InvalidArgumentException::class );
		$reader->set_meta( $order->id, '_fxc_secret', 'x' );
	}
}
