<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Tax;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tax\RateRow;
use Flexa\Commerce\Tax\TaxRateRepository;
use Flexa\Commerce\Tax\TaxService;
use Flexa\Commerce\Tax\TaxServices;
use Flexa\Commerce\Tests\Integration\Orders\OrderTestCase;

/**
 * Checkout and the rate repository commit their own transactions, so the
 * rate tables and the settings option are put back in tear_down.
 */
abstract class TaxTestCase extends OrderTestCase {
	private mixed $settings = null;

	private ?TaxServices $tax = null;

	public function set_up(): void {
		parent::set_up();
		$this->settings = get_option( Settings::OPTION );
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		foreach ( [ 'tax_rate_locations', 'tax_rates' ] as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
		}
		update_option( Settings::OPTION, $this->settings, true );
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();
		$this->tax = null;
	}

	protected function taxes(): TaxServices {
		return $this->tax ??= new TaxServices( Plugin::instance()->services() );
	}

	protected function tax(): TaxService {
		return $this->taxes()->service();
	}

	protected function rates(): TaxRateRepository {
		return $this->taxes()->repository();
	}

	/**
	 * Taxes on, the store in Cupertino, California.
	 *
	 * @param array<string,mixed> $settings
	 */
	protected function enable( array $settings = [] ): void {
		Settings::update(
			$settings + [
				'tax_enabled'    => true,
				'store_country'  => 'US',
				'store_state'    => 'CA',
				'store_postcode' => '95014',
				'store_city'     => 'Cupertino',
			]
		);
		$this->tax()->compile_base_rates();
	}

	/** @param array<string,mixed> $input */
	protected function rate( array $input ): RateRow {
		$row = $this->rates()->save( RateRow::from_input( $input + [ 'country' => 'US' ] ) );
		$this->tax()->compile_base_rates();
		return $row;
	}
}
