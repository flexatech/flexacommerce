<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Maintenance\Cron;

defined( 'ABSPATH' ) || exit;

/** Deactivation keeps all data. Only the opt-in uninstall removes it. */
final class Deactivator {
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => Activator::NETWORK_LIMIT,
				]
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				Cron::unschedule();
				restore_current_blog();
			}
			return;
		}
		Cron::unschedule();
		flush_rewrite_rules();
	}
}
