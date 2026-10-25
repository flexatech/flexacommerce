<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Checkout;

use Flexa\Commerce\Checkout\CheckoutField;
use Flexa\Commerce\Checkout\CheckoutRequest;
use Flexa\Commerce\Checkout\CheckoutValidator;
use Flexa\Commerce\Checkout\FieldSchema;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Unit\TestCase;

/** Address checks against each country's field schema (doc 02 §7.5 step 3). */
final class CheckoutValidatorTest extends TestCase {
	private const BILLING = [
		'email'      => ' Ada@Example.COM ',
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Infinite Loop',
		'city'       => 'Cupertino',
		'state'      => 'ca',
		'postcode'   => '95014',
		'country'    => 'us',
	];

	public function test_a_valid_address_is_cleaned(): void {
		$result = $this->addresses( [ 'billing' => self::BILLING ] );

		self::assertSame( [], $result['errors'] );
		self::assertSame( 'ada@example.com', $result['billing']->email );
		self::assertSame( 'CA', $result['billing']->state );
		self::assertSame( 'US', $result['billing']->country );
		self::assertSame( 'Cupertino', $result['shipping']?->city, 'ships to the billing address' );
		self::assertSame( '', $result['shipping']->email, 'the shipping copy has no email' );
	}

	public function test_errors_are_keyed_by_field_path(): void {
		$result = $this->addresses(
			[
				'billing' => [
					'email'    => 'nope',
					'state'    => 'ZZ',
					'postcode' => '1',
					'phone'    => 'call me',
				] + self::BILLING,
			]
		);
		self::assertSame( [ 'billing.email', 'billing.state', 'billing.postcode', 'billing.phone' ], array_keys( $result['errors'] ) );
	}

	public function test_required_fields_and_lengths(): void {
		$errors = $this->addresses(
			[
				'billing' => [
					'first_name' => '  ',
					'city'       => str_repeat( 'x', 101 ),
				] + self::BILLING,
			]
		)['errors'];
		self::assertSame( 'First name is required.', $errors['billing.first_name'] );
		self::assertSame( 'Town / City can be at most 100 characters.', $errors['billing.city'] );
	}

	public function test_countries_outside_the_sell_to_list_are_refused(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = [ 'sell_to_countries' => [ 'VN' ] ];
		$errors                                      = $this->addresses( [ 'billing' => self::BILLING ] )['errors'];
		self::assertSame( 'We do not sell to this country.', $errors['billing.country'] );

		$errors = $this->addresses( [ 'billing' => [ 'country' => '' ] + self::BILLING ] )['errors'];
		self::assertSame( 'Choose a country.', $errors['billing.country'] );
	}

	public function test_a_country_without_postcodes_or_states_needs_neither(): void {
		$result = $this->addresses(
			[
				'billing' => [
					'country'  => 'HK',
					'state'    => '',
					'postcode' => '',
				] + self::BILLING,
			]
		);
		self::assertSame( [], $result['errors'] );
		self::assertSame( '', $result['billing']->postcode );
	}

	public function test_a_separate_shipping_address_is_checked_on_its_own(): void {
		$result = $this->addresses(
			[
				'billing'                   => self::BILLING,
				'ship_to_different_address' => '1',
				'shipping'                  => [
					'first_name' => 'Grace',
					'country'    => 'CA',
					'state'      => 'qc',
					'postcode'   => '12345',
				] + self::BILLING,
			]
		);
		self::assertSame( [ 'shipping.postcode' ], array_keys( $result['errors'] ) );
		self::assertSame( 'QC', $result['shipping']?->state );
		self::assertSame( 'Grace', $result['shipping']->first_name );
	}

	public function test_no_shipping_address_when_the_order_does_not_need_one(): void {
		self::assertNull( $this->addresses( [ 'billing' => self::BILLING ], false )['shipping'] );
	}

	public function test_hidden_fields_are_not_asked_for_and_required_ones_are(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = [
			'checkout_company' => 'required',
			'checkout_phone'   => 'hidden',
		];
		$result = $this->addresses( [ 'billing' => [ 'phone' => 'call me' ] + self::BILLING ] );
		self::assertSame( [ 'billing.company' ], array_keys( $result['errors'] ) );
		self::assertSame( '', $result['billing']->phone, 'a hidden field is not stored' );
	}

	public function test_extra_fields_from_the_filter_become_meta(): void {
		add_filter(
			'flexacommerce.checkout.fields',
			static fn ( FieldSchema $schema ): FieldSchema => $schema->with( new CheckoutField( 'vat_id', 'VAT ID', 'text', false, 'off', [], 20, 35 ) )
		);
		$result = $this->addresses( [ 'billing' => [ 'vat_id' => ' DE123 ' ] + self::BILLING ] );
		self::assertSame( [ '_billing_vat_id' => 'DE123' ], $result['meta'] );
	}

	public function test_destination_reads_the_raw_shipping_address_and_the_note_is_trimmed(): void {
		$validator = new CheckoutValidator();
		$request   = $this->request(
			[
				'billing'                   => self::BILLING,
				'ship_to_different_address' => true,
				'shipping'                  => [
					'country'  => ' ca ',
					'state'    => 'qc',
					'postcode' => 'h2x  1y4',
					'city'     => ' Montreal ',
				],
				'customer_note'             => '<b>Leave</b> at the door' . str_repeat( '!', CheckoutValidator::NOTE_MAX ),
			]
		);
		$destination = $validator->destination( $request );
		self::assertSame( [ 'CA', 'QC', 'H2X 1Y4', 'Montreal' ], [ $destination->country, $destination->state, $destination->postcode, $destination->city ] );

		$note = $validator->note( $request );
		self::assertStringStartsWith( 'Leave at the door', $note );
		self::assertSame( CheckoutValidator::NOTE_MAX, mb_strlen( $note ) );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{billing:\Flexa\Commerce\Contracts\Orders\AddressView,shipping:?\Flexa\Commerce\Contracts\Orders\AddressView,meta:array<string,string>,errors:array<string,string>}
	 */
	private function addresses( array $data, bool $needs_shipping = true ): array {
		return ( new CheckoutValidator() )->addresses( $this->request( $data ), $needs_shipping );
	}

	/** @param array<string,mixed> $data */
	private function request( array $data ): CheckoutRequest {
		return CheckoutRequest::from_array( $data + [ 'checkout_token' => 't' ], 0 );
	}
}
