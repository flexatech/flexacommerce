<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;

/** Reverse charge for EU business buyers (ADR-086), store in Berlin. */
final class EuVatCheckoutTest extends TaxTestCase {
	private const BILLING = [
		'first_name' => 'Marie',
		'last_name'  => 'Curie',
		'company'    => 'ACME SARL',
		'address_1'  => '1 rue de Paris',
		'city'       => 'Paris',
		'postcode'   => '75001',
		'country'    => 'FR',
		'email'      => 'marie@example.com',
	];

	private string $rate_id = '';

	public function set_up(): void {
		parent::set_up();
		$gateways = Plugin::instance()->payments()->service()->gateways();
		$cod      = $gateways->get( 'cod' );
		self::assertNotNull( $cod );
		$gateways->settings()->save( $cod, [ 'enabled' => true ] );
		$zones         = ( new ZoneRepository() )->save(
			[ new Zone( 0, 'EU', [ 'country:FR', 'country:DE' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ]
		);
		$this->rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
		$this->enable(
			[
				'store_country'  => 'DE',
				'store_state'    => '',
				'store_postcode' => '10115',
				'store_city'     => 'Berlin',
				'vat_numbers'    => 'optional',
			]
		);
		$this->rate(
			[
				'country' => 'DE',
				'rate'    => '19',
				'name'    => 'VAT',
			]
		);
		$this->rate(
			[
				'country' => 'FR',
				'rate'    => '20',
				'name'    => 'VAT',
			]
		);
		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ): mixed {
				unset( $args );
				if ( ! str_contains( $url, '/vies/rest-api/' ) ) {
					return $pre;
				}
				return [
					'headers'  => [],
					'body'     => (string) wp_json_encode(
						str_contains( $url, '40303265045' ) || str_contains( $url, '123456789' ) ? [
							'isValid' => true,
							'name'    => 'ACME',
						] : [
							'isValid'   => false,
							'userError' => 'INVALID',
						]
					),
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		global $wpdb;
		remove_all_filters( 'pre_http_request' );
		delete_option( GatewaySettings::PREFIX . 'cod' );
		delete_option( ZoneRepository::OPTION );
		parent::tear_down();
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fxcm\\_vat\\_%' OR option_name LIKE '\\_transient\\_%fxcm\\_rl\\_%'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();
	}

	public function test_a_valid_number_from_another_member_state_pays_no_vat(): void {
		[ $checkout, $token ] = $this->cart();
		$paris                = new Destination( 'FR', '', '75001', 'Paris' );

		$view = $checkout->update_shipping( 0, $paris, $this->rate_id, '', '', 'FR' );
		self::assertSame( 3000, $view['totals']['total']['amount'], '2000 + 500 + 20 % VAT' );
		self::assertNull( $view['vat'] );

		$view = $checkout->update_shipping( 0, $paris, $this->rate_id, '', 'FR 40 303265045', 'FR' );
		self::assertSame( 2500, $view['totals']['total']['amount'] );
		self::assertTrue( $view['vat']['reverse_charge'] );
		self::assertFalse( $view['vat']['error'] );
		self::assertSame( 'FR40303265045', $checkout->form_state( 0 )['vat_number'], 'the cart keeps the number' );
		self::assertSame( 2500, $checkout->form_state( 0 )['totals']['total']['amount'] );

		$order = $checkout->place_order( $this->request( $token, 2500, 'FR40303265045' ) )->order;
		self::assertSame( 0, $order->tax_total );
		self::assertSame( 2500, $order->total );
		$meta = Plugin::instance()->orders()->orders();
		self::assertSame( '1', $meta->get_meta( $order->id, '_fxcm_reverse_charge' ) );
		self::assertSame( 'FR40303265045', $meta->get_meta( $order->id, '_billing_vat_number' ) );
		$check = json_decode( (string) $meta->get_meta( $order->id, '_fxcm_vat_check' ), true );
		self::assertSame( [ 'valid', 'ACME' ], [ $check['status'] ?? '', $check['name'] ?? '' ] );
	}

	public function test_clearing_the_number_charges_vat_again(): void {
		[ $checkout ] = $this->cart();
		$paris        = new Destination( 'FR', '', '75001', 'Paris' );
		$checkout->update_shipping( 0, $paris, $this->rate_id, '', 'FR40303265045', 'FR' );
		$view = $checkout->update_shipping( 0, $paris, $this->rate_id, '', '', 'FR' );
		self::assertSame( 3000, $view['totals']['total']['amount'] );
		self::assertSame( '', $checkout->form_state( 0 )['vat_number'] );
	}

	public function test_a_number_in_the_store_country_still_pays_vat(): void {
		[ $checkout, $token ] = $this->cart();
		$berlin               = new Destination( 'DE', '', '10115', 'Berlin' );
		$view                 = $checkout->update_shipping( 0, $berlin, $this->rate_id, '', 'DE123456789', 'DE' );
		self::assertSame( 2975, $view['totals']['total']['amount'] );
		self::assertFalse( $view['vat']['reverse_charge'] );

		$billing = [
			'country'   => 'DE',
			'city'      => 'Berlin',
			'postcode'  => '10115',
			'address_1' => 'Unter den Linden 1',
		] + self::BILLING;
		$order   = $checkout->place_order( $this->request( $token, 2975, 'DE123456789', $billing ) )->order;
		self::assertSame( 475, $order->tax_total );
		self::assertNull( Plugin::instance()->orders()->orders()->get_meta( $order->id, '_fxcm_reverse_charge' ) );
	}

	public function test_an_unregistered_number_stops_the_order(): void {
		[ $checkout, $token ] = $this->cart();
		$view                 = $checkout->update_shipping( 0, new Destination( 'FR', '', '75001', 'Paris' ), $this->rate_id, '', 'FR11111111111', 'FR' );
		self::assertTrue( $view['vat']['error'] );
		self::assertSame( 3000, $view['totals']['total']['amount'] );
		try {
			$checkout->place_order( $this->request( $token, 3000, 'FR11111111111' ) );
			self::fail( 'The order was placed.' );
		} catch ( InvalidCheckout $e ) {
			self::assertArrayHasKey( 'billing.vat_number', $e->errors );
		}
	}

	/** @return array{CheckoutService,string} */
	private function cart(): array {
		$browser  = $this->browser();
		$plugin   = Plugin::instance();
		$checkout = ( new CheckoutServices( $plugin->services(), $browser, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();
		$product  = $this->simple( 1000 );
		$browser->cart()->add( 0, $product, $this->service()->get( $product )->base->id, 2 );
		return [ $checkout, $checkout->form_state( 0 )['checkout_token'] ];
	}

	/** @param array<string,string> $billing */
	private function request( string $token, int $expected, string $vat, array $billing = self::BILLING ): CheckoutRequest {
		return CheckoutRequest::from_array(
			[
				'checkout_token' => $token,
				'billing'        => [ 'vat_number' => $vat ] + $billing,
				'shipping_rate'  => $this->rate_id,
				'payment_method' => 'cod',
				'expected_total' => $expected,
			],
			0,
			'203.0.113.9'
		);
	}
}
