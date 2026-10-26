<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Checkout\CartTaxes;
use Flexa\Commerce\Contracts\Tax\TaxRequest;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Render\CartTable;
use Flexa\Commerce\Tax\TaxService;

/**
 * The cart shows the tax of its items (doc 02 §7.12.5): at the address the
 * cart remembers, else at the default location; shipping tax waits for
 * checkout.
 */
final class TaxCartTest extends TaxTestCase {
	private ?CartServices $shop = null;

	public function tear_down(): void {
		parent::tear_down();
		$this->shop = null;
	}

	private function shop(): CartServices {
		if ( $this->shop !== null ) {
			return $this->shop;
		}
		$plugin     = Plugin::instance();
		$this->shop = new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			new MemoryTokenStore(),
			tax: fn (): TaxService => $this->tax(),
			totals: fn ( array $data, CartState $state ): array => ( new CartTaxes( $this->tax(), $this->shop()->presenter() ) )->extend( $data, $state, 0 ),
		);
		return $this->shop;
	}

	/** @return array<string,mixed> */
	private function cart_with( int $product, int $quantity ): array {
		$state = $this->shop()->cart()->add( 0, $product, 0, $quantity );
		return $this->shop()->presenter()->to_array( $state );
	}

	private function html(): string {
		$shop = $this->shop();
		return ( new CartTable( $shop->presenter(), $shop->cart(), $shop->pricing() ) )->html( $shop->cart()->state( 0 ) );
	}

	public function test_taxes_off_leave_the_cart_as_it_was(): void {
		$data = $this->cart_with( $this->simple( 1000 ), 2 );
		self::assertArrayNotHasKey( 'tax', $data );
		self::assertSame( 2000, $data['totals']['subtotal']['amount'] );
		self::assertStringNotContainsString( 'fxc-cart__tax', $this->html() );
	}

	public function test_excluding_display_adds_tax_rows_and_a_total(): void {
		$this->enable(
			[
				'tax_display_cart'  => 'excl',
				'tax_total_display' => 'itemized',
			]
		);
		$this->rate(
			[
				'state'    => 'CA',
				'rate'     => '6',
				'name'     => 'CA State',
				'priority' => 1,
			]
		);
		$this->rate(
			[
				'state'    => 'CA',
				'rate'     => '1.25',
				'name'     => 'County',
				'priority' => 2,
			]
		);
		$data = $this->cart_with( $this->simple( 1000 ), 2 );

		self::assertSame( 2000, $data['totals']['subtotal']['amount'] );
		self::assertSame( 145, $data['totals']['tax']['amount'] );
		self::assertSame( 2145, $data['totals']['total']['amount'] );
		self::assertSame( [ 'CA State', 'County' ], array_column( $data['tax']['lines'], 'label' ) );
		self::assertSame( [ 120, 25 ], array_map( static fn ( array $line ): int => $line['amount']['amount'], $data['tax']['lines'] ) );
		self::assertFalse( $data['tax']['pending'] );

		$html = $this->html();
		self::assertStringContainsString( 'data-wp-each--tax="state.cart.tax.lines"', $html );
		self::assertStringContainsString( 'CA State', $html );
		self::assertStringContainsString( '21.45', $html );
		self::assertStringContainsString( 'Shipping is calculated at checkout.', $html );
		self::assertLessThan( strpos( $html, 'fxc-cart__total-row' ), strpos( $html, 'class="fxc-cart__tax"' ), 'Excluded tax rows come before the total.' );
	}

	public function test_including_display_shows_gross_lines_and_includes_rows(): void {
		$this->enable(
			[
				'tax_display_cart'  => 'incl',
				'tax_total_display' => 'single',
			]
		);
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		$data = $this->cart_with( $this->simple( 1000 ), 2 );

		self::assertSame( 2145, $data['items'][0]['total']['amount'] );
		self::assertSame( 1073, $data['items'][0]['prices']['active']['amount'] );
		self::assertSame( 2145, $data['totals']['subtotal']['amount'] );
		self::assertSame( 2145, $data['totals']['total']['amount'] );
		self::assertCount( 1, $data['tax']['lines'] );
		self::assertStringStartsWith( 'Includes', $data['tax']['lines'][0]['text'] );

		$html = $this->html();
		self::assertStringContainsString( '10.73', $html, 'The unit price follows the display setting.' );
		self::assertStringContainsString( 'fxc-cart__tax--included', $html );
	}

	public function test_without_a_location_tax_waits_for_checkout(): void {
		$this->enable( [ 'tax_default_location' => 'none' ] );
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		$data = $this->cart_with( $this->simple( 1000 ), 1 );
		self::assertTrue( $data['tax']['pending'] );
		self::assertSame( 1000, $data['totals']['subtotal']['amount'] );
		self::assertStringContainsString( 'Tax and shipping are calculated at checkout.', $this->html() );
	}

	public function test_the_remembered_address_wins_over_the_store(): void {
		$this->enable( [ 'tax_display_cart' => 'excl' ] );
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		$this->rate(
			[
				'state' => 'NY',
				'rate'  => '4',
			]
		);
		$product = $this->simple( 1000 );
		$this->shop()->cart()->add( 0, $product, 0, 1 );
		$state = $this->shop()->cart()->set_shipping(
			0,
			[
				'country'  => 'US',
				'state'    => 'NY',
				'postcode' => '10001',
				'city'     => 'New York',
			],
			''
		);
		self::assertSame( 40, $this->shop()->presenter()->to_array( $state )['totals']['tax']['amount'] );
	}

	public function test_an_account_marked_tax_exempt_pays_no_tax(): void {
		$this->enable( [ 'tax_display_cart' => 'excl' ] );
		$this->rate(
			[
				'state' => 'CA',
				'rate'  => '7.25',
			]
		);
		$user    = self::factory()->user->create();
		$profile = new CustomerProfile();
		$state   = $this->shop()->cart()->add( 0, $this->simple( 1000 ), 0, 1 );
		$taxes   = new CartTaxes( $this->tax(), $this->shop()->presenter() );
		$data    = $this->shop()->presenter()->to_array( $state );

		self::assertSame( 73, $taxes->extend( $data, $state, $user )['totals']['tax']['amount'] );

		$profile->set_tax_exempt( $user, true );
		self::assertTrue( $taxes->request( $state, null, null, TaxRequest::CART, $user )->exempt );
		self::assertFalse( $taxes->request( $state, null, null, TaxRequest::CART, 0 )->exempt, 'Guests are never exempt by the flag.' );
		$exempt = $taxes->extend( $data, $state, $user );
		self::assertSame( 0, $exempt['totals']['tax']['amount'] ?? 0 );
		self::assertSame( 1000, $exempt['totals']['total']['amount'] );

		$profile->set_tax_exempt( $user, false );
		self::assertFalse( $taxes->request( $state, null, null, TaxRequest::CART, $user )->exempt );
	}
}
