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

	private ?Coupons\CouponServices $coupons = null;

	private ?Badges\BadgeRepository $badges                           = null;
	private ?Pricing\Rules\PriceRuleRepository $price_rule_repository = null;
	private ?Pricing\Rules\PriceRules $price_rules                    = null;
	private ?Options\OptionSetRepository $option_sets                 = null;
	private ?Options\ProductOptions $product_options                  = null;
	private ?Inventory\OptionStockRepository $option_stock            = null;

	private ?Migration\MigrationServices $migration = null;

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
		Setup\Requirements::instance()->register();

		// Storefront (doc 02 §6.3): every callback below is a cheap gate that
		// loads the Storefront module only when the request needs it (P1).
		add_action( 'rest_api_init', [ $this, 'on_rest_api_init' ] );
		add_action( 'wp_logout', [ $this, 'on_logout' ] );
		add_shortcode( 'flexacommerce_cart', [ $this, 'shortcode_cart' ] );
		add_shortcode( 'flexacommerce_add_to_cart', [ $this, 'shortcode_add_to_cart' ] );
		add_shortcode( 'flexacommerce_checkout', [ $this, 'shortcode_checkout' ] );
		add_shortcode( 'flexacommerce_account', [ $this, 'shortcode_account' ] );
		add_shortcode( 'flexacommerce_order_confirmation', [ $this, 'shortcode_order_confirmation' ] );

		if ( is_admin() ) {
			add_action( 'init', [ $this, 'register_blocks' ] );
			add_action( 'admin_post_' . Storefront\FormHandler::ACTION, [ Storefront\FormHandler::class, 'handle' ] );
			add_action( 'admin_post_nopriv_' . Storefront\FormHandler::ACTION, [ Storefront\FormHandler::class, 'handle' ] );
			$this->watch_settings();
			Admin\Menu::instance()->register();
			Admin\Enqueue::instance()->register();
			Admin\ProductEditor::instance()->register();
			Privacy\Privacy::instance()->register();
			add_action( 'admin_notices', [ Storefront\TemplateLoader::class, 'notice' ] );
		} else {
			add_filter( 'pre_render_block', [ $this, 'on_pre_render_block' ], 10, 3 );
			add_filter( 'the_posts', [ $this, 'on_the_posts' ] );
			add_action( 'pre_get_posts', [ $this, 'on_pre_get_posts' ] );
			add_action( 'template_redirect', [ $this, 'on_template_redirect' ] );
			// A settings key, so the check needs no query and loads no class (doc 10 F1).
			if ( Support\Settings::bool( 'cutover_redirects' ) ) {
				add_action( 'template_redirect', [ Migration\Redirects::class, 'handle' ], 1 );
			}
		}

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			$this->watch_settings();
			Setup\Activator::maybe_upgrade();
			Cli\ShopCommand::register();
			Cli\DbCommand::register();
			Cli\MigrateCommand::register();
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
		return $this->cart ??= new CartServices(
			$this->services(),
			$this->catalog(),
			tax: fn (): Tax\TaxService => $this->tax()->service(),
			totals: fn ( array $data, Cart\CartState $state ): array => $this->cart_totals( $data, $state ),
			coupons: fn (): Coupons\CouponService => $this->coupons()->service(),
			rules: fn (): Pricing\Rules\PriceRules => $this->price_rules(),
			product_options: fn (): Options\ProductOptions => $this->product_options(),
			history: fn (): Contracts\Orders\CustomerHistory => $this->customer_history(),
		);
	}

	/** @internal Coupon codes, built the first time a cart holds one or the Coupons screen is opened. */
	public function coupons(): Coupons\CouponServices {
		return $this->coupons ??= new Coupons\CouponServices( $this->services(), $this->cart()->pricing() );
	}

	/** The stored pricing rules (ADR-024). */
	public function price_rule_repository(): Pricing\Rules\PriceRuleRepository {
		return $this->price_rule_repository ??= new Pricing\Rules\PriceRuleRepository();
	}

	/** Applies the pricing rules to unit prices (ADR-024). */
	public function price_rules(): Pricing\Rules\PriceRules {
		return $this->price_rules ??= new Pricing\Rules\PriceRules( $this->price_rule_repository(), fn (): Contracts\Orders\CustomerHistory => $this->customer_history() );
	}

	/** Past orders of a customer, for rule and coupon conditions (ADR-041). */
	public function customer_history(): Contracts\Orders\CustomerHistory {
		return $this->orders()->customers();
	}

	/** The stored option sets (ADR-025). */
	public function option_sets(): Options\OptionSetRepository {
		return $this->option_sets ??= new Options\OptionSetRepository( $this->option_stock() );
	}

	/** What is left of the option choices that track stock (ADR-040). */
	public function option_stock(): Inventory\OptionStockRepository {
		return $this->option_stock ??= new Inventory\OptionStockRepository( $this->services()->wpdb(), $this->services()->transaction() );
	}

	/** Checks and prices the product options of cart items (ADR-025). */
	public function product_options(): Options\ProductOptions {
		return $this->product_options ??= new Options\ProductOptions( $this->option_sets() );
	}

	/** The labels drawn over product images (ADR-023). */
	public function badges(): Badges\BadgeRepository {
		return $this->badges ??= new Badges\BadgeRepository();
	}

	/**
	 * The tax rows of the cart page and `store/cart` (doc 02 §7.12.5). With
	 * taxes off the Checkout module stays unloaded (PT-off).
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function cart_totals( array $data, Cart\CartState $state ): array {
		return Support\Settings::bool( 'tax_enabled' ) ? $this->checkout()->cart_taxes()->extend( $data, $state, get_current_user_id() ) : $data;
	}

	/**
	 * @internal Orders, order history and the extension order reader.
	 * Every order write builds this first, so the email listeners are bound
	 * here and nowhere else: requests that never touch an order add none (P1).
	 */
	public function orders(): Orders\OrderServices {
		if ( $this->orders === null ) {
			$this->orders = new Orders\OrderServices(
				$this->services(),
				$this->cart()->stock(),
				function ( int $order_id ): void {
					$this->coupons()->service()->release( $order_id );
				},
				$this->option_stock()
			);
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
		return $this->checkout ??= new Checkout\CheckoutServices( $this->services(), $this->cart(), $this->shipping(), $this->orders(), $this->payments(), $this->tax(), fn (): Coupons\CouponService => $this->coupons()->service() );
	}

	public function tax(): Tax\TaxServices {
		return $this->tax ??= new Tax\TaxServices( $this->services() );
	}

	/** @internal Migration jobs, built only when a job is touched. */
	public function migration(): Migration\MigrationServices {
		return $this->migration ??= new Migration\MigrationServices( $this->services(), $this->catalog(), $this->tax(), $this->shipping() );
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
	 * Our routes first, then the block types the editor needs over REST
	 * (block renderer, templates); one callback serves both (P1). Our own
	 * routes never render a block, and registering the editor script builds
	 * WP_Scripts, which costs two queries on every cart request (P4q).
	 */
	public function on_rest_api_init(): void {
		Api\Router::instance()->register_routes();
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
		// Added at the first product image (a Query Loop sets the post), so other pages carry no extra callback.
		if ( ( $parsed['blockName'] ?? null ) === 'core/post-featured-image' && get_post_type( (int) ( $parent->context['postId'] ?? get_the_ID() ) ) === Catalog\PostTypes::PRODUCT && ! has_filter( 'render_block_core/post-featured-image', [ $this, 'on_featured_image' ] ) ) {
			add_filter( 'render_block_core/post-featured-image', [ $this, 'on_featured_image' ], 10, 3 );
		}
		return $pre;
	}

	/**
	 * Badges on the image of a product in a Query Loop. Other posts, and
	 * stores without badges, pass through without loading the storefront.
	 *
	 * @param array<string,mixed> $parsed
	 */
	public function on_featured_image( string $html, array $parsed, ?\WP_Block $block = null ): string {
		unset( $parsed );
		$id = $block !== null && isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
		if ( $html === '' || $id <= 0 || get_post_type( $id ) !== Catalog\PostTypes::PRODUCT || get_option( Badges\BadgeRepository::OPTION, [] ) === [] ) {
			return $html;
		}
		return Storefront\Storefront::instance()->with_badges( $html, $id );
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

	/** The shop's main query: visibility, page size, sorting, filters and SKU search. */
	public function on_pre_get_posts( \WP_Query $query ): void {
		if ( $query->is_main_query() && (
			$query->is_search()
			|| $query->is_post_type_archive( Catalog\PostTypes::PRODUCT )
			|| $query->is_tax( [ Catalog\PostTypes::CATEGORY, Catalog\PostTypes::TAG ] )
		) ) {
			Storefront\ShopQuery::instance()->main( $query );
		}
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
			self::no_page_cache();
			Storefront\CheckoutPage::instance()->handle();
		}
		$account = Support\Settings::int( 'account_page_id' );
		if ( $account > 0 && is_page( $account ) ) {
			self::no_page_cache();
			Storefront\AccountPage::instance()->handle();
		}
		$cart = Support\Settings::int( 'cart_page_id' );
		if ( $cart > 0 && is_page( $cart ) ) {
			self::no_page_cache();
		}
	}

	/**
	 * Cart, checkout and account pages differ per visitor, so page caches
	 * must skip them (doc 02 §7.3.1). Catalog pages never get here.
	 */
	private static function no_page_cache(): void {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Read by WP Super Cache, W3 Total Cache, LiteSpeed Cache and others.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
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

	/** @param array<string,string>|string $atts */
	public function shortcode_account( array|string $atts = [] ): string {
		return Storefront\Storefront::instance()->shortcode_account( $atts );
	}

	/** @param array<string,string>|string $atts */
	public function shortcode_order_confirmation( array|string $atts = [] ): string {
		return Storefront\Storefront::instance()->shortcode_order_confirmation( $atts );
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
