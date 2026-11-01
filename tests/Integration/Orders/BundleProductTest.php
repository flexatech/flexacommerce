<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Orders;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutServices;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Contracts\Exceptions\InvalidRefund;
use Flexa\Commerce\Coupons\RuleTargets;
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

/**
 * Bundle products (ADR-071): price and stock follow the items, the cart
 * counts each item's stock once for every line, and an order keeps the
 * items as lines under the bundle.
 */
final class BundleProductTest extends OrderTestCase {
	private string $rate_id = '';

	public function set_up(): void {
		parent::set_up();
		$gateways = Plugin::instance()->payments()->service()->gateways();
		$cod      = $gateways->get( 'cod' );
		self::assertNotNull( $cod );
		$gateways->settings()->save( $cod, [ 'enabled' => true ] );
		$zones         = ( new ZoneRepository() )->save( [ new Zone( 0, 'US', [ 'country:US' ], [], [ new MethodInstance( 0, 'flat_rate', true, [ 'cost' => 500 ] ) ] ) ] );
		$this->rate_id = 'flat_rate:' . $zones[0]->methods[0]->instance_id;
	}

	public function tear_down(): void {
		delete_option( GatewaySettings::PREFIX . 'cod' );
		delete_option( ZoneRepository::OPTION );
		parent::tear_down();
	}

	public function test_a_computed_price_and_the_stock_status_follow_the_items(): void {
		$tee             = $this->simple( 1000, 5 );
		[ $sized, $ids ] = $this->sized( 3 );
		$box             = $this->bundle(
			[
				[ $tee, 0, 2 ],
				[ $sized, $ids['m'], 1 ],
			],
			'discount',
			10
		);
		$saved           = $this->service()->get( $box );
		self::assertTrue( $saved->is_bundle() );
		self::assertSame( 4000, $saved->base->regular_price, 'Two tees and one M.' );
		self::assertSame( 3600, $saved->base->sale_price, '10% off the sum.' );
		self::assertFalse( $saved->base->manage_stock, 'A bundle keeps no stock.' );
		self::assertSame( [ $box ], Plugin::instance()->catalog()->products()->bundles_of( $tee ) );

		// An item's new price reaches the bundle.
		$this->save( $tee, [ 'base' => [ 'regular_price' => 1500 ] ] );
		$saved = $this->service()->get( $box );
		self::assertSame( 5000, $saved->base->regular_price );
		self::assertSame( 4500, $saved->base->sale_price );
		self::assertSame( '4500', get_post_meta( $box, ProductRepository::META_PRICE_MIN, true ) );

		// One item sold out sells the bundle out.
		$this->save(
			$sized,
			[
				'variations' => [
					[
						'id'             => $ids['m'],
						'stock_quantity' => 0,
					],
				],
			]
		);
		self::assertSame( Variant::OUTOFSTOCK, $this->service()->get( $box )->base->stock_status );
		$this->save(
			$sized,
			[
				'variations' => [
					[
						'id'             => $ids['m'],
						'stock_quantity' => 4,
					],
				],
			]
		);
		self::assertSame( Variant::INSTOCK, $this->service()->get( $box )->base->stock_status );

		// A deleted item stays listed, so the bundle cannot be sold until the merchant edits it.
		wp_delete_post( $sized, true );
		$saved = $this->service()->get( $box );
		self::assertCount( 2, $saved->bundle?->items ?? [] );
		self::assertSame( Variant::OUTOFSTOCK, $saved->base->stock_status );
	}

	public function test_a_bundle_refuses_items_it_cannot_sell(): void {
		$tee             = $this->simple();
		[ $sized, $ids ] = $this->sized();
		$any             = $this->variable_product( [ 'S' ] );
		$this->service()->generate_variants( $any );
		$wildcard = $this->save(
			$any,
			[
				'attributes' => [
					[
						'type'      => 'local',
						'name'      => 'Size',
						'values'    => [ 'S' ],
						'variation' => true,
					],
					[
						'type'      => 'local',
						'name'      => 'Fit',
						'values'    => [ 'Slim', 'Regular' ],
						'variation' => true,
					],
				],
			]
		)->variations[0]->id;
		$group    = $this->product();
		$this->save(
			$group,
			[
				'type'     => 'grouped',
				'children' => [ $tee ],
			]
		);
		$box = $this->bundle( [ [ $tee, 0, 1 ] ] );

		$cases = [
			'itself'          => [ [ 'product_id' => $box ] ],
			'a group'         => [ [ 'product_id' => $group ] ],
			'another bundle'  => [ [ 'product_id' => $box ] ],
			'no variation'    => [ [ 'product_id' => $sized ] ],
			'an Any value'    => [ [ 'variant_id' => $wildcard ] ],
			'the same twice'  => [ [ 'variant_id' => $ids['s'] ], [ 'variant_id' => $ids['s'] ] ],
			'unknown product' => [ [ 'product_id' => 999999 ] ],
		];
		$other = $this->product();
		foreach ( $cases as $case => $items ) {
			try {
				$this->save(
					$case === 'itself' ? $box : $other,
					[
						'type'   => 'bundle',
						'bundle' => [ 'items' => $items ],
					]
				);
				self::fail( 'Expected a refused item: ' . $case );
			} catch ( InvalidProduct $e ) {
				self::assertStringStartsWith( 'bundle.items.', $e->field, $case );
			}
		}

		// An item of a bundle cannot become a bundle itself.
		try {
			$this->save(
				$tee,
				[
					'type'   => 'bundle',
					'bundle' => [ 'items' => [ [ 'variant_id' => $ids['l'] ] ] ],
				]
			);
			self::fail( 'Expected a refused type.' );
		} catch ( InvalidProduct $e ) {
			self::assertSame( 'type', $e->field );
		}
	}

	public function test_the_cart_counts_each_item_once_across_lines(): void {
		$tee     = $this->simple( 1000, 5 );
		$mug     = $this->simple( 400 );
		$box     = $this->bundle(
			[
				[ $tee, 0, 2 ],
				[ $mug, 0, 1 ],
			]
		);
		$browser = $this->browser();
		$options = $browser->purchase_options()->for_product( $this->service()->get( $box ) );
		self::assertTrue( $options['in_stock'] );
		self::assertSame( 2, $options['variants'][0]['max'], 'Five tees make two bundles.' );

		$cart  = $browser->cart();
		$state = $cart->add( 0, $box, $this->service()->get( $box )->base->id, 2 );
		self::assertSame( 4800, $state->subtotal->amount );
		self::assertSame( [ $tee, $mug ], array_map( static fn ( $part ): int => $part->product->id, $state->items[0]->parts ) );

		// One tee is left: it sells on its own, a third bundle does not.
		$cart->add( 0, $tee, $this->service()->get( $tee )->base->id, 1 );
		try {
			$cart->add( 0, $box, $this->service()->get( $box )->base->id, 1 );
			self::fail( 'Expected too little stock.' );
		} catch ( InsufficientStock ) {
			self::assertSame( 3, $cart->state( 0 )->item_count() );
		}

		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $box . '"]' );
		self::assertStringContainsString( 'This bundle includes:', $html );
		self::assertStringContainsString( '2&nbsp;&times;', $html );
	}

	public function test_an_order_keeps_the_items_under_the_bundle_and_takes_their_stock(): void {
		$tee     = $this->simple( 1000, 5 );
		$mug     = $this->simple( 400, 4 );
		$box     = $this->bundle(
			[
				[ $tee, 0, 2 ],
				[ $mug, 0, 1 ],
			]
		);
		$browser = $this->browser();
		$browser->cart()->add( 0, $box, $this->service()->get( $box )->base->id, 2 );
		$order = $this->checkout_order( $browser, 4800 + 500 );

		$lines  = array_values( array_filter( $order->items, static fn ( OrderItem $i ): bool => $i->type === OrderItem::PRODUCT ) );
		$bundle = $lines[0];
		self::assertCount( 3, $lines );
		self::assertSame( $box, $bundle->product_id );
		self::assertFalse( $bundle->is_bundle_part() );
		self::assertSame( 2, $order->item_count(), 'The items are not counted twice.' );
		$parts = $order->parts_of( $bundle );
		self::assertSame( [ $tee, $mug ], array_map( static fn ( OrderItem $i ): ?int => $i->product_id, $parts ) );
		self::assertSame( [ 4, 2 ], array_map( static fn ( OrderItem $i ): int => $i->quantity, $parts ) );
		self::assertSame( [ 0, 0 ], array_map( static fn ( OrderItem $i ): int => $i->total, $parts ) );
		self::assertSame( 1, $this->stock_of( $tee ) );
		self::assertSame( 2, $this->stock_of( $mug ) );

		$orders = $this->orders();
		$orders->record_payment( $order->id, $order->total, TransitionContext::system() );

		// A part is refunded with its bundle, not on its own line.
		try {
			$orders->record_refund( $order->id, 100, [ new RefundLine( $parts[0]->id, 1, 100, true ) ], '', TransitionContext::system() );
			self::fail( 'Expected a refused part line.' );
		} catch ( InvalidRefund $e ) {
			self::assertStringContainsString( 'bundle', $e->getMessage() );
		}

		$after = $orders->record_refund( $order->id, 2400, [ new RefundLine( $bundle->id, 1, 2400, true ) ], '', TransitionContext::system() );
		self::assertSame( 3, $this->stock_of( $tee ), 'One bundle back puts two tees back.' );
		self::assertSame( 3, $this->stock_of( $mug ) );
		self::assertSame( [ 2, 1 ], array_map( static fn ( OrderItem $i ): int => $i->restocked_qty, $after->parts_of( $after->item( $bundle->id ) ?? $bundle ) ) );
	}

	public function test_the_item_search_offers_simple_products_and_single_variations(): void {
		$tee             = $this->simple();
		[ $sized, $ids ] = $this->sized();
		$catalog         = Plugin::instance()->catalog();
		$targets         = new RuleTargets( $catalog->products(), $catalog->variants() );
		update_post_meta( $tee, '_fxc_search_probe', '1' );
		$this->save( $tee, [ 'base' => [ 'sku' => $this->sku( 'PROBE' ) ] ] );
		$this->save( $sized, [ 'base' => [ 'sku' => $this->sku( 'SIZED' ) ] ] );

		$found = $targets->search_parts( $this->sku( 'PROBE' ) );
		self::assertSame( [ $tee ], array_column( $found, 'product_id' ) );
		$found = $targets->search_parts( $this->sku( 'SIZED' ) );
		self::assertEqualsCanonicalizing( array_values( $ids ), array_column( $found, 'id' ) );
	}

	/**
	 * A published bundle of `[product id, variant id or 0, quantity]` items.
	 *
	 * @param list<array{int,int,int}> $items
	 */
	private function bundle( array $items, string $mode = 'sum', int $discount = 0 ): int {
		$id = $this->product( 'Bundle ' . $this->run );
		$this->save(
			$id,
			[
				'type'   => 'bundle',
				'bundle' => [
					'mode'     => $mode,
					'discount' => $discount,
					'items'    => array_map(
						static fn ( array $item ): array => [
							'product_id' => $item[0],
							'variant_id' => $item[1],
							'quantity'   => $item[2],
						],
						$items
					),
				],
			]
		);
		return $id;
	}

	private function checkout_order( CartServices $browser, int $total ): Order {
		$plugin   = Plugin::instance();
		$checkout = ( new CheckoutServices( $plugin->services(), $browser, new ShippingServices( $plugin->services() ), $plugin->orders(), $plugin->payments() ) )->service();
		$form     = $checkout->form_state( 0 );
		self::assertSame( $total, $form['totals']['total']['amount'] );
		return $checkout->place_order(
			CheckoutRequest::from_array(
				[
					'checkout_token' => $form['checkout_token'],
					'billing'        => [
						'first_name' => 'Ada',
						'last_name'  => 'Lovelace',
						'address_1'  => '1 Infinite Loop',
						'city'       => 'Cupertino',
						'state'      => 'CA',
						'postcode'   => '95014',
						'country'    => 'US',
						'email'      => 'ada@example.com',
					],
					'shipping_rate'  => $this->rate_id,
					'payment_method' => 'cod',
					'expected_total' => $total,
				],
				0,
				'203.0.113.9'
			)
		)->order;
	}
}
