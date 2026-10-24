<?php

declare(strict_types=1);

namespace Flexa\Commerce;

use Flexa\Commerce\Catalog\CatalogServices;
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

	private ?CatalogServices $catalog = null;

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'admin_init', [ Setup\Activator::class, 'maybe_upgrade' ] );
		add_action( 'wp_initialize_site', [ Setup\Activator::class, 'initialize_site' ], 200 );
		add_filter( 'wpmu_drop_tables', [ Database\Schema::class, 'site_tables_to_drop' ] );

		Catalog\PostTypes::instance()->register();
		add_action( 'save_post_' . Catalog\PostTypes::PRODUCT, [ $this, 'on_product_saved' ], 5 );
		add_action( 'before_delete_post', [ $this, 'on_post_deleted' ], 10, 2 );

		Maintenance\Cron::instance()->register();
		Api\Router::instance()->register();
		Setup\Requirements::instance()->register();

		if ( is_admin() ) {
			Admin\Menu::instance()->register();
			Admin\Enqueue::instance()->register();
			Admin\ProductEditor::instance()->register();
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

	/** @internal */
	public function catalog(): CatalogServices {
		return $this->catalog ??= new CatalogServices( $this->services() );
	}

	/** Every product gets its base row as soon as the post exists (doc 03 §3). */
	public function on_product_saved( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) !== false || wp_is_post_autosave( $post_id ) !== false ) {
			return;
		}
		$this->catalog()->product_service()->ensure_base( $post_id );
	}

	public function on_post_deleted( int $post_id, \WP_Post $post ): void {
		if ( $post->post_type === Catalog\PostTypes::PRODUCT ) {
			$this->catalog()->product_service()->on_post_deleted( $post_id, $post );
		}
	}

	public function extensions(): Extensions {
		return $this->services()->extensions();
	}
}
