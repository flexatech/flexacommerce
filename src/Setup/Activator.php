<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Badges\BadgeRepository;
use Flexa\Commerce\Coupons\CouponRepository;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Maintenance\Cron;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Activator {
	/** Network activation installs on at most this many sites; the rest install on first admin visit. */
	public const NETWORK_LIMIT = 50;

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => self::NETWORK_LIMIT,
				]
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	public static function activate_site(): void {
		Schema::migrate();
		Settings::seed();
		self::seed_lists();
		Pages::install();
		Roles::install();
		Cron::schedule();
		Requirements::store();

		do_action( 'flexacommerce.activated' );
	}

	/**
	 * A site that was never installed (beyond the network limit, or created
	 * while the plugin was off) gets the full install; others just migrate.
	 */
	public static function maybe_upgrade(): void {
		if ( Schema::installed_version() === 0 ) {
			self::activate_site();
			return;
		}
		Schema::maybe_upgrade();
		self::seed_lists();
		if ( (int) get_option( Roles::VERSION_OPTION, 0 ) < Roles::VERSION ) {
			Roles::install();
			Cron::schedule();
		}
	}

	/**
	 * Badges, pricing rules and product options are read on storefront pages, and
	 * the automatic coupon flag by every cart. An empty list
	 * that exists rides in the autoloaded options; a missing one costs a
	 * query on every page without a persistent object cache.
	 */
	private static function seed_lists(): void {
		add_option( BadgeRepository::OPTION, [], '', true );
		add_option( PricingService::RULES_OPTION, [], '', true );
		add_option( ProductOptions::OPTION, [], '', true );
		add_option( CouponRepository::AUTOMATIC_OPTION, 0, '', true );
	}

	/** New site on a network where the plugin is network-active. */
	public static function initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( FLEXACOMMERCE_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::activate_site();
		restore_current_blog();
	}
}
