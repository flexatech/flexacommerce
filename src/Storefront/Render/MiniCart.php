<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Storefront\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Header cart link with the item count. The server never reads the session
 * here, so the markup is the same for every visitor and catalog pages stay
 * cacheable (P11). The script reads the count from the `fxc_cart_count`
 * cookie, and asks `store/cart` only for a logged-in shopper whose browser
 * has no count yet (doc 10 Q19).
 */
final class MiniCart {
	public function html(): string {
		return Component::wrap( 'mini_cart', fn (): string => $this->markup(), [] );
	}

	private function markup(): string {
		Assets::use_store();
		$url = Assets::cart_url();
		return sprintf(
			'<a class="fxc-mini-cart" href="%1$s" data-wp-interactive="flexacommerce/cart" data-wp-init="callbacks.initCount" data-wp-class--has-items="state.count">'
			. '<span class="fxc-mini-cart__label">%2$s</span> '
			. '<span class="fxc-mini-cart__count" data-wp-text="state.countLabel" aria-live="polite"></span>'
			. '</a>',
			esc_url( $url ),
			esc_html__( 'Cart', 'flexacommerce' )
		);
	}
}
