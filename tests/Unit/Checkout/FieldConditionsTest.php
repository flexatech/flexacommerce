<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Checkout;

use Flexa\Commerce\Checkout\CartFacts;
use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutValidator;
use Flexa\Commerce\Checkout\FieldSchema;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckoutLayout;
use Flexa\Commerce\Tests\Unit\TestCase;

/** Fields of the merchant's shown only for some countries or carts (doc 11 §11). */
final class FieldConditionsTest extends TestCase {
	private const BILLING = [
		'email'      => 'ada@example.com',
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Infinite Loop',
		'city'       => 'Cupertino',
		'state'      => 'CA',
		'postcode'   => '95014',
		'country'    => 'US',
	];

	private const GERMANY = [
		'country'  => 'DE',
		'state'    => '',
		'postcode' => '10115',
		'city'     => 'Berlin',
	];

	public function test_conditions_are_cleaned_and_empty_means_always(): void {
		$layout = CheckoutLayout::from_array(
			[
				'fields' => [
					'billing' => [
						[
							'id'    => 'vat',
							'type'  => 'text',
							'label' => 'VAT number',
							'when'  => [
								'countries'  => [ 'de', 'FR', 'DE', 'XX' ],
								'shipping'   => 'sometimes',
								'products'   => [ '12', 12, -3, 'x' ],
								'categories' => [ 7 ],
							],
						],
						[
							'id'    => 'po',
							'type'  => 'text',
							'label' => 'PO number',
						],
					],
				],
			]
		);
		self::assertSame(
			[
				'countries'  => [ 'DE', 'FR' ],
				'shipping'   => 'any',
				'products'   => [ 12 ],
				'categories' => [ 7 ],
			],
			$layout->entry( 'billing', 'vat' )['when'] ?? null
		);
		self::assertSame(
			[
				'countries'  => [],
				'shipping'   => 'any',
				'products'   => [],
				'categories' => [],
			],
			$layout->entry( 'billing', 'po' )['when'] ?? null
		);
		self::assertTrue( $layout->names_categories() );
		self::assertArrayNotHasKey( 'when', $layout->entry( 'billing', 'phone' ) ?? [], 'core fields have no conditions' );
	}

	/** @dataProvider broken_conditions */
	public function test_the_builder_gets_the_condition_that_broke_a_rule( array $when, string $field ): void {
		try {
			CheckoutLayout::save(
				[
					'fields' => [
						'billing' => [
							[
								'id'    => 'vat',
								'type'  => 'text',
								'label' => 'VAT number',
								'when'  => $when,
							],
						],
					],
				]
			);
			self::fail( 'saved' );
		} catch ( InvalidCheckoutLayout $e ) {
			self::assertSame( $field, $e->field );
		}
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public static function broken_conditions(): array {
		return [
			'unknown country' => [ [ 'countries' => [ 'DE', 'ZZ' ] ], 'billing.0.when.countries' ],
			'bad shipping'    => [ [ 'shipping' => 'often' ], 'billing.0.when.shipping' ],
			'too many'        => [ [ 'products' => range( 1, CheckoutLayout::MAX_TARGETS + 1 ) ], 'billing.0.when.products' ],
		];
	}

	public function test_the_cart_side_of_a_condition(): void {
		$when = static fn ( array $when ): array => $when + [
			'countries'  => [],
			'shipping'   => 'any',
			'products'   => [],
			'categories' => [],
		];
		$cart = new CartFacts( true, [ 5, 9 ], [ 3, 1 ] );
		self::assertTrue( $cart->allows( $when( [] ) ) );
		self::assertTrue( $cart->allows( $when( [ 'shipping' => 'ships' ] ) ) );
		self::assertFalse( $cart->allows( $when( [ 'shipping' => 'no_shipping' ] ) ) );
		self::assertTrue( ( new CartFacts( false ) )->allows( $when( [ 'shipping' => 'no_shipping' ] ) ) );
		self::assertTrue( $cart->allows( $when( [ 'products' => [ 9 ] ] ) ) );
		self::assertTrue(
			$cart->allows(
				$when(
					[
						'products'   => [ 40 ],
						'categories' => [ 1 ],
					]
				)
			),
			'a product or a category'
		);
		self::assertFalse(
			$cart->allows(
				$when(
					[
						'products'   => [ 40 ],
						'categories' => [ 2 ],
					]
				)
			)
		);
		self::assertFalse(
			$cart->allows(
				$when(
					[
						'shipping' => 'no_shipping',
						'products' => [ 5 ],
					]
				)
			),
			'shipping and the products together'
		);
		self::assertEquals( $cart, CartFacts::from_array( $cart->to_array() ) );
	}

	public function test_a_field_the_cart_does_not_meet_is_left_out(): void {
		$this->save_gift_fields();
		$ids = static fn ( ?CartFacts $cart ): array => array_column( FieldSchema::additional( $cart )->to_array(), 'id' );
		self::assertSame( [ 'gift', 'door', 'customer_note' ], $ids( null ), 'with no cart, as on the account page, every field is there' );
		self::assertSame( [ 'door', 'customer_note' ], $ids( new CartFacts( true, [ 3 ] ) ) );
		self::assertSame( [ 'gift', 'customer_note' ], $ids( new CartFacts( false, [ 8 ] ) ) );
	}

	public function test_a_field_for_other_countries_is_not_asked_for_or_kept(): void {
		CheckoutLayout::save(
			[
				'fields' => [
					'billing'    => [
						[
							'id'     => 'vat',
							'type'   => 'text',
							'label'  => 'VAT number',
							'answer' => 'required',
							'when'   => [ 'countries' => [ 'DE' ] ],
						],
					],
					'additional' => [
						[
							'id'     => 'customs',
							'type'   => 'text',
							'label'  => 'Customs number',
							'answer' => 'required',
							'when'   => [ 'countries' => [ 'US' ] ],
						],
					],
				],
			]
		);
		$validator = new CheckoutValidator();
		$facts     = new CartFacts( true );

		$schema = FieldSchema::for_address( 'billing', 'US', $facts );
		self::assertSame( [ 'DE' ], $schema->field( 'vat' )?->countries, 'kept in the schema so the page can show it when the country changes' );
		self::assertFalse( $schema->field( 'vat' )->shown_in( 'US' ) );

		$us = $validator->addresses( $this->request( [ 'billing' => [ 'vat' => 'DE1' ] + self::BILLING ] ), true, $facts );
		self::assertSame( [ 'additional.customs' ], array_keys( $us['errors'] ) );
		self::assertArrayNotHasKey( '_billing_vat', $us['meta'], 'an answer posted for a hidden field is dropped' );

		$de = $validator->addresses( $this->request( [ 'billing' => self::GERMANY + self::BILLING ] ), true, $facts );
		self::assertSame( [ 'billing.vat' ], array_keys( $de['errors'] ) );

		$elsewhere = $validator->addresses(
			$this->request(
				[
					'billing'                   => [ 'vat' => 'DE1' ] + self::GERMANY + self::BILLING,
					'ship_to_different_address' => '1',
					'shipping'                  => self::BILLING,
				]
			),
			true,
			$facts
		);
		self::assertSame( [ 'additional.customs' ], array_keys( $elsewhere['errors'] ), 'additional fields follow the country the order ships to' );
		self::assertSame( 'DE1', $elsewhere['meta']['_billing_vat'] );
	}

	public function test_the_validator_skips_a_field_the_cart_does_not_meet(): void {
		$this->save_gift_fields( 'required' );
		$result = ( new CheckoutValidator() )->addresses( $this->request( [ 'billing' => self::BILLING ] ), true, new CartFacts( true, [ 3 ] ) );
		self::assertSame( [ 'additional.door' ], array_keys( $result['errors'] ), 'the gift field is for product 8 only' );
	}

	private function save_gift_fields( string $answer = 'optional' ): void {
		CheckoutLayout::save(
			[
				'fields' => [
					'additional' => [
						[
							'id'     => 'gift',
							'type'   => 'text',
							'label'  => 'Gift message',
							'answer' => $answer,
							'when'   => [ 'products' => [ 8 ] ],
						],
						[
							'id'     => 'door',
							'type'   => 'text',
							'label'  => 'Door code',
							'answer' => $answer,
							'when'   => [ 'shipping' => 'ships' ],
						],
					],
				],
			]
		);
		self::assertFalse( CheckoutLayout::current()->names_categories(), 'no term query for this layout' );
	}

	/** @param array<string,mixed> $data */
	private function request( array $data ): CheckoutRequest {
		return CheckoutRequest::from_array( $data + [ 'checkout_token' => 't' ], 0 );
	}
}
