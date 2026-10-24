<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Cart\CartItem;
use Flexa\Commerce\Cart\CartPresenter;
use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\FormHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The cart page table (doc 02 §6.3). One form: quantity fields and the
 * "Update cart" button post to `admin-post.php` without JavaScript; with
 * it, quantity changes and removals go to `store/cart/items` and the totals
 * follow from the returned cart, which has the same shape as the inlined
 * state (CartPresenter).
 */
final class CartTable {
	public function __construct(
		private readonly CartPresenter $presenter,
		private readonly CartService $cart,
		private readonly PricingService $pricing,
	) {}

	public function html( CartState $state ): string {
		return Component::wrap(
			'cart',
			fn (): string => $this->markup( $state ),
			[ 'item_count' => $state->item_count() ]
		);
	}

	private function markup( CartState $state ): string {
		Assets::use_store();
		wp_interactivity_state( 'flexacommerce/cart', [ 'cart' => $this->presenter->to_array( $state ) ] );

		$html  = '<div class="fxc-cart" data-wp-interactive="flexacommerce/cart">';
		$html .= FormHandler::notice_for( 0 );
		$html .= '<ul class="fxc-notices" role="status" aria-live="polite">'
			. '<template data-wp-each--notice="state.cart.notices"><li class="fxc-notice" data-wp-text="context.notice.message"></li></template>'
			. '</ul>';

		$shop  = get_post_type_archive_link( PostTypes::PRODUCT );
		$html .= sprintf(
			'<div class="fxc-cart__empty" data-wp-bind--hidden="state.hasItems"%1$s><p>%2$s</p>%3$s</div>',
			$state->items !== [] ? ' hidden' : '',
			esc_html__( 'Your cart is empty.', 'flexacommerce' ),
			is_string( $shop ) ? '<p><a class="wp-element-button" href="' . esc_url( $shop ) . '">' . esc_html__( 'Continue shopping', 'flexacommerce' ) . '</a></p>' : ''
		);

		if ( $state->items === [] ) {
			return $html . '</div>';
		}

		$html .= '<form class="fxc-cart__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wp-bind--hidden="!state.hasItems">';
		$html .= FormHandler::hidden_fields( 'update' );
		$html .= '<table class="fxc-cart__table"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Product', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Price', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Quantity', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Total', 'flexacommerce' ) . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Remove', 'flexacommerce' ) . '</span></th>'
			. '</tr></thead><tbody>';
		foreach ( $state->items as $item ) {
			$html .= $this->row( $item );
		}
		$html .= '</tbody></table>';

		$html .= sprintf(
			'<p class="fxc-cart__subtotal">%1$s <strong data-wp-text="state.cart.totals.subtotal.formatted">%2$s</strong></p>',
			esc_html__( 'Subtotal:', 'flexacommerce' ),
			esc_html( $this->presenter->money( $state->subtotal )['formatted'] )
		);
		$html .= '<p class="fxc-cart__actions"><button type="submit" class="wp-element-button">' . esc_html__( 'Update cart', 'flexacommerce' ) . '</button></p>';
		$html .= '<p class="fxc-cart__message" role="status" aria-live="polite" data-wp-text="state.message"></p>';
		return $html . '</form></div>';
	}

	private function row( CartItem $item ): string {
		$line    = $item->line;
		$product = $item->product;
		$name    = $product !== null ? $product->title : __( 'Unavailable product', 'flexacommerce' );
		$link    = $product !== null ? (string) get_permalink( $product->id ) : '';
		$labels  = '';
		foreach ( $line->labels as $label ) {
			$labels .= sprintf( '<li><span>%1$s:</span> %2$s</li>', esc_html( $label['name'] ), esc_html( $label['value'] ) );
		}
		$field = 'fxc-line-' . $line->key;
		$state = $item->status === CartItem::OK ? '' : ' is-problem';

		$html  = sprintf(
			'<tr class="fxc-cart__line%1$s" %2$s data-wp-bind--hidden="state.lineRemoved">',
			$state,
			wp_interactivity_data_wp_context( [ 'key' => $line->key ] )
		);
		$html .= '<td class="fxc-cart__product">'
			. ( $link !== '' ? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ) )
			. ( $labels !== '' ? '<ul class="fxc-cart__attributes">' . $labels . '</ul>' : '' )
			. '</td>';
		$html .= '<td class="fxc-cart__price">' . ( $item->unit !== null ? ProductPrice::single( $this->pricing, $item->unit ) : '' ) . '</td>';
		$html .= sprintf(
			'<td class="fxc-cart__quantity"><label class="screen-reader-text" for="%1$s">%2$s</label><input id="%1$s" type="number" name="quantities[%3$s]" value="%4$d" min="0" max="%5$d" step="1" inputmode="numeric" data-wp-on--change="actions.setLineQuantity"></td>',
			esc_attr( $field ),
			/* translators: %s: product name */
			esc_html( sprintf( __( 'Quantity of %s', 'flexacommerce' ), $name ) ),
			esc_attr( $line->key ),
			$line->quantity,
			max( $line->quantity, $product !== null && $item->variant !== null ? $item->max_quantity( $this->line_max( $item ) ) : $line->quantity )
		);
		$html .= sprintf(
			'<td class="fxc-cart__total" data-wp-text="state.lineTotal">%s</td>',
			$item->total !== null ? esc_html( $this->presenter->money( $item->total )['formatted'] ) : ''
		);
		$html .= sprintf(
			'<td class="fxc-cart__remove"><button type="submit" class="fxc-cart__remove-button" name="remove" value="%1$s" aria-label="%2$s" data-wp-on--click="actions.removeLine">&times;</button></td>',
			esc_attr( $line->key ),
			/* translators: %s: product name */
			esc_attr( sprintf( __( 'Remove %s from the cart', 'flexacommerce' ), $name ) )
		);
		return $html . '</tr>';
	}

	private function line_max( CartItem $item ): int {
		return $item->product !== null && $item->variant !== null
			? $this->cart->line_max_quantity( $item->product, $item->variant )
			: 0;
	}
}
