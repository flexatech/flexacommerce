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

	private static bool $registered = false;

	private static bool $used = false;

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
			],
		];
	}

	/**
	 * The cart page, else the shop. Resolving the page's permalink costs one
	 * post lookup when no object cache holds it.
	 */
	public static function cart_url(): string {
		$page = Settings::int( 'cart_page_id' );
		$url  = $page > 0 && get_post_status( $page ) === 'publish' ? get_permalink( $page ) : false;
		if ( is_string( $url ) ) {
			return $url;
		}
		$shop = get_post_type_archive_link( PostTypes::PRODUCT );
		return is_string( $shop ) ? $shop : home_url( '/' );
	}
}
