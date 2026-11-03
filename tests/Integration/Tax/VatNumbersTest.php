<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Tax\VatCheck;
use Flexa\Commerce\Tax\VatNumbers;

final class VatNumbersTest extends TaxTestCase {
	/** @var list<string> */
	private array $asked = [];

	public function set_up(): void {
		parent::set_up();
		$this->enable(
			[
				'store_country'  => 'DE',
				'store_state'    => '',
				'store_postcode' => '10115',
				'store_city'     => 'Berlin',
				'vat_numbers'    => 'optional',
			]
		);
	}

	public function tear_down(): void {
		global $wpdb;
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fxc\\_vat\\_%' OR option_name LIKE '\\_transient\\_%fxc\\_rl\\_%'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();
	}

	public function test_numbers_are_normalized_and_checked_against_the_billing_country(): void {
		$this->vies(
			[
				'isValid' => true,
				'name'    => 'ACME SARL',
				'address' => '1 rue de Paris',
			]
		);
		$vat = $this->vat();

		$check = $vat->check( 'fr 40-303.265 045', 'fr' );
		self::assertSame( [ 'FR40303265045', 'FR', VatCheck::VALID, 'ACME SARL' ], [ $check?->number, $check?->country, $check?->status, $check?->name ] );
		self::assertTrue( $vat->reverse_charge( $check ) );

		self::assertSame( 'FR40303265045', $vat->check( '40303265045', 'FR' )?->number, 'the prefix is added' );
		self::assertSame( 'EL123456789', $vat->check( 'GR123456789', 'GR' )?->number, 'Greece uses EL' );
		self::assertSame( VatCheck::MISMATCH, $vat->check( 'IT12345678901', 'FR' )?->status );
		self::assertSame( VatCheck::MALFORMED, $vat->check( 'FR123', 'FR' )?->status );
		self::assertNull( $vat->check( 'GB123456789', 'GB' ), 'outside the EU' );
		self::assertNull( $vat->check( '', 'FR' ) );
		self::assertSame( [ 'FR/40303265045', 'EL/123456789' ], $this->asked, 'VIES answers are cached' );
	}

	public function test_a_number_from_the_store_country_still_pays_vat(): void {
		$this->vies( [ 'isValid' => true ] );
		$vat   = $this->vat();
		$check = $vat->check( 'DE123456789', 'DE' );
		self::assertSame( VatCheck::VALID, $check?->status );
		self::assertFalse( $vat->reverse_charge( $check ) );
	}

	public function test_an_unregistered_number_is_refused(): void {
		$this->vies(
			[
				'isValid'   => false,
				'userError' => 'INVALID',
			]
		);
		$check = $this->vat()->check( 'FR40303265045', 'FR' );
		self::assertSame( VatCheck::INVALID, $check?->status );
		self::assertFalse( $check->usable() );
		self::assertFalse( $this->vat()->reverse_charge( $check ) );
	}

	public function test_an_unanswered_check_follows_the_setting(): void {
		$this->vies(
			[
				'isValid'   => false,
				'userError' => 'MS_UNAVAILABLE',
			]
		);
		$check = $this->vat()->check( 'FR40303265045', 'FR' );
		self::assertSame( VatCheck::UNVERIFIED, $check?->status );
		self::assertTrue( $check->usable() );
		self::assertTrue( $this->vat()->reverse_charge( $check ), 'accept by default' );

		$this->enable(
			[
				'store_country'  => 'DE',
				'vat_unverified' => 'charge',
			]
		);
		self::assertFalse( $this->vat()->reverse_charge( $check ) );
	}

	public function test_the_format_check_does_not_call_vies(): void {
		$this->vies( [ 'isValid' => true ] );
		$this->enable(
			[
				'store_country' => 'DE',
				'vat_numbers'   => 'optional',
				'vat_check'     => 'format',
			]
		);
		$check = $this->vat()->check( 'ATU12345678', 'AT' );
		self::assertSame( VatCheck::WELL_FORMED, $check?->status );
		self::assertTrue( $this->vat()->reverse_charge( $check ) );
		self::assertSame( [], $this->asked );
	}

	public function test_vies_calls_are_rate_limited_per_subject(): void {
		$this->vies(
			[
				'isValid'   => false,
				'userError' => 'INVALID',
			]
		);
		$vat = $this->vat();
		$this->expectException( RateLimited::class );
		for ( $i = 0; $i < 25; $i++ ) {
			$vat->check( sprintf( 'FR40%09d', $i ), 'FR', '203.0.113.9' );
		}
	}

	public function test_nothing_comes_off_when_the_field_is_off(): void {
		$this->vies( [ 'isValid' => true ] );
		$check = $this->vat()->check( 'FR40303265045', 'FR' );
		$this->enable(
			[
				'store_country' => 'DE',
				'vat_numbers'   => 'off',
			]
		);
		self::assertFalse( VatNumbers::enabled() );
		self::assertFalse( $this->vat()->reverse_charge( $check ) );
	}

	private function vat(): VatNumbers {
		return new VatNumbers( new RateLimiter() );
	}

	/** @param array<string,mixed> $answer */
	private function vies( array $answer ): void {
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ) use ( $answer ): mixed {
				unset( $pre, $args );
				if ( preg_match( '#/vies/rest-api/ms/([A-Z]{2})/vat/([^/?]+)#', $url, $m ) !== 1 ) {
					return false;
				}
				$this->asked[] = $m[1] . '/' . $m[2];
				return [
					'headers'  => [],
					'body'     => (string) wp_json_encode( $answer ),
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
}
