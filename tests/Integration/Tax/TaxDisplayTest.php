<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Storefront\Render\ProductPrice;
use Flexa\Commerce\Tax\TaxService;

final class TaxDisplayTest extends TaxTestCase {
	private function shop(): CartServices {
		$plugin = Plugin::instance();
		return new CartServices( $plugin->services(), $plugin->catalog(), new MemoryTokenStore(), fn (): TaxService => $this->tax() );
	}

	private function pricing(): PricingService {
		return $this->shop()->pricing();
	}

	/** @return array{int,string} the displayed active price and the suffix */
	private function shown( int $product_id ): array {
		$product = $this->service()->get( $product_id );
		$pricing = $this->pricing();
		$unit    = $pricing->unit_price( $product, $product->base );
		self::assertNotNull( $unit );
		return [ $pricing->for_display( $product, $product->base, $unit )->active->amount, $pricing->display_suffix( $product, $product->base, $unit ) ];
	}

	public function test_shop_prices_follow_the_display_setting(): void {
		$product = $this->simple( 1000 );
		self::assertSame( [ 1000, '' ], $this->shown( $product ), 'taxes off' );

		$this->enable(
			[
				'tax_display_shop'     => 'incl',
				'price_display_suffix' => 'incl. tax',
			]
		);
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		self::assertSame( [ 1073, 'incl. tax' ], $this->shown( $product ) );

		$this->enable(
			[
				'tax_display_shop'     => 'excl',
				'price_display_suffix' => '({price_including_tax} with tax)',
			]
		);
		[ $amount, $suffix ] = $this->shown( $product );
		self::assertSame( 1000, $amount );
		self::assertStringContainsString( '10.73', $suffix );
	}

	/** The shop's price filter scales its bounds by this (ADR-055). */
	public function test_the_catalog_ratio(): void {
		self::assertSame( 1.0, $this->pricing()->catalog_ratio(), 'taxes off' );
		$this->enable( [ 'tax_display_shop' => 'incl' ] );
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		self::assertEqualsWithDelta( 1.0725, $this->pricing()->catalog_ratio(), 0.000001 );
		$this->enable( [ 'tax_display_shop' => 'excl' ] );
		self::assertSame( 1.0, $this->pricing()->catalog_ratio() );
	}

	public function test_inclusive_prices_can_be_shown_without_tax(): void {
		$product = $this->simple( 1073 );
		$this->enable(
			[
				'prices_include_tax' => true,
				'tax_display_shop'   => 'excl',
			]
		);
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		self::assertSame( 1000, $this->shown( $product )[0] );
	}

	public function test_a_product_that_is_not_taxable_is_shown_as_stored(): void {
		$product = $this->simple( 1000 );
		$this->save(
			$product,
			[
				'base' => [
					'regular_price' => 1000,
					'tax_status'    => 'none',
				],
			]
		);
		$this->enable(
			[
				'tax_display_shop'     => 'incl',
				'price_display_suffix' => 'incl. tax',
			]
		);
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		self::assertSame( [ 1000, '' ], $this->shown( $product ) );
	}

	public function test_the_price_markup_carries_the_suffix(): void {
		$product = $this->simple( 1000 );
		$this->enable(
			[
				'tax_display_shop'     => 'incl',
				'price_display_suffix' => 'incl. tax',
			]
		);
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		$shop = $this->shop();
		$html = ( new ProductPrice( $shop->pricing(), $shop->cart() ) )->html( $this->service()->get( $product ) );
		self::assertStringContainsString( '10.73', $html );
		self::assertStringContainsString( 'fxc-price__suffix', $html );
		self::assertStringContainsString( 'incl. tax', $html );
	}
}
