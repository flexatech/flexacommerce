<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Cart;

use Flexa\Commerce\Cart\Cart;
use Flexa\Commerce\Cart\CartLine;
use Flexa\Commerce\Tests\Unit\TestCase;

final class CartTest extends TestCase {
	private function line( int $variant, int $qty, array $attributes = [] ): CartLine {
		return new CartLine( CartLine::key_for( $variant, $attributes ), 1, $variant, $qty, $attributes );
	}

	public function test_line_key_ignores_attribute_order(): void {
		self::assertSame(
			CartLine::key_for(
				3,
				[
					'a' => 'x',
					'b' => 'y',
				]
			),
			CartLine::key_for(
				3,
				[
					'b' => 'y',
					'a' => 'x',
				]
			)
		);
		self::assertNotSame( CartLine::key_for( 3, [ 'a' => 'x' ] ), CartLine::key_for( 3, [ 'a' => 'z' ] ) );
		self::assertNotSame( CartLine::key_for( 3, [] ), CartLine::key_for( 4, [] ) );
	}

	public function test_adding_the_same_line_merges_quantities(): void {
		$cart = ( new Cart() )->with_added( $this->line( 3, 1 ) )->with_added( $this->line( 3, 2 ) )->with_added( $this->line( 3, 1, [ 'size' => 's' ] ) );
		self::assertCount( 2, $cart->lines );
		self::assertSame( 3, $cart->lines[0]->quantity );
		self::assertSame( 4, $cart->quantity_of( 3 ) );
		self::assertSame( 4, $cart->item_count() );
	}

	public function test_a_replaced_line_keeps_its_place_or_joins_its_twin(): void {
		$s    = $this->line( 3, 1, [ 'size' => 's' ] );
		$m    = $this->line( 3, 2, [ 'size' => 'm' ] );
		$cart = ( new Cart() )->with_added( $s )->with_added( $m );

		$l    = $this->line( 3, 4, [ 'size' => 'l' ] );
		$next = $cart->with_replaced( $s->key, $l );
		self::assertSame( [ $l->key, $m->key ], array_column( $next->lines, 'key' ) );
		self::assertSame( 4, $next->with_replaced( $l->key, $this->line( 3, 4, [ 'size' => 'l' ] ) )->lines[0]->quantity );

		$joined = $cart->with_replaced( $s->key, $this->line( 3, 5, [ 'size' => 'm' ] ) );
		self::assertSame( [ $m->key ], array_column( $joined->lines, 'key' ) );
		self::assertSame( 7, $joined->lines[0]->quantity );
	}

	public function test_quantity_zero_removes_the_line(): void {
		$line = $this->line( 3, 2 );
		$cart = ( new Cart() )->with_added( $line );
		self::assertSame( 5, $cart->with_quantity( $line->key, 5 )->item_count() );
		self::assertTrue( $cart->with_quantity( $line->key, 0 )->is_empty() );
		self::assertTrue( $cart->without( $line->key )->is_empty() );
	}

	public function test_data_round_trip_drops_bad_lines(): void {
		$cart            = ( new Cart() )->with_added(
			new CartLine(
				CartLine::key_for( 3, [ 'size' => 's' ] ),
				1,
				3,
				2,
				[ 'size' => 's' ],
				[
					[
						'name'  => 'Size',
						'value' => 'S',
					],
				]
			)
		)
			->with_notices(
				[
					[
						'code'    => 'x',
						'message' => 'Changed',
					],
				]
			);
		$data            = $cart->to_data();
		$data['lines'][] = [
			'variant_id' => 0,
			'qty'        => 1,
		];
		$data['lines'][] = [
			'variant_id' => 9,
			'qty'        => -1,
		];
		$data['lines'][] = 'junk';
		$back            = Cart::from_data( $data );
		self::assertCount( 1, $back->lines );
		self::assertSame( $cart->lines[0]->key, $back->lines[0]->key );
		self::assertSame(
			[
				[
					'name'  => 'Size',
					'value' => 'S',
				],
			],
			$back->lines[0]->labels
		);
		self::assertSame( 'Changed', $back->notices[0]['message'] );
	}

	public function test_shipping_choice_and_checkout_token_survive_a_round_trip(): void {
		$destination = [
			'country'  => 'US',
			'state'    => 'CA',
			'postcode' => '95014',
			'city'     => 'Cupertino',
		];
		$cart        = ( new Cart() )->with_added( $this->line( 3, 1 ) )->with_shipping( $destination, 'flat_rate:2' )->with_checkout_token( 'tok' );
		$back        = Cart::from_data( $cart->to_data() );

		self::assertSame( 'flat_rate:2', $back->shipping_rate );
		self::assertSame( $destination, $back->destination );
		self::assertSame( 'tok', $back->checkout_token );
		self::assertSame( Cart::VERSION, $cart->to_data()['v'] );
	}

	public function test_bad_shipping_data_falls_back_to_empty(): void {
		$cart = Cart::from_data(
			[
				'shipping_rate'  => 5,
				'destination'    => [
					'country' => [ 'US' ],
					'state'   => 'CA',
				],
				'checkout_token' => null,
			]
		);
		self::assertSame( '', $cart->shipping_rate );
		self::assertSame(
			[
				'country'  => '',
				'state'    => 'CA',
				'postcode' => '',
				'city'     => '',
			],
			$cart->destination
		);
		self::assertSame( '', $cart->checkout_token );
	}

	public function test_emptied_after_an_order_takes_a_new_token_and_keeps_the_destination(): void {
		$destination = [
			'country'  => 'VN',
			'state'    => '',
			'postcode' => '700000',
			'city'     => '',
		];
		$cart        = ( new Cart() )->with_added( $this->line( 3, 1 ) )
			->with_shipping( $destination, 'flat_rate:2' )
			->with_notices(
				[
					[
						'code'    => 'x',
						'message' => 'y',
					],
				]
			)
			->with_checkout_token( 'old' )
			->emptied( 'new' );

		self::assertTrue( $cart->is_empty() );
		self::assertSame( 'new', $cart->checkout_token );
		self::assertSame( $destination, $cart->destination );
		self::assertSame( '', $cart->shipping_rate, 'the rate is chosen again for the next cart' );
		self::assertSame( [], $cart->notices );
	}

	public function test_emptied_keeps_the_rate_window(): void {
		$cart = ( new Cart() )->with_added( $this->line( 3, 1 ) )->with_rate(
			[
				'window' => 10,
				'count'  => 4,
			]
		)->emptied();
		self::assertTrue( $cart->is_empty() );
		self::assertSame( 4, $cart->rate['count'] );
	}
}
