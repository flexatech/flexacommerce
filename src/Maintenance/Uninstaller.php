<?php

declare(strict_types=1);

namespace Flexa\Commerce\Maintenance;

use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Removes data only on sites where the merchant turned on
 * "remove data on uninstall". Every other site keeps its store.
 */
final class Uninstaller {
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::maybe_erase();
			return;
		}

		$offset = 0;
		$batch  = 100;
		do {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => $batch,
					'offset' => $offset,
				]
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::maybe_erase();
				restore_current_blog();
			}
			$offset += $batch;
			$fetched = count( $sites );
		} while ( $fetched === $batch );
	}

	private static function maybe_erase(): void {
		if ( Settings::bool( 'remove_data_on_uninstall' ) ) {
			Eraser::erase_site();
		}
	}
}
