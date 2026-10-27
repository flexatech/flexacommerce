<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Orders\CustomerHistory;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponRepository;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Coupons\CouponServices;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Shipping\MethodInstance;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Tests\Integration\Tax\TaxTestCase;

/**
 * Carts and checkouts with the coupon service wired in, a flat US rate of
 * 500 and cash on delivery. Checkout commits, so the coupon tables are
 * emptied in tear_down.
 */
abstract class CouponTestCase extends TaxTestCase {
	protected const BILLING = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Infinite Loop',
		'city'       => 'Cupertino',
		'state'      => 'CA',
		'postcode'   => '95014',
		'country'    => 'US',
		'email'      => 'ada@example.com',
	];

	protected string $rate_id = '';

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
		global $wpdb;
		delete_option( GatewaySettings::PREFIX . 'cod' );
		delete_option( ZoneRepository::OPTION );
		parent::tear_down();
		foreach ( [ 'coupon_uses', 'coupons' ] as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
		}
		update_option( CouponRepository::AUTOMATIC_OPTION, 0, true );
		// The plugin's coupon services cache coupons for the request; a server render test fills them.
		( fn () => $this->coupons = null )->call( Plugin::instance() );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	/** Fresh services each time, as a new request would have: the repository caches coupons by code. */
	protected function coupons(): CouponServices {
		return new CouponServices( Plugin::instance()->services(), Plugin::instance()->cart()->pricing() );
	}

	/** @param array<string,mixed> $input */
	protected function coupon( string $code, string $type, int $amount, array $input = [] ): Coupon {
		return $this->coupons()->editor()->save(
			$input + [
				'code'   => $code,
				'type'   => $type,
				'amount' => $amount,
			]
		);
	}

	protected function shopper( ?MemoryTokenStore $tokens = null ): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			$tokens ?? new MemoryTokenStore(),
			coupons: fn (): CouponService => $this->coupons()->service(),
			history: fn (): CustomerHistory => $plugin->customer_history(),
		);
	}

	protected function checkout( CartServices $browser ): CheckoutService {
		$plugin = Plugin::instance();
		return ( new CheckoutServices(
			$plugin->services(),
			$browser,
			new ShippingServices( $plugin->services() ),
			$plugin->orders(),
			$plugin->payments(),
			null,
			fn (): CouponService => $this->coupons()->service(),
		) )->service();
	}

	protected function add( CartServices $browser, int $product_id, int $qty, int $user_id = 0 ): void {
		$browser->cart()->add( $user_id, $product_id, $this->service()->get( $product_id )->base->id, $qty );
	}

	/** @param array<string,mixed> $overrides */
	protected function request( string $token, array $overrides = [], int $user_id = 0 ): CheckoutRequest {
		return CheckoutRequest::from_array(
			$overrides + [
				'checkout_token' => $token,
				'billing'        => self::BILLING,
				'shipping_rate'  => $this->rate_id,
				'payment_method' => 'cod',
			],
			$user_id,
			'203.0.113.9'
		);
	}

	/** @return list<OrderItem> */
	protected function lines( Order $order, string $type ): array {
		return array_values( array_filter( $order->items, static fn ( OrderItem $i ): bool => $i->type === $type ) );
	}

	protected function uses( int $coupon_id ): int {
		return (int) $this->coupons()->repository()->find( $coupon_id )?->usage_count;
	}
}
