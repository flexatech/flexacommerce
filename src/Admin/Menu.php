<?php

declare(strict_types=1);

namespace Flexa\Commerce\Admin;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level FlexaCommerce menu. The React pages render the same root; the
 * app reads `flexaCommerce.page` to pick the screen. Products, categories
 * and tags are core screens listed under the same menu.
 */
final class Menu {
	use HasInstance;

	public const SLUG = 'flexacommerce';

	/** Submenu order; core adds the product list itself (show_in_menu). */
	private const ORDER = [
		self::SLUG . '-orders',
		self::SLUG . '-customers',
		'edit.php?post_type=' . PostTypes::PRODUCT,
		'edit-tags.php?taxonomy=' . PostTypes::CATEGORY . '&post_type=' . PostTypes::PRODUCT,
		'edit-tags.php?taxonomy=' . PostTypes::TAG . '&post_type=' . PostTypes::PRODUCT,
		self::SLUG . '-attributes',
		self::SLUG . '-product-options',
		self::SLUG . '-promotions',
		self::SLUG . '-settings',
		self::SLUG . '-migration',
		self::SLUG,
	];

	/** Pages that moved, old slug => the query args of the page they moved to. */
	private const MOVED = [
		self::SLUG . '-coupons' => [ 'page' => self::SLUG . '-promotions' ],
	];

	/** @var array<string,string> page slug => hook suffix */
	private array $pages = [];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_menu', [ $this, 'order_menu' ], 999 );
		add_action( 'admin_init', [ $this, 'redirect_moved' ] );
		add_filter( 'admin_body_class', [ $this, 'admin_body_class' ] );
		add_filter( 'parent_file', [ $this, 'parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'submenu_file' ] );
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
			self::SLUG . '-orders',
			(string) add_submenu_page(
				self::SLUG,
				__( 'Orders', 'flexacommerce' ),
				__( 'Orders', 'flexacommerce' ),
				Capabilities::VIEW_ORDERS,
				self::SLUG . '-orders',
				[ $this, 'render_page' ]
			)
		);
		$this->add(
			self::SLUG . '-customers',
			(string) add_submenu_page(
				self::SLUG,
				__( 'Customers', 'flexacommerce' ),
				__( 'Customers', 'flexacommerce' ),
				Capabilities::VIEW_ORDERS,
				self::SLUG . '-customers',
				[ $this, 'render_page' ]
			)
		);
		$this->add(
			self::SLUG . '-promotions',
			(string) add_submenu_page(
				self::SLUG,
				__( 'Promotions', 'flexacommerce' ),
				__( 'Promotions', 'flexacommerce' ),
				Capabilities::MANAGE,
				self::SLUG . '-promotions',
				[ $this, 'render_page' ]
			)
		);
		foreach ( [
			PostTypes::CATEGORY => __( 'Categories', 'flexacommerce' ),
			PostTypes::TAG      => __( 'Tags', 'flexacommerce' ),
		] as $taxonomy => $label ) {
			add_submenu_page(
				self::SLUG,
				$label,
				$label,
				Capabilities::MANAGE_PRODUCT_TERMS,
				'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . PostTypes::PRODUCT
			);
		}
		$this->add(
			self::SLUG . '-attributes',
			(string) add_submenu_page(
				self::SLUG,
				__( 'Attributes', 'flexacommerce' ),
				__( 'Attributes', 'flexacommerce' ),
				Capabilities::MANAGE_PRODUCT_TERMS,
				self::SLUG . '-attributes',
				[ $this, 'render_page' ]
			)
		);
		$this->add(
			self::SLUG . '-product-options',
			(string) add_submenu_page(
				self::SLUG,
				__( 'Product options', 'flexacommerce' ),
				__( 'Product options', 'flexacommerce' ),
				Capabilities::MANAGE,
				self::SLUG . '-product-options',
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
		$this->add(
			self::SLUG . '-migration',
			(string) add_submenu_page(
				self::SLUG,
				__( 'Migration', 'flexacommerce' ),
				__( 'Migration', 'flexacommerce' ),
				Capabilities::MANAGE,
				self::SLUG . '-migration',
				[ $this, 'render_page' ]
			)
		);
	}

	/** An old bookmark (the Coupons page before Promotions) opens the page it moved to. */
	public function redirect_moved(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a page link, no state change.
		if ( ! isset( self::MOVED[ $page ] ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( self::MOVED[ $page ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Orders first: the top-level item links to the first submenu entry. */
	public function order_menu(): void {
		global $submenu;
		if ( ! isset( $submenu[ self::SLUG ] ) || ! is_array( $submenu[ self::SLUG ] ) ) {
			return;
		}
		$rank = array_flip( self::ORDER );
		usort(
			$submenu[ self::SLUG ],
			static fn ( array $a, array $b ): int => ( $rank[ $a[2] ] ?? 100 ) <=> ( $rank[ $b[2] ] ?? 100 )
		);
	}

	/** Keeps the menu open on product and term screens. */
	public function parent_file( string $parent_file ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen !== null && $screen->post_type === PostTypes::PRODUCT ? self::SLUG : $parent_file;
	}

	public function submenu_file( ?string $submenu_file ): ?string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen === null || $screen->post_type !== PostTypes::PRODUCT ) {
			return $submenu_file;
		}
		if ( $screen->base === 'edit-tags' || $screen->base === 'term' ) {
			return 'edit-tags.php?taxonomy=' . $screen->taxonomy . '&post_type=' . PostTypes::PRODUCT;
		}
		return 'edit.php?post_type=' . PostTypes::PRODUCT;
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
