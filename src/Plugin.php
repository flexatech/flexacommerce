<?php

declare(strict_types=1);

namespace Flexa\Commerce;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Catalog\CatalogServices;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Contracts\Exceptions\IncompatibleApi;
use Flexa\Commerce\Contracts\Extensions;
use Flexa\Commerce\Contracts\Orders\OrderReader;
use Flexa\Commerce\Contracts\Payments\GatewayRegistry;
use Flexa\Commerce\Contracts\Shipping\ShippingMethodRegistry;
use Flexa\Commerce\Support\ExtensionsFacade;
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

	private ?CartServices $cart = null;

	private ?Orders\OrderServices $orders = null;

	private ?Payments\PaymentServices $payments = null;

	private ?Shipping\ShippingServices $shipping = null;

	private ?Checkout\CheckoutServices $checkout = null;

	private ?Tax\TaxServices $tax = null;

	private ?Notifications\NotificationServices $notifications = null;

	private ?Extensions $extensions = null;

	private bool $blocks = false;

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

		// Storefront (doc 02 §6.3): every callback below is a cheap gate that
		// loads the Storefront module only when the request needs it (P1).
		add_action( 'rest_api_init', [ $this, 'on_rest_api_init' ] );
		add_action( 'wp_logout', [ $this, 'on_logout' ] );
		add_shortcode( 'flexacommerce_cart', [ $this, 'shortcode_cart' ] );
		add_shortcode( 'flexacommerce_add_to_cart', [ $this, 'shortcode_add_to_cart' ] );
		add_shortcode( 'flexacommerce_checkout', [ $this, 'shortcode_checkout' ] );

		if ( is_admin() ) {
			add_action( 'init', [ $this, 'register_blocks' ] );
			add_action( 'admin_post_' . Storefront\FormHandler::ACTION, [ Storefront\FormHandler::class, 'handle' ] );
			add_action( 'admin_post_nopriv_' . Storefront\FormHandler::ACTION, [ Storefront\FormHandler::class, 'handle' ] );
			$this->watch_settings();
			Admin\Menu::instance()->register();
			Admin\Enqueue::instance()->register();
			Admin\ProductEditor::instance()->register();
			Privacy\Privacy::instance()->register();
		} else {
			add_filter( 'pre_render_block', [ $this, 'on_pre_render_block' ], 10, 3 );
			add_filter( 'the_posts', [ $this, 'on_the_posts' ] );
			add_action( 'template_redirect', [ $this, 'on_template_redirect' ] );
		}

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			$this->watch_settings();
			Setup\Activator::maybe_upgrade();
			Cli\ShopCommand::register();
			Cli\DbCommand::register();
		}

		add_action( 'plugins_loaded', [ $this, 'announce' ], 20 );
	}

	/**
	 * Extensions hook `flexacommerce.loaded` and receive the narrow facade.
	 * Without a listener the facade and the container are not built (doc 10 F2).
	 */
	public function announce(): void {
		if ( ! has_action( 'flexacommerce.loaded' ) ) {
			return;
		}
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

	/** @internal Pricing, stock, sessions and the cart. */
	public function cart(): CartServices {
		return $this->cart ??= new CartServices( $this->services(), $this->catalog(), tax: fn (): Tax\TaxService => $this->tax()->service() );
	}

	/**
	 * @internal Orders, order history and the extension order reader.
	 * Every order write builds this first, so the email listeners are bound
	 * here and nowhere else: requests that never touch an order add none (P1).
	 */
	public function orders(): Orders\OrderServices {
		if ( $this->orders === null ) {
			$this->orders = new Orders\OrderServices( $this->services(), $this->cart()->stock() );
			$this->notifications()->notifier()->bind();
		}
		return $this->orders;
	}

	/** @internal Order emails, their settings and templates. */
	public function notifications(): Notifications\NotificationServices {
		return $this->notifications ??= new Notifications\NotificationServices( $this->services(), $this->orders(), fn (): Checkout\CheckoutServices => $this->checkout() );
	}

	/** @internal Zones, shipping methods and rates. */
	public function shipping(): Shipping\ShippingServices {
		return $this->shipping ??= new Shipping\ShippingServices( $this->services() );
	}

	/** @internal Gateways, transactions, webhooks and refunds. */
	public function payments(): Payments\PaymentServices {
		return $this->payments ??= new Payments\PaymentServices( $this->services(), fn (): Orders\OrderService => $this->orders()->service() );
	}

	/** @internal Checkout, order confirmation and order-pay. */
	public function checkout(): Checkout\CheckoutServices {
		return $this->checkout ??= new Checkout\CheckoutServices( $this->services(), $this->cart(), $this->shipping(), $this->orders(), $this->payments(), $this->tax() );
	}

	public function tax(): Tax\TaxServices {
		return $this->tax ??= new Tax\TaxServices( $this->services() );
	}

	/**
	 * Settings change only in admin, REST and WP-CLI requests, so the hook is
	 * added there and storefront pages keep their callback budget (P1).
	 */
	private function watch_settings(): void {
		if ( ! has_action( 'flexacommerce.settings.updated', [ $this, 'on_settings_updated' ] ) ) {
			add_action( 'flexacommerce.settings.updated', [ $this, 'on_settings_updated' ], 10, 2 );
		}
	}

	/**
	 * Recompile the base-location rates when a setting they depend on changes.
	 *
	 * @param array<string,mixed> $new
	 * @param array<string,mixed> $old
	 */
	public function on_settings_updated( array $new, array $old ): void {
		$this->tax()->service()->on_settings_updated( $new, $old );
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

	public function register_blocks(): void {
		if ( $this->blocks ) {
			return;
		}
		$this->blocks = true;
		remove_filter( 'pre_render_block', [ $this, 'on_pre_render_block' ] );
		Storefront\Storefront::instance()->register_blocks();
	}

	/**
	 * The editor needs the block types over REST (block renderer, templates).
	 * Our own routes never render a block, and registering the editor script
	 * builds WP_Scripts, which costs two queries on every cart request (P4q).
	 */
	public function on_rest_api_init(): void {
		$this->watch_settings();
		$route = isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ? (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' ) : '';
		if ( str_starts_with( $route, '/' . FLEXACOMMERCE_REST_NAMESPACE . '/' ) ) {
			return;
		}
		$this->register_blocks();
	}

	/**
	 * Registers the blocks the first time a top-level block whose tree holds
	 * one of ours is rendered. It has to be the top-level block: core builds
	 * an inner block, and looks up its type, before the filter runs for it.
	 * Template parts and synced patterns render their content as top-level
	 * blocks again, so a mini-cart in a header part is found too.
	 *
	 * @param array<string,mixed> $parsed
	 */
	public function on_pre_render_block( ?string $pre, array $parsed, ?\WP_Block $parent = null ): ?string {
		if ( $parent === null && self::has_own_block( $parsed ) ) {
			$this->register_blocks();
		}
		return $pre;
	}

	/** @param array<string,mixed> $parsed */
	private static function has_own_block( array $parsed ): bool {
		if ( is_string( $parsed['blockName'] ?? null ) && str_starts_with( $parsed['blockName'], 'flexacommerce/' ) ) {
			return true;
		}
		foreach ( is_array( $parsed['innerBlocks'] ?? null ) ? $parsed['innerBlocks'] : [] as $inner ) {
			if ( is_array( $inner ) && self::has_own_block( $inner ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Loads the variants of every product in a front-end query result with
	 * one query (doc 02 §7.1, O5), so a product list costs no query per item.
	 *
	 * @param array<mixed> $posts
	 * @return array<mixed>
	 */
	public function on_the_posts( array $posts ): array {
		$ids = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && $post->post_type === Catalog\PostTypes::PRODUCT ) {
				$ids[] = $post->ID;
			}
		}
		if ( $ids !== [] ) {
			$this->catalog()->products()->prime( $ids );
		}
		return $posts;
	}

	public function on_template_redirect(): void {
		if (
			is_singular( Catalog\PostTypes::PRODUCT )
			|| is_post_type_archive( Catalog\PostTypes::PRODUCT )
			|| is_tax( [ Catalog\PostTypes::CATEGORY, Catalog\PostTypes::TAG ] )
		) {
			Storefront\Storefront::instance()->on_template_redirect();
		}
		if ( self::is_checkout_request() ) {
			Storefront\CheckoutPage::instance()->handle();
		}
	}

	/**
	 * The checkout page, a checkout or order-pay post, or an order view.
	 * Literal names keep the Checkout classes unloaded on other pages (P1);
	 * CheckoutPage and CheckoutPages hold the same names as constants.
	 */
	private static function is_checkout_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification -- routing only; CheckoutPage verifies.
		if ( isset( $_POST['fxc_checkout'] ) || isset( $_POST['fxc_pay'] ) || isset( $_GET['order-received'] ) || isset( $_GET['order-pay'] ) ) {
			return true;
		}
		// phpcs:enable
		$page = Support\Settings::int( 'checkout_page_id' );
		return $page > 0 && is_page( $page );
	}

	/** The count cookie belongs to the user's cart; a guest starts at zero. */
	public function on_logout(): void {
		( new Cart\CookieTokenStore() )->clear();
	}

	/** @param array<string,string>|string $atts */
	public function shortcode_cart( array|string $atts = [] ): string {
		return Storefront\Storefront::instance()->shortcode_cart( $atts );
	}

	/** @param array<string,string>|string $atts */
	public function shortcode_add_to_cart( array|string $atts = [] ): string {
		return Storefront\Storefront::instance()->shortcode_add_to_cart( $atts );
	}

	/** @param array<string,string>|string $atts */
	public function shortcode_checkout( array|string $atts = [] ): string {
		return Storefront\Storefront::instance()->shortcode_checkout( $atts );
	}

	public function extensions(): Extensions {
		return $this->extensions ??= new ExtensionsFacade(
			$this->services(),
			fn (): GatewayRegistry => $this->payments()->gateways(),
			fn (): ShippingMethodRegistry => $this->shipping()->methods(),
			fn (): OrderReader => $this->orders()->reader(),
		);
	}
}
