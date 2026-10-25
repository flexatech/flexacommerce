<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Support;

use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tests\Unit\TestCase;

final class SettingsTest extends TestCase {
	public function test_defaults_when_nothing_stored(): void {
		self::assertSame( Settings::defaults(), Settings::all() );
		self::assertSame( 'USD', Settings::string( 'currency' ) );
		self::assertFalse( Settings::bool( 'remove_data_on_uninstall' ) );
	}

	public function test_sanitize_drops_unknown_keys_and_coerces(): void {
		$clean = Settings::sanitize(
			[
				'unknown'             => 'x',
				'currency'            => ' eur ',
				'currency_position'   => 'middle',
				'hold_minutes'        => '999999',
				'max_line_quantity'   => 'abc',
				'store_customer_ip'   => '0',
				'checkout_paused'     => 'yes',
				'thousand_separator'  => '<b>"',
				'decimal_separator'   => ' , ',
				'order_number_prefix' => '<script>x</script>SO-',
			]
		);

		self::assertArrayNotHasKey( 'unknown', $clean );
		self::assertSame( 'EUR', $clean['currency'] );
		self::assertSame( 'left', $clean['currency_position'] );
		self::assertSame( 10080, $clean['hold_minutes'] );
		self::assertSame( 999, $clean['max_line_quantity'] );
		self::assertFalse( $clean['store_customer_ip'] );
		self::assertTrue( $clean['checkout_paused'] );
		self::assertSame( 'b', $clean['thousand_separator'] );
		self::assertSame( ' , ', $clean['decimal_separator'] );
		self::assertSame( 'xSO-', $clean['order_number_prefix'] );
	}

	public function test_store_country_and_sell_to_list_are_normalized(): void {
		$clean = Settings::sanitize(
			[
				'store_country'     => ' vn ',
				'sell_to_countries' => 'us, ca,us,usa,,x1',
			]
		);
		self::assertSame( 'VN', $clean['store_country'] );
		self::assertSame( [ 'US', 'CA' ], $clean['sell_to_countries'] );

		self::assertSame( 'US', Settings::sanitize( [ 'store_country' => 'USA' ] )['store_country'] );
		self::assertSame( [ 'DE' ], Settings::sanitize( [ 'sell_to_countries' => [ 'de', 7, [ 'fr' ] ] ] )['sell_to_countries'] );
		self::assertSame( [], Settings::sanitize( [ 'sell_to_countries' => 42 ] )['sell_to_countries'] );
	}

	public function test_checkout_field_modes_page_ids_and_bases(): void {
		$clean = Settings::sanitize(
			[
				'checkout_company'   => 'required',
				'checkout_phone'     => 'sometimes',
				'checkout_address_2' => 'required',
				'terms_page_id'      => '-3',
				'checkout_page_id'   => '12',
				'product_base'       => ' Shop Items! ',
				'tag_base'           => [ 'x' ],
			]
		);
		self::assertSame( 'required', $clean['checkout_company'] );
		self::assertSame( 'optional', $clean['checkout_phone'], 'unknown mode keeps the default' );
		self::assertSame( 'optional', $clean['checkout_address_2'], 'address line 2 cannot be required' );
		self::assertSame( 0, $clean['terms_page_id'] );
		self::assertSame( 12, $clean['checkout_page_id'] );
		self::assertSame( 'shop-items', $clean['product_base'] );
		self::assertSame( '', $clean['tag_base'] );
	}

	public function test_typed_readers_never_return_the_wrong_type(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = 'corrupt';
		self::assertSame( 0, Settings::int( 'checkout_page_id' ) );
		self::assertSame( '', Settings::string( 'sell_to_countries' ), 'a list is not a string' );
		self::assertSame( 0, Settings::int( 'not_a_setting' ) );
		self::assertNull( Settings::get( 'not_a_setting' ) );
	}

	public function test_invalid_currency_keeps_default(): void {
		self::assertSame( 'USD', Settings::sanitize( [ 'currency' => 'XYZ' ] )['currency'] );
	}

	public function test_partial_update_merges_over_stored(): void {
		Settings::update( [ 'currency' => 'GBP' ] );
		$new = Settings::update( [ 'hold_minutes' => 30 ] );

		self::assertSame( 'GBP', $new['currency'] );
		self::assertSame( 30, $new['hold_minutes'] );

		$last = end( \WP_Test_State::$actions );
		self::assertSame( 'flexacommerce.settings.updated', $last[0] );
		self::assertSame( 60, $last[1][1]['hold_minutes'] );
	}

	public function test_corrupt_stored_values_are_coerced_on_read(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = [
			'currency'     => 'nope',
			'hold_minutes' => -5,
		];
		self::assertSame( 'USD', Settings::get( 'currency' ) );
		self::assertSame( 1, Settings::get( 'hold_minutes' ) );
	}

	public function test_seed_does_not_overwrite(): void {
		\WP_Test_State::$options[ Settings::OPTION ] = [ 'currency' => 'JPY' ];
		Settings::seed();
		self::assertSame( 'JPY', Settings::string( 'currency' ) );
	}
}
