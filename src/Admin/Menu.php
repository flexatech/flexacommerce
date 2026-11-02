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

	/** Product CSV import and export (ADR-059): reached from the product list, not the menu. */
	public const PRODUCT_CSV = self::SLUG . '-product-csv';

	/** Reviews::TYPE written out, so building the menu loads no Reviews class. */
	private const REVIEW_TYPE = 'fxc_review';

	/** Core's comments screen, filtered to product reviews (ADR-056). */
	private const REVIEWS = 'edit-comments.php?comment_type=' . self::REVIEW_TYPE;

	/** The product list, added by core (show_in_menu). */
	private const PRODUCTS = 'edit.php?post_type=' . PostTypes::PRODUCT;

	private const CATEGORIES = 'edit-tags.php?taxonomy=' . PostTypes::CATEGORY . '&post_type=' . PostTypes::PRODUCT;

	private const TAGS = 'edit-tags.php?taxonomy=' . PostTypes::TAG . '&post_type=' . PostTypes::PRODUCT;

	/**
	 * The menu groups (ADR-082). The sidebar shows one entry per group,
	 * linking to its first page the user may open; the app header and the
	 * core product screens show the group's pages as tabs.
	 */
	private const SECTIONS = [
		'orders'    => [ self::SLUG . '-orders', self::SLUG . '-subscriptions', self::SLUG . '-recovery' ],
		'products'  => [ self::PRODUCTS, self::CATEGORIES, self::TAGS, self::SLUG . '-attributes', self::SLUG . '-product-options', self::REVIEWS, self::SLUG . '-stock-alerts' ],
		'customers' => [ self::SLUG . '-customers' ],
		'marketing' => [ self::SLUG . '-promotions', self::SLUG . '-gift-cards' ],
		'settings'  => [ self::SLUG . '-settings', self::SLUG . '-checkout', self::SLUG, self::SLUG . '-migration' ],
	];

	/** Pages that moved, old slug => the query args of the page they moved to. */
	private const MOVED = [
		self::SLUG . '-coupons' => [ 'page' => self::SLUG . '-promotions' ],
	];

	/** @var array<string,string> page slug => hook suffix */
	private array $pages = [];

	/** @var array<string,string> page slug => capability, read from the submenu before it is grouped */
	private array $caps = [];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		// Late: admin.php finds the page hook and the title in the full submenu first.
		add_action( 'admin_enqueue_scripts', [ $this, 'group_menu' ], 0 );
		add_action( 'admin_init', [ $this, 'redirect_moved' ] );
		add_filter( 'admin_body_class', [ $this, 'admin_body_class' ] );
		add_filter( 'parent_file', [ $this, 'parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'submenu_file' ] );
		add_action( 'admin_footer-edit.php', [ $this, 'product_list_buttons' ] );
		add_action( 'all_admin_notices', [ $this, 'core_screen_tabs' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_commands' ], 20 );
	}

	/** @return array<string,string> section id => label */
	private static function section_labels(): array {
		return [
			'orders'    => __( 'Orders', 'flexacommerce' ),
			'products'  => __( 'Products', 'flexacommerce' ),
			'customers' => __( 'Customers', 'flexacommerce' ),
			'marketing' => __( 'Marketing', 'flexacommerce' ),
			'settings'  => __( 'Settings', 'flexacommerce' ),
		];
	}

	/** @return array<string,string> page slug => tab label */
	private static function page_labels(): array {
		return [
			self::SLUG . '-orders'          => __( 'Orders', 'flexacommerce' ),
			self::SLUG . '-subscriptions'   => __( 'Subscriptions', 'flexacommerce' ),
			self::SLUG . '-recovery'        => __( 'Abandoned carts', 'flexacommerce' ),
			self::PRODUCTS                  => __( 'All products', 'flexacommerce' ),
			self::CATEGORIES                => __( 'Categories', 'flexacommerce' ),
			self::TAGS                      => __( 'Tags', 'flexacommerce' ),
			self::SLUG . '-attributes'      => __( 'Attributes', 'flexacommerce' ),
			self::SLUG . '-product-options' => __( 'Product options', 'flexacommerce' ),
			self::REVIEWS                   => __( 'Reviews', 'flexacommerce' ),
			self::SLUG . '-stock-alerts'    => __( 'Stock alerts', 'flexacommerce' ),
			self::SLUG . '-customers'       => __( 'Customers', 'flexacommerce' ),
			self::SLUG . '-promotions'      => __( 'Promotions', 'flexacommerce' ),
			self::SLUG . '-gift-cards'      => __( 'Gift cards', 'flexacommerce' ),
			self::SLUG . '-settings'        => __( 'Settings', 'flexacommerce' ),
			self::SLUG . '-checkout'        => __( 'Checkout', 'flexacommerce' ),
			self::SLUG                      => __( 'Status', 'flexacommerce' ),
			self::SLUG . '-migration'       => __( 'Migration', 'flexacommerce' ),
		];
	}

	public function register_menu(): void {
		$labels = self::page_labels();
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
		foreach ( [
			self::SLUG                      => Capabilities::MANAGE,
			self::SLUG . '-orders'          => Capabilities::VIEW_ORDERS,
			self::SLUG . '-subscriptions'   => Capabilities::VIEW_ORDERS,
			self::SLUG . '-customers'       => Capabilities::VIEW_ORDERS,
			self::SLUG . '-recovery'        => Capabilities::VIEW_ORDERS,
			self::SLUG . '-stock-alerts'    => Capabilities::VIEW_ORDERS,
			self::SLUG . '-gift-cards'      => Capabilities::VIEW_ORDERS,
			self::SLUG . '-promotions'      => Capabilities::MANAGE,
			self::SLUG . '-checkout'        => Capabilities::MANAGE,
			self::SLUG . '-attributes'      => Capabilities::MANAGE_PRODUCT_TERMS,
			self::SLUG . '-product-options' => Capabilities::MANAGE,
			self::SLUG . '-settings'        => Capabilities::MANAGE,
			self::SLUG . '-migration'       => Capabilities::MANAGE,
		] as $slug => $capability ) {
			$this->add( $slug, (string) add_submenu_page( self::SLUG, $labels[ $slug ], $labels[ $slug ], $capability, $slug, [ $this, 'render_page' ] ) );
		}
		foreach ( [ self::CATEGORIES, self::TAGS ] as $slug ) {
			add_submenu_page( self::SLUG, $labels[ $slug ], $labels[ $slug ], Capabilities::MANAGE_PRODUCT_TERMS, $slug );
		}
		add_submenu_page( self::SLUG, $labels[ self::REVIEWS ], $labels[ self::REVIEWS ], 'moderate_comments', self::REVIEWS );
		// No parent: a page without a menu entry. The product list links to it.
		$csv = (string) add_submenu_page( '', __( 'Import and export products', 'flexacommerce' ), __( 'Import and export products', 'flexacommerce' ), Capabilities::MANAGE, self::PRODUCT_CSV, [ $this, 'render_page' ] );
		$this->add( self::PRODUCT_CSV, $csv );
		// Without a menu entry core finds no page title, so set it.
		add_action(
			'load-' . $csv,
			static function (): void {
				$GLOBALS['title'] = __( 'Import and export products', 'flexacommerce' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the admin page title.
			}
		);
	}

	/**
	 * Swaps the long submenu for one entry per section, just before the
	 * menu is drawn. The pages stay registered, so their links, permissions
	 * and titles are unchanged.
	 */
	public function group_menu(): void {
		global $submenu;
		if ( $this->caps !== [] || ! isset( $submenu[ self::SLUG ] ) || ! is_array( $submenu[ self::SLUG ] ) ) {
			return;
		}
		$entries = [];
		foreach ( $submenu[ self::SLUG ] as $entry ) {
			$entries[ (string) $entry[2] ]    = $entry;
			$this->caps[ (string) $entry[2] ] = (string) $entry[1];
		}
		$this->caps[ self::PRODUCT_CSV ] = Capabilities::MANAGE;

		$grouped = [];
		foreach ( self::section_labels() as $section => $label ) {
			$first = $this->first_page( $section );
			if ( $first !== '' && isset( $entries[ $first ] ) ) {
				$entry     = $entries[ $first ];
				$entry[0]  = $label;
				$entry[3]  = (string) ( $entry[3] ?? $label );
				$grouped[] = $entry;
			}
		}
		$submenu[ self::SLUG ] = $grouped; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the grouped menu replaces the long one.
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

	/**
	 * The sections and the pages in each that the current user may open.
	 *
	 * @return list<array{id:string,label:string,url:string,pages:list<array{slug:string,label:string,url:string}>}>
	 */
	public function sections(): array {
		$labels = self::page_labels();
		$out    = [];
		foreach ( self::section_labels() as $section => $label ) {
			$pages = [];
			foreach ( self::SECTIONS[ $section ] as $slug ) {
				if ( $this->can_open( $slug ) ) {
					$pages[] = [
						'slug'  => $slug,
						'label' => $labels[ $slug ],
						'url'   => self::url( $slug ),
					];
				}
			}
			if ( $pages !== [] ) {
				$out[] = [
					'id'    => $section,
					'label' => $label,
					'url'   => $pages[0]['url'],
					'pages' => $pages,
				];
			}
		}
		return $out;
	}

	/**
	 * The open screen as [section id, page slug]; the product CSV page has
	 * a section and no tab. Both are '' on a screen outside the menu.
	 *
	 * @return array{0:string,1:string}
	 */
	public function current(): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen === null ) {
			return [ '', '' ];
		}
		$slug = $this->page_for( $screen->id );
		if ( $slug === self::PRODUCT_CSV ) {
			return [ 'products', '' ];
		}
		if ( $slug === '' && $this->on_reviews() ) {
			$slug = self::REVIEWS;
		} elseif ( $slug === '' && $screen->post_type === PostTypes::PRODUCT ) {
			$slug = $screen->base === 'edit-tags' || $screen->base === 'term'
			? 'edit-tags.php?taxonomy=' . $screen->taxonomy . '&post_type=' . PostTypes::PRODUCT
			: self::PRODUCTS;
		}
		return [ self::section_of( $slug ), $slug ];
	}

	/** Keeps the menu open on every page of a section. */
	public function parent_file( string $parent_file ): string {
		return $this->current()[0] !== '' ? self::SLUG : $parent_file;
	}

	/** Highlights the section entry of the open page. */
	public function submenu_file( ?string $submenu_file ): ?string {
		$section = $this->current()[0];
		if ( $section === '' ) {
			return $submenu_file;
		}
		$first = $this->first_page( $section );
		return $first !== '' ? $first : $submenu_file;
	}

	/**
	 * The section tabs above core's product, term and review lists, so they
	 * read as one area with the app pages. Core's own nav-tab styles.
	 */
	public function core_screen_tabs(): void {
		[ $section, $slug ] = $this->current();
		$screen             = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $section === '' || isset( $this->pages[ $slug ] ) || $screen === null || $screen->base === 'post' ) {
			return;
		}
		foreach ( $this->sections() as $item ) {
			if ( $item['id'] !== $section ) {
				continue;
			}
			echo '<nav class="nav-tab-wrapper wp-clearfix" style="margin:10px 20px 0 2px" aria-label="' . esc_attr( $item['label'] ) . '">';
			foreach ( $item['pages'] as $page ) {
				$active = $page['slug'] === $slug;
				printf(
					'<a href="%s" class="nav-tab%s"%s>%s</a>',
					esc_url( $page['url'] ),
					$active ? ' nav-tab-active' : '',
					$active ? ' aria-current="page"' : '',
					esc_html( $page['label'] )
				);
			}
			echo '</nav>';
		}
	}

	/**
	 * Shortcuts the command palette offers besides the pages.
	 *
	 * @return list<array{label:string,url:string}>
	 */
	public function shortcuts(): array {
		$out = [];
		if ( current_user_can( Capabilities::EDIT_PRODUCTS ) ) {
			$out[] = [
				'label' => __( 'Add a product', 'flexacommerce' ),
				'url'   => self::url( 'post-new.php?post_type=' . PostTypes::PRODUCT ),
			];
		}
		if ( current_user_can( Capabilities::MANAGE ) ) {
			$out[] = [
				'label' => __( 'Import products', 'flexacommerce' ),
				'url'   => self::url( 'admin.php?page=' . self::PRODUCT_CSV . '&tab=import' ),
			];
			$out[] = [
				'label' => __( 'Export products', 'flexacommerce' ),
				'url'   => self::url( 'admin.php?page=' . self::PRODUCT_CSV . '&tab=export' ),
			];
		}
		return $out;
	}

	/**
	 * Every page, the tabs inside Settings and Promotions, and the
	 * shortcuts, for core's command palette (Ctrl or Cmd + K). The grouped
	 * menu hides most pages from the list core builds out of the submenu,
	 * so they are added here, with words to find them by.
	 *
	 * @return list<array{name:string,label:string,search:string,url:string}>
	 */
	public function commands(): array {
		$words = [
			self::SLUG . '-orders'          => __( 'sales, refunds, invoices, shipments', 'flexacommerce' ),
			self::SLUG . '-subscriptions'   => __( 'renewals, recurring, plans', 'flexacommerce' ),
			self::SLUG . '-recovery'        => __( 'abandoned, recovery, reminder emails', 'flexacommerce' ),
			self::SLUG . '-customers'       => __( 'buyers, accounts, users', 'flexacommerce' ),
			self::SLUG . '-gift-cards'      => __( 'store credit, balance, vouchers', 'flexacommerce' ),
			self::SLUG . '-attributes'      => __( 'variations, swatches, sizes, colours', 'flexacommerce' ),
			self::SLUG . '-product-options' => __( 'add-ons, extra fields, personalisation', 'flexacommerce' ),
			self::SLUG . '-stock-alerts'    => __( 'back in stock, inventory, waitlist', 'flexacommerce' ),
			self::SLUG . '-checkout'        => __( 'checkout fields, form builder, steps', 'flexacommerce' ),
			self::SLUG . '-migration'       => __( 'woocommerce, import, move', 'flexacommerce' ),
			self::SLUG                      => __( 'system status, health, logs', 'flexacommerce' ),
		];
		$tabs  = [
			self::SLUG . '-settings'   => [
				''           => [ __( 'General', 'flexacommerce' ), __( 'currency, units, store address', 'flexacommerce' ) ],
				'storefront' => [ __( 'Storefront', 'flexacommerce' ), __( 'shop page, wishlist, reviews, cart', 'flexacommerce' ) ],
				'payments'   => [ __( 'Payments', 'flexacommerce' ), __( 'stripe, paypal, bank transfer, cash on delivery, gateways', 'flexacommerce' ) ],
				'shipping'   => [ __( 'Shipping', 'flexacommerce' ), __( 'zones, rates, shipping classes, local pickup', 'flexacommerce' ) ],
				'tax'        => [ __( 'Tax', 'flexacommerce' ), __( 'vat, sales tax, rates', 'flexacommerce' ) ],
				'emails'     => [ __( 'Emails', 'flexacommerce' ), __( 'notifications, templates', 'flexacommerce' ) ],
				'invoices'   => [ __( 'Invoices', 'flexacommerce' ), __( 'pdf, credit notes', 'flexacommerce' ) ],
			],
			self::SLUG . '-promotions' => [
				''          => [ __( 'Coupons', 'flexacommerce' ), __( 'discount codes, vouchers', 'flexacommerce' ) ],
				'pricing'   => [ __( 'Pricing rules', 'flexacommerce' ), __( 'automatic discounts, bulk, tiers, bogo', 'flexacommerce' ) ],
				'badges'    => [ __( 'Badges', 'flexacommerce' ), __( 'labels, sale badge', 'flexacommerce' ) ],
				'simulator' => [ __( 'Simulator', 'flexacommerce' ), __( 'test cart, preview prices', 'flexacommerce' ) ],
			],
		];
		$out   = [];
		foreach ( $this->sections() as $section ) {
			foreach ( $section['pages'] as $page ) {
				$trail = $section['label'] === $page['label'] ? $page['label'] : $section['label'] . ' > ' . $page['label'];
				foreach ( $tabs[ $page['slug'] ] ?? [ '' => null ] as $tab => $named ) {
					$label = $named === null ? $trail : $trail . ' > ' . $named[0];
					$out[] = [
						'name'   => 'flexacommerce/' . sanitize_key( $page['slug'] . '-' . $tab ),
						'label'  => $label,
						'search' => $label . ' ' . ( $named[1] ?? '' ) . ' ' . ( $words[ $page['slug'] ] ?? '' ),
						'url'    => $tab === '' ? $page['url'] : $page['url'] . '&tab=' . $tab,
					];
				}
			}
		}
		foreach ( $this->shortcuts() as $i => $shortcut ) {
			$out[] = [
				'name'   => 'flexacommerce/shortcut-' . $i,
				'label'  => $shortcut['label'],
				'search' => $shortcut['label'],
				'url'    => $shortcut['url'],
			];
		}
		return $out;
	}

	/** Adds the commands to core's palette on every admin screen that loads it. */
	public function enqueue_commands(): void {
		if ( ! wp_script_is( 'wp-commands', 'enqueued' ) ) {
			return;
		}
		$commands = $this->commands();
		if ( $commands === [] ) {
			return;
		}
		wp_register_script( 'flexacommerce-commands', false, [ 'wp-commands', 'wp-data' ], FLEXACOMMERCE_VERSION, true );
		wp_enqueue_script( 'flexacommerce-commands' );
		wp_add_inline_script(
			'flexacommerce-commands',
			sprintf(
				'(function(c){var s=wp.data.dispatch(wp.commands.store);c.forEach(function(x){s.registerCommand({name:x.name,label:x.label,searchLabel:x.search,callback:function(o){o.close();window.location.href=x.url;}});});})(%s);',
				wp_json_encode( $commands )
			)
		);
	}

	/** The section a page belongs to, or ''. */
	private static function section_of( string $slug ): string {
		foreach ( self::SECTIONS as $section => $slugs ) {
			if ( in_array( $slug, $slugs, true ) ) {
				return $section;
			}
		}
		return '';
	}

	/** The first page of a section the current user may open, or ''. */
	private function first_page( string $section ): string {
		foreach ( self::SECTIONS[ $section ] as $slug ) {
			if ( $this->can_open( $slug ) ) {
				return $slug;
			}
		}
		return '';
	}

	private function can_open( string $slug ): bool {
		return isset( $this->caps[ $slug ] ) && current_user_can( $this->caps[ $slug ] );
	}

	private static function url( string $slug ): string {
		return esc_url_raw( str_contains( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug ) );
	}
	/** Import and Export next to "Add New" on the product list. */
	public function product_list_buttons(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen === null || $screen->id !== 'edit-' . PostTypes::PRODUCT || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$url   = admin_url( 'admin.php?page=' . self::PRODUCT_CSV );
		$links = sprintf(
			'<a href="%s" class="page-title-action">%s</a><a href="%s" class="page-title-action">%s</a>',
			esc_url( add_query_arg( 'tab', 'import', $url ) ),
			esc_html__( 'Import', 'flexacommerce' ),
			esc_url( add_query_arg( 'tab', 'export', $url ) ),
			esc_html__( 'Export', 'flexacommerce' )
		);
		wp_print_inline_script_tag(
			sprintf(
				'(function(){var a=document.querySelector(".wrap .page-title-action");if(a){a.insertAdjacentHTML("afterend",%s);}})();',
				wp_json_encode( $links )
			)
		);
	}

	/** The comments screen showing product reviews only. */
	private function on_reviews(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, no state change.
		return $screen !== null && $screen->base === 'edit-comments' && isset( $_GET['comment_type'] ) && sanitize_key( wp_unslash( $_GET['comment_type'] ) ) === self::REVIEW_TYPE;
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
