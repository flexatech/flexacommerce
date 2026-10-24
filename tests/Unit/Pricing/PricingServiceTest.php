<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Pricing;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Catalog\VariantView;
use Flexa\Commerce\Contracts\Pricing\PriceContext;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\StoreMoneyFactory;
use Flexa\Commerce\Tests\Unit\TestCase;

final class PricingServiceTest extends TestCase {
	private function service(): PricingService {
		return new PricingService( new StoreMoneyFactory(), new Clock( new \DateTimeImmutable( '2026-10-24 12:00:00', new \DateTimeZone( 'UTC' ) ) ) );
	}

	private function product( Variant $base ): Product {
		return new Product( 7, 'simple', 'publish', 'Mug', [], $base, [] );
	}

	public function test_sale_price_inside_the_window(): void {
		$base  = Variant::base_for( 7 )->with(
			[
				'regular_price' => 1000,
				'sale_price'    => 800,
				'sale_to'       => '2026-11-01 00:00:00',
			]
		);
		$price = $this->service()->unit_price( $this->product( $base ), $base );
		self::assertNotNull( $price );
		self::assertSame( 800, $price->active->amount );
		self::assertSame( 1000, $price->regular->amount );
		self::assertTrue( $price->on_sale );
	}

	public function test_no_regular_price_cannot_be_bought(): void {
		$base = Variant::base_for( 7 );
		self::assertNull( $this->service()->unit_price( $this->product( $base ), $base ) );
	}

	public function test_filter_changes_the_unit_price_and_gets_the_context(): void {
		$seen = null;
		add_filter(
			'flexacommerce.price.unit',
			static function ( int $amount, VariantView $view, PriceContext $context ) use ( &$seen ): int {
				$seen = [ $view->product_id, $context->quantity ];
				return $context->quantity >= 10 ? $amount - 100 : $amount;
			}
		);
		$base    = Variant::base_for( 7 )->with( [ 'regular_price' => 1000 ] );
		$service = $this->service();
		self::assertSame( 900, $service->unit_price( $this->product( $base ), $base, 10 )?->active->amount );
		self::assertSame( [ 7, 10 ], $seen );
		self::assertSame( 1000, $service->unit_price( $this->product( $base ), $base, 1 )?->active->amount );
	}

	public function test_filter_cannot_make_a_price_negative(): void {
		add_filter( 'flexacommerce.price.unit', static fn (): int => -50 );
		$base = Variant::base_for( 7 )->with( [ 'regular_price' => 1000 ] );
		self::assertSame( 0, $this->service()->unit_price( $this->product( $base ), $base )?->active->amount );
	}

	public function test_line_total_sum_and_allocation(): void {
		$service = $this->service();
		$money   = new StoreMoneyFactory();
		$base    = Variant::base_for( 7 )->with( [ 'regular_price' => 333 ] );
		$unit    = $service->unit_price( $this->product( $base ), $base );
		self::assertNotNull( $unit );
		$a = $service->line_total( $unit, 3 );
		self::assertSame( 999, $a->amount );
		self::assertSame( 1998, $service->sum( [ $a, $a ] )->amount );

		$parts = $service->allocate( $money->from_minor( 100 ), [ $money->from_minor( 1 ), $money->from_minor( 2 ) ] );
		self::assertSame( 100, array_sum( array_map( static fn ( $m ): int => $m->amount, $parts ) ) );
		self::assertSame( [], $service->allocate( $money->from_minor( 100 ), [] ) );

		$even = $service->allocate( $money->from_minor( 10 ), [ $money->from_minor( 0 ), $money->from_minor( 0 ) ] );
		self::assertSame( [ 5, 5 ], array_map( static fn ( $m ): int => $m->amount, $even ) );
	}
}
