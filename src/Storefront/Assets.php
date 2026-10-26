<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Cart\CookieTokenStore;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront assets, enqueued only by a component that is on the page:
 * one script module (`@flexacommerce/cart`, the Interactivity store for the
 * form, cart and mini-cart) and one small stylesheet. A page without a
 * FlexaCommerce component loads neither (P1).
 */
final class Assets {
	public const MODULE = '@flexacommerce/cart';
	public const STYLE  = 'flexacommerce-storefront';

	public const CHECKOUT = '@flexacommerce/checkout';

	private static bool $registered = false;

	private static bool $used = false;

	/** @var array<string,mixed>|null */
	private static ?array $checkout = null;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		wp_register_script_module(
			self::MODULE,
			FLEXACOMMERCE_URL . 'assets/frontend/cart.js',
			[ [ 'id' => '@wordpress/interactivity' ] ],
			FLEXACOMMERCE_VERSION
		);
		wp_register_script_module( self::CHECKOUT, FLEXACOMMERCE_URL . 'assets/frontend/checkout.js', [], FLEXACOMMERCE_VERSION );
		wp_register_style( self::STYLE, FLEXACOMMERCE_URL . 'assets/frontend/storefront.css', [], FLEXACOMMERCE_VERSION );
		wp_style_add_data( self::STYLE, 'path', FLEXACOMMERCE_PATH . 'assets/frontend/storefront.css' );
	}

	/** Called by each component as it renders; the first call enqueues and prints config. */
	public static function use_store(): void {
		if ( self::$used ) {
			return;
		}
		self::$used = true;
		self::register();
		wp_enqueue_script_module( self::MODULE );
		wp_enqueue_style( self::STYLE );
		wp_interactivity_config( 'flexacommerce/cart', self::config() );
	}

	/** The stylesheet alone, enqueued before the page head prints (classic templates). */
	public static function use_style(): void {
		self::register();
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * The checkout and order-pay forms: a plain script module (no
	 * Interactivity store) that reads its config from the module data
	 * script WordPress prints for it.
	 *
	 * @param array<string,mixed> $config
	 */
	public static function use_checkout( array $config ): void {
		self::register();
		wp_enqueue_style( self::STYLE );
		if ( self::$checkout === null ) {
			wp_enqueue_script_module( self::CHECKOUT );
			add_filter( 'script_module_data_' . self::CHECKOUT, [ self::class, 'checkout_data' ] );
			$cart           = Plugin::instance()->cart();
			self::$checkout = [
				'api'   => esc_url_raw( rest_url( FLEXACOMMERCE_REST_NAMESPACE . '/store/' ) ),
				'nonce' => $cart->nonce()->create( get_current_user_id(), wp_get_session_token(), $cart->sessions()->token() ),
			];
		}
		self::$checkout = array_merge( self::$checkout, $config );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function checkout_data( array $data ): array {
		return array_merge( $data, self::$checkout ?? [] );
	}

	/** @return array<string,mixed> */
	public static function config(): array {
		$cart = Plugin::instance()->cart();
		return [
			'api'       => esc_url_raw( rest_url( FLEXACOMMERCE_REST_NAMESPACE . '/store/' ) ),
			'nonce'     => $cart->nonce()->create( get_current_user_id(), wp_get_session_token(), $cart->sessions()->token() ),
			'loggedIn'  => is_user_logged_in(),
			'cartUrl'   => self::cart_url(),
			'countName' => CookieTokenStore::COUNT_COOKIE,
			'i18n'      => [
				'added'       => __( 'Added to your cart.', 'flexacommerce' ),
				'failed'      => __( 'Could not update the cart. Please try again.', 'flexacommerce' ),
				'choose'      => __( 'Choose product options before adding to the cart.', 'flexacommerce' ),
				'unavailable' => __( 'This combination is not available.', 'flexacommerce' ),
				'inStock'     => __( 'In stock', 'flexacommerce' ),
				'outOfStock'  => __( 'Out of stock', 'flexacommerce' ),
				/* translators: %d: quantity in stock */
				'left'        => __( '%d in stock', 'flexacommerce' ),
				'updated'     => __( 'Cart updated.', 'flexacommerce' ),
				/* translators: %s: coupon code */
				'coupon'      => __( 'Coupon: %s', 'flexacommerce' ),
				'applied'     => __( 'Coupon applied.', 'flexacommerce' ),
				'unapplied'   => __( 'Coupon removed.', 'flexacommerce' ),
				'freeShip'    => __( 'Free shipping', 'flexacommerce' ),
			],
		];
	}

	/**
	 * The cart page, else the shop. Its permalink needs the page post; when
	 * no cache holds it the link is `?page_id=`, which WordPress redirects
	 * to the permalink, so a product page does not pay a query for a link
	 * few shoppers click (P2).
	 */
	public static function cart_url(): string {
		$page = Settings::int( 'cart_page_id' );
		if ( $page > 0 ) {
			if ( wp_cache_get( $page, 'posts' ) === false ) {
				return add_query_arg( 'page_id', $page, home_url( '/' ) );
			}
			$url = get_post_status( $page ) === 'publish' ? get_permalink( $page ) : false;
			if ( is_string( $url ) ) {
				return $url;
			}
		}
		$shop = get_post_type_archive_link( PostTypes::PRODUCT );
		return is_string( $shop ) ? $shop : home_url( '/' );
	}
}
