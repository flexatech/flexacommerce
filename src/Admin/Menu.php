<?php

declare(strict_types=1);

namespace Flexa\Commerce\Admin;

use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level FlexaCommerce menu. Every screen renders the same React root;
 * the app reads `flexaCommerce.page` to pick the screen.
 */
final class Menu {
	use HasInstance;

	public const SLUG = 'flexacommerce';

	/** @var array<string,string> page slug => hook suffix */
	private array $pages = [];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_filter( 'admin_body_class', [ $this, 'admin_body_class' ] );
	}

	public function register_menu(): void {
		$this->add(
			self::SLUG,
			(string) add_menu_page(
				__( 'FlexaCommerce', 'flexacommerce' ),
				__( 'FlexaCommerce', 'flexacommerce' ),
				Capabilities::MANAGE,
				self::SLUG,
				[ $this, 'render_page' ],
				'dashicons-store',
				56
			)
		);
		$this->add(
			self::SLUG,
			(string) add_submenu_page(
				self::SLUG,
				__( 'Status', 'flexacommerce' ),
				__( 'Status', 'flexacommerce' ),
				Capabilities::MANAGE,
				self::SLUG,
				[ $this, 'render_page' ]
			)
		);
		$this->add(
			self::SLUG . '-settings',
			(string) add_submenu_page(
				self::SLUG,
				__( 'Settings', 'flexacommerce' ),
				__( 'Settings', 'flexacommerce' ),
				Capabilities::MANAGE,
				self::SLUG . '-settings',
				[ $this, 'render_page' ]
			)
		);
	}

	/** @return array<string,string> page slug => hook suffix */
	public function pages(): array {
		return $this->pages;
	}

	/** The page slug for a hook suffix, or '' when the screen is not ours. */
	public function page_for( string $hook_suffix ): string {
		$slug = array_search( $hook_suffix, $this->pages, true );
		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * Tailwind utilities are scoped under `.flexacommerce-ui`; putting the
	 * class on <body> keeps them working inside Radix portals.
	 */
	public function admin_body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen !== null && $this->page_for( $screen->id ) !== '' && ! str_contains( $classes, 'flexacommerce-ui' ) ) {
			$classes .= ' flexacommerce-ui';
		}
		return $classes;
	}

	public function render_page(): void {
		echo '<div id="flexacommerce-admin-root" class="flexacommerce-ui"></div>';
	}

	private function add( string $slug, string $hook_suffix ): void {
		if ( $hook_suffix !== '' ) {
			$this->pages[ $slug ] = $hook_suffix;
		}
	}
}
