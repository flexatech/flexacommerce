<?php

declare(strict_types=1);

namespace Flexa\Commerce;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Contracts\Exceptions\IncompatibleApi;
use Flexa\Commerce\Contracts\Extensions;
use Flexa\Commerce\Support\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Registers hooks only (doc 02 §4.1). No service is built during boot.
 */
final class Plugin {
	use HasInstance;

	private bool $booted = false;

	private ?Services $services = null;

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'admin_init', [ Setup\Activator::class, 'maybe_upgrade' ] );
		add_action( 'wp_initialize_site', [ Setup\Activator::class, 'initialize_site' ], 200 );
		add_filter( 'wpmu_drop_tables', [ Database\Schema::class, 'site_tables_to_drop' ] );

		Maintenance\Cron::instance()->register();
		Api\Router::instance()->register();
		Setup\Requirements::instance()->register();

		if ( is_admin() ) {
			Admin\Menu::instance()->register();
			Admin\Enqueue::instance()->register();
		}

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			Setup\Activator::maybe_upgrade();
			Cli\ShopCommand::register();
			Cli\DbCommand::register();
		}

		add_action( 'plugins_loaded', [ $this, 'announce' ], 20 );
	}

	/** Extensions hook `flexacommerce.loaded` and receive the narrow facade. */
	public function announce(): void {
		try {
			do_action( 'flexacommerce.loaded', $this->extensions() );
		} catch ( IncompatibleApi $e ) {
			$this->services()->logger( 'extensions' )->error(
				'An extension needs a newer API: {message}',
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/** @internal Extensions use extensions(); the container may change. */
	public function services(): Services {
		if ( $this->services === null ) {
			global $wpdb;
			$this->services = new Services( $wpdb );
		}
		return $this->services;
	}

	public function extensions(): Extensions {
		return $this->services()->extensions();
	}
}
