<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\FormHandler;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The heart button under the add-to-cart form (ADR-066). The markup is
 * the same for every visitor, so product pages and lists stay cacheable:
 * the store reads the `fxcm_wishlist_ids` cookie and sets `aria-pressed`.
 * Without scripts the button posts to admin-post.php and always saves.
 */
final class WishlistButton {
	public static function shown( string $mode ): bool {
		$setting = Settings::string( 'wishlist' );
		return $setting === 'all' || ( $setting === 'single' && $mode === AddToCartForm::SINGLE );
	}

	public static function html( Product $product, string $mode ): string {
		if ( ! self::shown( $mode ) || $product->status !== 'publish' ) {
			return '';
		}
		return Component::wrap(
			'wishlist_button',
			static fn (): string => self::markup( $product, $mode ),
			[
				'product_id' => $product->id,
				'mode'       => $mode,
			]
		);
	}

	private static function markup( Product $product, string $mode ): string {
		Assets::use_store();
		$loop  = $mode === AddToCartForm::LOOP;
		$label = $loop
			/* translators: %s: product name */
			? sprintf( __( 'Save %s to your wishlist', 'flexacommerce' ), $product->title )
			: __( 'Save to wishlist', 'flexacommerce' );
		$heart = '<svg class="fxc-wishlist-button__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8.2 3.4 4.5 7 4.5c2 0 3.5 1.1 5 3 1.5-1.9 3-3 5-3 3.6 0 5.5 3.7 4.3 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg>';
		return sprintf(
			'<form class="fxc-wishlist-button fxc-wishlist-button--%1$s" method="post" action="%2$s" data-wp-interactive="flexacommerce/cart" %3$s data-wp-init="callbacks.initWishlist" data-wp-on--submit="actions.toggleWishlist">%4$s<input type="hidden" name="product_id" value="%5$d"><button type="submit" class="fxc-wishlist-button__button" data-wp-bind--aria-pressed="state.inWishlist" data-wp-bind--disabled="context.busy"%6$s>%7$s<span class="%8$s">%9$s</span></button><span class="screen-reader-text" role="status" data-wp-text="context.wishlistMessage"></span>%10$s</form>',
			esc_attr( $mode ),
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_interactivity_data_wp_context(
				[
					'productId'       => $product->id,
					'busy'            => false,
					'wishlistMessage' => '',
				]
			),
			FormHandler::hidden_fields( 'wishlist_add' ),
			$product->id,
			$loop ? ' title="' . esc_attr( $label ) . '"' : '',
			$heart,
			$loop ? 'screen-reader-text' : 'fxc-wishlist-button__label',
			esc_html( $label ),
			FormHandler::notice_for( $product->id, FormHandler::SCOPE_WISHLIST )
		);
	}
}
