<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;

/**
 * Golden parity (doc 02 §7.12.9): every case in tests/fixtures/tax-golden.json
 * was priced by a real WooCommerce checkout (`tools/bench/bench.sh tax-golden`).
 * Here the same cart goes through CheckoutService::place_order() and the
 * stored order must match, rate by rate and line by line.
 */
final class TaxGoldenTest extends TaxTestCase {
	/**
	 * Cases where FlexaCommerce differs from WooCommerce on purpose (listed in
	 * doc 02 §7.12.9), with the totals FlexaCommerce gives instead.
	 */
	private const INTENDED = [
		// WooCommerce matches "95014-1234" only against wildcards such as 950*;
		// FlexaCommerce reads the first five digits of a US ZIP+4, so the city
		// and district rates apply like they do for "95014".
		'zip-plus-four' => [
			'total'     => 2724,
			'tax_total' => 224,
		],
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
		remove_all_filters( 'flexacommerce.tax.exempt' );
		delete_option( GatewaySettings::PREFIX . 'cod' );
		delete_option( ZoneRepository::OPTION );
		parent::tear_down();
	}

	/** @return array<string,array{array<string,mixed>}> */
	public static function cases(): array {
		$doc = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/tax-golden.json' ), true, 512, JSON_THROW_ON_ERROR );
		$out = [];
		foreach ( $doc['cases'] as $case ) {
			$out[ $case['id'] ] = [ $case ];
		}
		return $out;
	}

	/**
	 * @dataProvider cases
	 * @param array<string,mixed> $case
	 */
	public function test_the_order_matches_woocommerce( array $case ): void {
		self::assertIsArray( $case['expected'], 'Run tools/bench/bench.sh tax-golden to price this case.' );
		$this->enable( $case['settings'] );
		$ids = [];
		foreach ( $case['rates'] as $index => $rate ) {
			$ids[ $this->rate( $rate )->id ] = $index;
		}
		if ( $case['exempt'] ) {
			add_filter( 'flexacommerce.tax.exempt', '__return_true' );
		}

		$browser  = $this->browser();
		$checkout = $this->checkout( $browser );
		foreach ( $case['lines'] as $line ) {
			$product = $this->product();
			$this->save(
				$product,
				[
					'base' => [
						'regular_price' => $line['price'],
						'tax_class'     => $line['tax_class'],
						'tax_status'    => $line['tax_status'],
					],
				]
			);
			$browser->cart()->add( 0, $product, $this->service()->get( $product )->base->id, $line['qty'] );
		}
		$ship = $case['address'];
		$checkout->update_shipping( 0, new Destination( $ship['country'], $ship['state'], $ship['postcode'], $ship['city'] ), $this->rate_id );
		$form = $checkout->form_state( 0 );

		// The form is quoted for the shipping address, so with taxes on the
		// billing address its total is not the order's.
		$expected = ( $case['settings']['tax_based_on'] ?? 'shipping' ) === 'billing' ? null : $form['totals']['total']['amount'];
		$order    = $checkout->place_order( $this->request( $form['checkout_token'], $expected, $case ) )->order;

		$rates    = array_fill( 0, count( $case['rates'] ), null );
		$lines    = [];
		$shipping = 0;
		foreach ( $order->items as $item ) {
			if ( $item->type === OrderItem::TAX ) {
				$rates[ $ids[ (int) $item->meta['rate_id'] ] ] = [
					'items'    => (int) $item->meta['items_tax'],
					'shipping' => (int) $item->meta['shipping_tax'],
				];
			} elseif ( $item->type === OrderItem::PRODUCT ) {
				$lines[] = [
					'net' => $item->total,
					'tax' => $item->tax_total,
				];
			} elseif ( $item->type === OrderItem::SHIPPING ) {
				$shipping = $item->tax_total;
			}
		}
		$actual = [
			'total'        => $order->total,
			'tax_total'    => $order->tax_total,
			'shipping'     => $order->shipping_total,
			'shipping_tax' => $shipping,
			'rates'        => $rates,
			'lines'        => $lines,
		];
		if ( $case['exempt'] ) {
			self::assertSame( '1', Plugin::instance()->orders()->orders()->get_meta( $order->id, '_fxc_tax_exempt' ) );
		}
		if ( isset( self::INTENDED[ $case['id'] ] ) ) {
			self::assertNotSame( $case['expected'], $actual, 'WooCommerce now agrees; drop the case from INTENDED.' );
			self::assertSame( self::INTENDED[ $case['id'] ], array_intersect_key( $actual, self::INTENDED[ $case['id'] ] ) );
			return;
		}
		self::assertSame( $case['expected'], $actual );
	}

	private function checkout( CartServices $browser ): CheckoutService {
		$plugin = Plugin::instance();
		return ( new CheckoutServices( $plugin->services(), $browser, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();
	}

	/** @param array<string,mixed> $case */
	private function request( string $token, ?int $expected, array $case ): CheckoutRequest {
		$person = [
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'address_1'  => '1 Infinite Loop',
			'email'      => 'ada@example.com',
		];
		$ship   = $case['address'];
		$bill   = $case['billing'] ?? $ship;
		return CheckoutRequest::from_array(
			[
				'checkout_token'            => $token,
				'billing'                   => $person + $bill,
				'shipping'                  => $person + $ship,
				'ship_to_different_address' => isset( $case['billing'] ),
				'shipping_rate'             => $this->rate_id,
				'payment_method'            => 'cod',
				'expected_total'            => $expected,
			],
			0,
			'203.0.113.9'
		);
	}
}
