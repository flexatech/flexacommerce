<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\Tax\TaxCalculator;
use Flexa\Commerce\Contracts\Tax\TaxRequest;
use Flexa\Commerce\Contracts\Tax\TaxResult;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\RefundLine;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;

final class TaxCheckoutTest extends TaxTestCase {
	private const BILLING = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Infinite Loop',
		'city'       => 'Cupertino',
		'state'      => 'CA',
		'postcode'   => '95014',
		'country'    => 'US',
		'email'      => 'ada@example.com',
	];

	private string $rate_id = '';

	public function set_up(): void {
		parent::set_up();
		$gateways = Plugin::instance()->payments()->service()->gateways();
		$cod      = $gateways->get( 'cod' );
		self::assertNotNull( $cod );
		$gateways->settings()->save( $cod, [ 'enabled' => true ] );
		$zones         = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		$this->rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
	}

	public function tear_down(): void {
		remove_all_filters( 'flexacommerce.tax.calculator' );
		delete_option( GatewaySettings::PREFIX . 'cod' );
		delete_option( ZoneRepository::OPTION );
		parent::tear_down();
	}

	public function test_tax_is_added_on_top_of_exclusive_prices(): void {
		$this->enable();
		$this->rate( [ 'state' => 'CA', 'rate' => '7.25', 'name' => 'CA Tax' ] );
		[ $checkout, $token ] = $this->cart( 2 );

		$form = $checkout->form_state( 0 );
		self::assertSame( 181, $form['totals']['tax']['amount'] );
		self::assertSame( 2681, $form['totals']['total']['amount'] );

		$order = $checkout->place_order( $this->request( $token, 2681 ) )->order;
		self::assertSame( 2681, $order->total );
		self::assertSame( 181, $order->tax_total );
		self::assertSame( 2000, $order->subtotal );
		self::assertFalse( $order->prices_include_tax );
		self::assertSame( 145, $this->line( $order, OrderItem::PRODUCT )->tax_total );
		self::assertSame( 36, $this->line( $order, OrderItem::SHIPPING )->tax_total );
		self::assertSame( 181, $this->line( $order, OrderItem::TAX )->total );
		self::assertFalse( $order->needs_attention );
	}

	public function test_inclusive_prices_hold_the_tax_inside(): void {
		$this->enable( [ 'prices_include_tax' => true ] );
		$this->rate( [ 'state' => 'CA', 'rate' => '7.25' ] );
		[ $checkout, $token ] = $this->cart( 2 );

		$order = $checkout->place_order( $this->request( $token, 2536 ) )->order;
		self::assertTrue( $order->prices_include_tax );
		self::assertSame( 2536, $order->total );
		self::assertSame( 171, $order->tax_total );
		$item = $this->line( $order, OrderItem::PRODUCT );
		self::assertSame( 135, $item->tax_total );
		self::assertSame( 1865, $item->total, 'the line stores its net' );
	}

	public function test_no_rate_for_the_address_means_no_tax(): void {
		$this->enable();
		$this->rate( [ 'state' => 'NY', 'rate' => '4' ] );
		[ $checkout, $token ] = $this->cart( 1 );
		$order = $checkout->place_order( $this->request( $token, 1500 ) )->order;
		self::assertSame( 0, $order->tax_total );
		self::assertSame( [ OrderItem::PRODUCT, OrderItem::SHIPPING ], array_map( static fn ( OrderItem $i ): string => $i->type, $order->items ) );
	}

	public function test_a_failing_calculator_falls_back_to_the_table_and_flags_the_order(): void {
		$this->enable();
		$this->rate( [ 'state' => 'CA', 'rate' => '7.25' ] );
		add_filter(
			'flexacommerce.tax.calculator',
			static fn (): TaxCalculator => new class() implements TaxCalculator {
				public function calculate( TaxRequest $request ): TaxResult {
					throw new \RuntimeException( 'service down' );
				}
			}
		);
		[ $checkout, $token ] = $this->cart( 2 );
		$order = $checkout->place_order( $this->request( $token, 2681 ) )->order;
		self::assertSame( 181, $order->tax_total );
		self::assertTrue( $order->needs_attention );
	}

	public function test_a_refund_splits_off_the_line_tax(): void {
		$this->enable();
		$this->rate( [ 'state' => 'CA', 'rate' => '7.25' ] );
		[ $checkout, $token ] = $this->cart( 2 );
		$orders = Plugin::instance()->orders()->service();
		$order  = $checkout->place_order( $this->request( $token, 2681 ) )->order;
		$orders->record_payment( $order->id, $order->total, TransitionContext::system() );

		$item  = $this->line( $order, OrderItem::PRODUCT );
		$after = $orders->record_refund( $order->id, 1072, [ new RefundLine( $item->id, 1, 1072 ) ], '', TransitionContext::system() );
		$item  = $this->line( $after, OrderItem::PRODUCT );
		self::assertSame( [ 72 ], array_values( $item->refunded_taxes() ) );

		$after = $orders->record_refund( $order->id, 1073, [ new RefundLine( $item->id, 1, 1073 ) ], '', TransitionContext::system() );
		self::assertSame( [ 145 ], array_values( $this->line( $after, OrderItem::PRODUCT )->refunded_taxes() ), 'the last refund takes the rest' );
	}

	public function test_stacked_us_rates_store_one_tax_line_each_and_a_full_refund_returns_them(): void {
		$this->enable();
		// Cupertino, Santa Clara County: state, county, city and a district.
		$this->rate( [ 'state' => 'CA', 'rate' => '6', 'name' => 'CA State', 'priority' => 1 ] );
		$this->rate( [ 'state' => 'CA', 'rate' => '0.25', 'name' => 'Santa Clara County', 'priority' => 2, 'postcodes' => '950*' ] );
		$this->rate( [ 'state' => 'CA', 'rate' => '1', 'name' => 'Cupertino', 'priority' => 3, 'postcodes' => '95014', 'cities' => 'Cupertino' ] );
		$this->rate( [ 'state' => 'CA', 'rate' => '2.125', 'name' => 'District', 'priority' => 4, 'postcodes' => '95000...95099', 'shipping' => false ] );
		$this->rate( [ 'state' => 'CA', 'rate' => '5', 'name' => 'Other ZIP', 'priority' => 3, 'postcodes' => '94016' ] );
		[ $checkout, $token ] = $this->cart( 2 );

		// The cart keeps the city of the quoted address, so the city rate is in the form total too.
		$checkout->update_shipping( 0, new Destination( 'US', 'CA', '95014', 'Cupertino' ), $this->rate_id );
		self::assertSame( 2724, $checkout->form_state( 0 )['totals']['total']['amount'] );

		// Items 2000: 120 + 5 + 20 + 42.5→43 = 188. Shipping 500 (no district): 30 + 1.25→1 + 5 = 36.
		$order = $checkout->place_order( $this->request( $token, 2724 ) )->order;
		self::assertSame( 224, $order->tax_total );
		$lines = array_values( array_filter( $order->items, static fn ( OrderItem $i ): bool => $i->type === OrderItem::TAX ) );
		self::assertSame( [ 150, 6, 25, 43 ], array_map( static fn ( OrderItem $i ): int => $i->total, $lines ) );
		self::assertSame( $order->tax_total, array_sum( array_map( static fn ( OrderItem $i ): int => $i->total, $lines ) ) );
		self::assertSame( 36, $this->line( $order, OrderItem::SHIPPING )->tax_total );

		$orders = Plugin::instance()->orders()->service();
		$orders->record_payment( $order->id, $order->total, TransitionContext::system() );
		$item     = $this->line( $order, OrderItem::PRODUCT );
		$shipping = $this->line( $order, OrderItem::SHIPPING );
		$after    = $orders->record_refund(
			$order->id,
			$order->total,
			[
				new RefundLine( $item->id, 2, $item->total + $item->tax_total ),
				new RefundLine( $shipping->id, 0, $shipping->total + $shipping->tax_total ),
			],
			'',
			TransitionContext::system()
		);
		$refunded = 0;
		foreach ( $after->items as $line ) {
			$refunded += array_sum( $line->refunded_taxes() );
			self::assertSame( $line->taxes(), $line->refunded_taxes() );
		}
		self::assertSame( 224, $refunded );
	}

	/** @return array{CheckoutService,string} */
	private function cart( int $qty ): array {
		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		$product  = $this->simple( 1000 );
		$browser->cart()->add( 0, $product, $this->service()->get( $product )->base->id, $qty );
		return [ $checkout, $checkout->form_state( 0 )['checkout_token'] ];
	}

	private function checkout( CartServices $browser ): CheckoutService {
		$plugin = Plugin::instance();
		return ( new CheckoutServices( $plugin->services(), $browser, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();
	}

	private function request( string $token, int $expected ): CheckoutRequest {
		return CheckoutRequest::from_array(
			[
				'checkout_token' => $token,
				'billing'        => self::BILLING,
				'shipping_rate'  => $this->rate_id,
				'payment_method' => 'cod',
				'expected_total' => $expected,
			],
			0,
			'203.0.113.9'
		);
	}

	private function line( Order $order, string $type ): OrderItem {
		foreach ( $order->items as $item ) {
			if ( $item->type === $type ) {
				return $item;
			}
		}
		self::fail( "No {$type} line." );
	}
}
