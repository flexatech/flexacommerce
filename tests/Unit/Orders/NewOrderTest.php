<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Orders;

use Flexa\Commerce\Contracts\Exceptions\OrderTotalsMismatch;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Tests\Unit\TestCase;

final class NewOrderTest extends TestCase {
	/** @param list<NewOrderItem> $items */
	private static function order( array $items, int $subtotal, int $shipping, int $tax, int $total, bool $inclusive = false ): NewOrder {
		return new NewOrder( 'pending', 'USD', 2, $items, $subtotal, $shipping, $total, 'a@example.com', tax_total: $tax, prices_include_tax: $inclusive );
	}

	/** @return list<NewOrderItem> */
	private static function taxed_items( int $line_tax = 73, int $shipping_tax = 36, int $tax_line = 109 ): array {
		return [
			NewOrderItem::product( 7, 7, 7, 'Mug', null, [], 2, 500, null, [ '1' => $line_tax ] ),
			NewOrderItem::shipping( 'flat:1', 'Flat', 500, [ '1' => $shipping_tax ] ),
			NewOrderItem::tax( 'US-CA-TAX-1', 'CA Tax', $tax_line, [ 'rate_id' => 1 ] ),
		];
	}

	public function test_exclusive_order_with_tax_adds_up(): void {
		self::order( self::taxed_items(), 1000, 500, 109, 1609 )->assert_totals();
		$this->addToAssertionCount( 1 );
	}

	public function test_inclusive_line_may_differ_from_unit_price_times_quantity(): void {
		$items = [
			NewOrderItem::product( 7, 7, 7, 'Mug', null, [], 3, 333, 908, [ '1' => 91 ] ),
			NewOrderItem::tax( 'GB-TAX-1', 'VAT', 91, [] ),
		];
		self::order( $items, 908, 0, 91, 999, true )->assert_totals();
		$this->expectException( OrderTotalsMismatch::class );
		self::order( $items, 908, 0, 91, 999 )->assert_totals();
	}

	public function test_tax_lines_must_match_the_item_taxes(): void {
		$this->expectException( OrderTotalsMismatch::class );
		self::order( self::taxed_items( 73, 36, 110 ), 1000, 500, 110, 1610 )->assert_totals();
	}

	public function test_item_taxes_must_match_the_order_tax(): void {
		$this->expectException( OrderTotalsMismatch::class );
		self::order( self::taxed_items( 74, 36, 109 ), 1000, 500, 109, 1609 )->assert_totals();
	}

	public function test_total_must_include_the_tax(): void {
		$this->expectException( OrderTotalsMismatch::class );
		self::order( self::taxed_items(), 1000, 500, 109, 1500 )->assert_totals();
	}
}
