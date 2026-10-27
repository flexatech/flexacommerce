<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Checkout;

use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutValidator;
use Flexa\Commerce\Checkout\FieldSchema;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckoutLayout;
use Flexa\Commerce\Orders\OrderFields;
use Flexa\Commerce\Tests\Unit\TestCase;

/** The fields as the merchant arranged them in the checkout builder (ADR-031). */
final class CheckoutLayoutTest extends TestCase {
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

	public function test_the_default_is_the_page_of_before(): void {
		$billing = FieldSchema::for_address( 'billing', 'US' );
		self::assertSame( [ 'email', 'first_name', 'last_name', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' ], array_column( $billing->to_array(), 'id' ), 'company is hidden' );
		self::assertSame( [ 5, 10, 20 ], array_map( static fn ( $f ): int => $f->priority, array_slice( $billing->fields(), 0, 3 ) ), 'the priorities extensions rely on' );
		self::assertSame( 'half', $billing->field( 'first_name' )?->width );
		self::assertSame( 'full', $billing->field( 'email' )?->width );
		self::assertFalse( $billing->field( 'phone' )?->required );
		self::assertSame( 'Order notes (optional)', FieldSchema::additional()->field( 'customer_note' )?->label );
		self::assertTrue( CheckoutLayout::default()->is_default() );
	}

	public function test_a_damaged_option_falls_back_field_by_field(): void {
		$layout = CheckoutLayout::from_array(
			[
				'fields' => [
					'billing' => [
						[
							'id'    => 'phone',
							'label' => 'Mobile',
						],
						'junk',
						[
							'id'     => 'country',
							'answer' => 'hidden',
						],
						[
							'id'     => 'postcode',
							'answer' => 'optional',
						],
						[
							'id'   => 'email',
							'type' => 'text',
						],
						[
							'id'    => 'phone',
							'label' => 'Twice',
						],
					],
				],
			]
		);
		$ids    = array_column( $layout->section( 'billing' ), 'id' );
		self::assertSame( [ 'phone', 'country', 'postcode', 'email', 'first_name' ], array_slice( $ids, 0, 5 ), 'the rest of the core fields follow in their default order' );
		self::assertCount( 11, $ids );
		self::assertSame( 'Mobile', $layout->entry( 'billing', 'phone' )['label'] ?? '' );
		self::assertSame( 'required', $layout->entry( 'billing', 'country' )['answer'] ?? '', 'country cannot be hidden' );
		self::assertSame( 'required', $layout->entry( 'billing', 'postcode' )['answer'] ?? '', 'postcode follows the country' );
	}

	/** @dataProvider broken_layouts */
	public function test_the_builder_gets_the_field_that_broke_a_rule( array $entry, string $field ): void {
		try {
			CheckoutLayout::save( [ 'fields' => [ 'billing' => [ [ 'id' => 'first_name' ], $entry ] ] ] );
			self::fail( 'saved' );
		} catch ( InvalidCheckoutLayout $e ) {
			self::assertSame( $field, $e->field );
		}
		self::assertArrayNotHasKey( CheckoutLayout::OPTION, \WP_Test_State::$options );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public static function broken_layouts(): array {
		return [
			'email hidden'      => [
				[
					'id'     => 'email',
					'answer' => 'hidden',
				],
				'billing.1.answer',
			],
			'twice'             => [ [ 'id' => 'first_name' ], 'billing.1.id' ],
			'a core id'         => [
				[
					'id'    => 'customer_note',
					'type'  => 'text',
					'label' => 'Note',
				],
				'billing.1.id',
			],
			'no label'          => [
				[
					'id'   => 'gift',
					'type' => 'text',
				],
				'billing.1.label',
			],
			'unknown type'      => [
				[
					'id'    => 'gift',
					'type'  => 'file',
					'label' => 'Gift',
				],
				'billing.1.type',
			],
			'no choices'        => [
				[
					'id'    => 'size',
					'type'  => 'select',
					'label' => 'Size',
				],
				'billing.1.options',
			],
			'same choice twice' => [
				[
					'id'      => 'size',
					'type'    => 'radio',
					'label'   => 'Size',
					'options' => [
						[
							'value' => 's',
							'label' => 'Small',
						],
						[
							'value' => 's',
							'label' => 'Short',
						],
					],
				],
				'billing.1.options.1.value',
			],
			'too long'          => [
				[
					'id'    => 'last_name',
					'label' => str_repeat( 'x', 81 ),
				],
				'billing.1.label',
			],
		];
	}

	public function test_the_schema_follows_the_layout(): void {
		CheckoutLayout::save(
			[
				'fields' => [
					'billing' => [
						[ 'id' => 'email' ],
						[
							'id'     => 'phone',
							'answer' => 'required',
							'width'  => 'half',
							'help'   => 'For the courier',
						],
						[
							'id'          => 'vat',
							'type'        => 'text',
							'label'       => 'VAT number',
							'placeholder' => 'DE123',
						],
						[
							'id'     => 'address_2',
							'answer' => 'required',
						],
					],
				],
			]
		);
		$fields = FieldSchema::for_address( 'billing', 'US' );
		self::assertSame( [ 'email', 'phone', 'vat', 'address_2', 'first_name' ], array_slice( array_column( $fields->to_array(), 'id' ), 0, 5 ) );
		self::assertTrue( $fields->field( 'phone' )?->required );
		self::assertSame( [ 'half', 'For the courier' ], [ $fields->field( 'phone' )->width, $fields->field( 'phone' )->help ] );
		self::assertSame( 'DE123', $fields->field( 'vat' )?->placeholder );
		self::assertSame( 'Apartment, suite, unit', $fields->field( 'address_2' )?->label, 'no "(optional)" on a required field' );
		self::assertNull( $fields->core()->field( 'vat' ), 'the account page keeps the address columns' );
	}

	public function test_custom_answers_go_to_meta_with_their_label(): void {
		CheckoutLayout::save(
			[
				'fields' => [
					'billing'    => [
						[
							'id'      => 'heard',
							'type'    => 'select',
							'label'   => 'How did you hear about us?',
							'options' => [
								[
									'value' => 'friend',
									'label' => 'A friend',
								],
							],
							'email'   => false,
						],
					],
					'additional' => [
						[
							'id'     => 'gift',
							'type'   => 'textarea',
							'label'  => 'Gift message',
							'answer' => 'required',
						],
						[
							'id'     => 'customer_note',
							'answer' => 'hidden',
						],
						[
							'id'    => 'wrap',
							'type'  => 'checkbox',
							'label' => 'Gift wrap',
						],
					],
				],
			]
		);
		$validator = new CheckoutValidator();

		$result = $validator->addresses( $this->request( [ 'billing' => self::BILLING ] ), true );
		self::assertSame( [ 'additional.gift' ], array_keys( $result['errors'] ) );

		$request = $this->request(
			[
				'billing'       => [ 'heard' => 'friend' ] + self::BILLING,
				'additional'    => [
					'gift' => "Happy birthday\nLove, Ada",
					'wrap' => 'on',
				],
				'customer_note' => 'Leave it at the door',
			]
		);
		$result  = $validator->addresses( $request, true );
		self::assertSame( [], $result['errors'] );
		self::assertSame( 'friend', $result['meta']['_billing_heard'] );
		self::assertSame( "Happy birthday\nLove, Ada", $result['meta']['_additional_gift'] );
		self::assertSame(
			[
				[
					'key'   => '_billing_heard',
					'label' => 'How did you hear about us?',
					'value' => 'A friend',
					'email' => false,
				],
				[
					'key'   => '_additional_gift',
					'label' => 'Gift message',
					'value' => "Happy birthday\nLove, Ada",
					'email' => true,
				],
				[
					'key'   => '_additional_wrap',
					'label' => 'Gift wrap',
					'value' => 'Yes',
					'email' => true,
				],
			],
			OrderFields::parse( $result['meta'][ OrderFields::META ] )
		);
		self::assertSame( '', $validator->note( $request ), 'the notes are hidden' );

		$bad = $validator->addresses( $this->request( [ 'billing' => [ 'heard' => 'tv' ] + self::BILLING ] + [ 'additional' => [ 'gift' => 'Hi' ] ] ), true );
		self::assertSame( [ 'billing.heard' ], array_keys( $bad['errors'] ), 'only listed choices' );
	}

	public function test_a_required_note_and_numbers(): void {
		CheckoutLayout::save(
			[
				'fields' => [
					'additional' => [
						[
							'id'     => 'customer_note',
							'answer' => 'required',
						],
						[
							'id'    => 'floor',
							'type'  => 'number',
							'label' => 'Floor',
						],
					],
				],
			]
		);
		self::assertSame( 'Order notes', FieldSchema::additional()->field( 'customer_note' )?->label );
		$result = ( new CheckoutValidator() )->addresses( $this->request( [ 'billing' => self::BILLING ] + [ 'additional' => [ 'floor' => 'third' ] ] ), true );
		self::assertSame( [ 'customer_note', 'additional.floor' ], array_keys( $result['errors'] ) );
		self::assertSame( 'Floor must be a number.', $result['errors']['additional.floor'] );
	}

	/** @param array<string,mixed> $data */
	private function request( array $data ): CheckoutRequest {
		return CheckoutRequest::from_array( $data + [ 'checkout_token' => 't' ], 0 );
	}
}
