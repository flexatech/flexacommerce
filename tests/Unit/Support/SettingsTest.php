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
				'unknown'            => 'x',
				'currency'           => ' eur ',
				'currency_position'  => 'middle',
				'hold_minutes'       => '999999',
				'max_line_quantity'  => 'abc',
				'store_customer_ip'  => '0',
				'checkout_paused'    => 'yes',
				'thousand_separator' => '<b>"',
				'decimal_separator'  => ' , ',
				'order_number_prefix' => "<script>x</script>SO-",
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
