<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Cart\CartItem;
use Flexa\Commerce\Cart\CartPresenter;
use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Contracts\Pricing\PriceResolution;
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
	private const COUPON_FORM = 'fxc-cart-coupon';

	/** @param ?\Closure():ProductOptions $product_options to tell which lines have options to change */
	public function __construct(
		private readonly CartPresenter $presenter,
		private readonly CartService $cart,
		private readonly PricingService $pricing,
		private readonly ?\Closure $product_options = null,
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
		$data  = $this->presenter->to_array( $state );
		$shown = [];
		foreach ( (array) $data['items'] as $item ) {
			if ( is_array( $item ) ) {
				$shown[ (string) $item['key'] ] = $item;
			}
		}
		// The getters of cart.js, for the server render: without them the
		// directives read null, so a page without scripts showed an empty
		// cart. Closures make the client keep its own getters.
		$line = static fn (): array => $shown[ (string) ( wp_interactivity_get_context()['key'] ?? '' ) ] ?? [];
		wp_interactivity_state(
			'flexacommerce/cart',
			[
				'cart'        => $data,
				'hasItems'    => static fn (): bool => $shown !== [],
				'hasDiscount' => static fn (): bool => (int) ( $data['totals']['discount']['amount'] ?? 0 ) > 0,
				'showTotal'   => static fn (): bool => (int) ( $data['totals']['discount']['amount'] ?? 0 ) > 0 || ( $data['fees'] ?? [] ) !== [],
				'nextLabel'   => static fn (): string => self::next_label( $data['next_discount'] ?? null ),
				'nextWidth'   => static fn (): string => (int) ( $data['next_discount']['percent'] ?? 0 ) . '%',
				'lineRemoved' => static fn (): bool => $line() === [],
				'lineTotal'   => static fn (): string => (string) ( $line()['total']['formatted'] ?? '' ),
			]
		);

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
		// Enter in a field submits the form through its first submit button,
		// which would otherwise be the first line's remove button.
		$html .= '<button type="submit" class="fxc-cart__default" tabindex="-1" aria-hidden="true" data-wp-on--click="actions.submitCart">' . esc_html__( 'Update cart', 'flexacommerce' ) . '</button>';
		$html .= '<table class="fxc-cart__table"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Product', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Price', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Quantity', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Total', 'flexacommerce' ) . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Remove', 'flexacommerce' ) . '</span></th>'
			. '</tr></thead><tbody>';
		foreach ( $state->items as $item ) {
			$html .= $this->row( $item, $shown[ $item->line->key ] ?? [] );
		}
		$html .= '</tbody></table>';
		$html .= self::next_discount( $data['next_discount'] ?? null );
		$html .= self::totals( $data );
		$html .= '<p class="fxc-cart__actions"><button type="submit" class="wp-element-button">' . esc_html__( 'Update cart', 'flexacommerce' ) . '</button></p>';
		$html .= '<p class="fxc-cart__message" role="status" aria-live="polite" data-wp-text="state.message"></p>';
		$html .= '</form>';
		return $html . self::coupon_form() . '</div>';
	}

	/**
	 * Its own form after the cart form, so Enter in the code field applies
	 * the code. The remove buttons in the totals submit it through `form`.
	 */
	private static function coupon_form(): string {
		return '<form id="' . self::COUPON_FORM . '" class="fxc-cart__coupon" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wp-on--submit="actions.applyCoupon">'
			. FormHandler::hidden_fields( 'coupon' )
			. '<label for="fxc-coupon-code">' . esc_html__( 'Coupon code', 'flexacommerce' ) . '</label> '
			. '<input id="fxc-coupon-code" type="text" name="coupon_code" maxlength="' . Coupon::MAX_CODE_LENGTH . '" autocomplete="off" data-wp-bind--value="state.couponCode" data-wp-on--input="actions.setCouponCode"> '
			. '<button type="submit" class="wp-element-button" data-wp-bind--disabled="state.couponBusy">' . esc_html__( 'Apply', 'flexacommerce' ) . '</button>'
			. '<span class="fxc-cart__coupon-message" role="status" aria-live="polite" data-wp-text="state.couponMessage"></span>'
			. '</form>';
	}

	/**
	 * One row per code: the discount, or why the code does not apply. The
	 * remove button posts the coupon form without JavaScript; an automatic
	 * coupon has none.
	 *
	 * @param array<string,mixed> $coupon a CartPresenter coupon row
	 */
	private static function coupon_row( array $coupon, bool $child ): string {
		$code = (string) ( $coupon['code'] ?? '' );
		return sprintf(
			'<tr class="fxc-cart__coupon-row"%1$s><th scope="row"><span data-wp-text="state.couponLabel">%2$s</span> <button type="submit" class="fxc-cart__remove-coupon" form="%3$s" name="remove_coupon" data-wp-bind--value="context.coupon.code" data-wp-bind--hidden="context.coupon.automatic" value="%4$s" aria-label="%5$s" data-wp-on--click="actions.removeCoupon"%7$s>&times;</button></th><td data-wp-text="state.couponAmount">%6$s</td></tr>',
			$child ? ' data-wp-each-child' : '',
			esc_html( self::coupon_label( $coupon ) ),
			self::COUPON_FORM,
			esc_attr( $code ),
			esc_attr__( 'Remove coupon', 'flexacommerce' ),
			esc_html( ! empty( $coupon['valid'] ) ? CheckoutForm::coupon_amount( $coupon ) : (string) ( $coupon['message'] ?? '' ) ),
			empty( $coupon['automatic'] ) ? '' : ' hidden'
		);
	}

	/** @param array<mixed> $coupon a CartPresenter coupon row */
	private static function coupon_label( array $coupon ): string {
		$label = (string) ( $coupon['label'] ?? '' );
		return ! empty( $coupon['automatic'] )
			/* translators: %s: the name of an automatic discount */
			? sprintf( __( 'Discount: %s', 'flexacommerce' ), $label )
			/* translators: %s: coupon code */
			: sprintf( __( 'Coupon: %s', 'flexacommerce' ), $label );
	}

	/** "Spend $20.00 more for this discount: Spring sale.", from the cart response. */
	private static function next_label( mixed $next ): string {
		return is_array( $next ) ? (string) ( $next['message'] ?? '' ) : '';
	}

	/**
	 * How far the cart is from the next automatic discount, above the
	 * totals. The bar is decoration; the sentence says the same.
	 */
	private static function next_discount( mixed $next ): string {
		return sprintf(
			'<div class="fxc-cart__next" data-wp-bind--hidden="!state.cart.next_discount"%1$s><p aria-live="polite" data-wp-text="state.nextLabel">%2$s</p><div class="fxc-cart__progress" aria-hidden="true"><span data-wp-style--width="state.nextWidth" style="width:%3$d%%"></span></div></div>',
			is_array( $next ) ? '' : ' hidden',
			esc_html( self::next_label( $next ) ),
			is_array( $next ) ? (int) ( $next['percent'] ?? 0 ) : 0
		);
	}

	/**
	 * Subtotal, then the tax rows and the total when taxes are on (doc 02
	 * §7.12.5). The rows bind to the cart state, so a quantity change
	 * updates them from the `store/cart` response.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function totals( array $data ): string {
		$totals = (array) $data['totals'];
		$html   = '<table class="fxc-cart__totals"><tbody>';
		$html  .= sprintf(
			'<tr class="fxc-cart__subtotal"><th scope="row">%1$s</th><td data-wp-text="state.cart.totals.subtotal.formatted">%2$s</td></tr>',
			esc_html__( 'Subtotal', 'flexacommerce' ),
			esc_html( (string) ( $totals['subtotal']['formatted'] ?? '' ) )
		);
		$html  .= '<template data-wp-each--coupon="state.coupons" data-wp-each-key="context.coupon.code">' . self::coupon_row( [], false ) . '</template>';
		foreach ( (array) ( $data['coupons'] ?? [] ) as $coupon ) {
			$html .= self::coupon_row( (array) $coupon, true );
		}
		// The fees of the fee rules (ADR-043); they carry no tax.
		$html .= '<template data-wp-each--fee="state.cart.fees" data-wp-each-key="context.fee.id"><tr class="fxc-cart__fee"><th scope="row" data-wp-text="context.fee.name"></th><td data-wp-text="context.fee.amount.formatted"></td></tr></template>';
		foreach ( (array) ( $data['fees'] ?? [] ) as $fee ) {
			$html .= '<tr class="fxc-cart__fee" data-wp-each-child><th scope="row" data-wp-text="context.fee.name">' . esc_html( (string) $fee['name'] ) . '</th><td data-wp-text="context.fee.amount.formatted">' . esc_html( (string) $fee['amount']['formatted'] ) . '</td></tr>';
		}
		$tax = is_array( $data['tax'] ?? null ) ? $data['tax'] : null;
		if ( $tax === null ) {
			// Without taxes the total row only shows once a coupon takes something off or a fee adds to it.
			$html .= sprintf(
				'<tr class="fxc-cart__total-row" data-wp-bind--hidden="!state.showTotal"%1$s><th scope="row">%2$s</th><td><strong data-wp-text="state.cart.totals.total.formatted">%3$s</strong></td></tr>',
				(int) ( $totals['discount']['amount'] ?? 0 ) > 0 || ( $data['fees'] ?? [] ) !== [] ? '' : ' hidden',
				esc_html__( 'Total', 'flexacommerce' ),
				esc_html( (string) ( $totals['total']['formatted'] ?? '' ) )
			);
			return $html . '</tbody></table>';
		}
		if ( ! empty( $tax['pending'] ) ) {
			return $html . '<tr class="fxc-cart__note"><td colspan="2">' . esc_html__( 'Tax and shipping are calculated at checkout.', 'flexacommerce' ) . '</td></tr></tbody></table>';
		}
		$rows = '';
		foreach ( (array) $tax['lines'] as $line ) {
			$rows .= ! empty( $tax['included'] )
				? '<tr class="fxc-cart__tax fxc-cart__tax--included" data-wp-each-child><td colspan="2" data-wp-text="context.tax.text">' . esc_html( (string) $line['text'] ) . '</td></tr>'
				: '<tr class="fxc-cart__tax" data-wp-each-child><th scope="row" data-wp-text="context.tax.label">' . esc_html( (string) $line['label'] ) . '</th><td data-wp-text="context.tax.amount.formatted">' . esc_html( (string) $line['amount']['formatted'] ) . '</td></tr>';
		}
		$template = ! empty( $tax['included'] )
			? '<template data-wp-each--tax="state.cart.tax.lines"><tr class="fxc-cart__tax fxc-cart__tax--included"><td colspan="2" data-wp-text="context.tax.text"></td></tr></template>'
			: '<template data-wp-each--tax="state.cart.tax.lines"><tr class="fxc-cart__tax"><th scope="row" data-wp-text="context.tax.label"></th><td data-wp-text="context.tax.amount.formatted"></td></tr></template>';
		$total    = sprintf(
			'<tr class="fxc-cart__total-row"><th scope="row">%1$s</th><td><strong data-wp-text="state.cart.totals.total.formatted">%2$s</strong></td></tr>',
			esc_html__( 'Total', 'flexacommerce' ),
			esc_html( (string) ( $totals['total']['formatted'] ?? '' ) )
		);
		$html    .= ! empty( $tax['included'] ) ? $total . $template . $rows : $template . $rows . $total;
		return $html . '<tr class="fxc-cart__note"><td colspan="2">' . esc_html__( 'Shipping is calculated at checkout.', 'flexacommerce' ) . '</td></tr></tbody></table>';
	}

	/** @param array<string,mixed> $shown the item in the public cart shape, with display prices */
	private function row( CartItem $item, array $shown ): string {
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
			. ( $link !== '' && $this->editable( $item ) ? sprintf(
				'<a class="fxc-cart__edit" href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( add_query_arg( AddToCartForm::EDIT, $line->key, $link ) ),
				/* translators: %s: product name */
				esc_attr( sprintf( __( 'Change the options of %s', 'flexacommerce' ), $name ) ),
				esc_html__( 'Edit', 'flexacommerce' )
			) : '' )
			. '</td>';
		$html .= '<td class="fxc-cart__price">' . ( $item->unit !== null ? ProductPrice::single( $this->pricing, self::unit( $item, $shown ) ) : '' ) . '</td>';
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
			esc_html( (string) ( $shown['total']['formatted'] ?? '' ) )
		);
		$html .= sprintf(
			'<td class="fxc-cart__remove"><button type="submit" class="fxc-cart__remove-button" name="remove" value="%1$s" aria-label="%2$s" data-wp-on--click="actions.removeLine">&times;</button></td>',
			esc_attr( $line->key ),
			/* translators: %s: product name */
			esc_attr( sprintf( __( 'Remove %s from the cart', 'flexacommerce' ), $name ) )
		);
		return $html . '</tr>';
	}

	/**
	 * The unit price as the cart shows it: with tax display on, the price
	 * comes from the public shape, which holds the tax-adjusted amounts.
	 *
	 * @param array<string,mixed> $shown
	 */
	private static function unit( CartItem $item, array $shown ): PriceResolution {
		$unit   = $item->unit;
		$prices = is_array( $shown['prices'] ?? null ) ? $shown['prices'] : null;
		if ( $unit === null || $prices === null ) {
			return $unit ?? new PriceResolution( Money::zero( 'USD' ), Money::zero( 'USD' ), false );
		}
		$currency = $unit->active->currency;
		return new PriceResolution( new Money( (int) $prices['regular']['amount'], $currency ), new Money( (int) $prices['active']['amount'], $currency ), $unit->on_sale );
	}

	/** A line with a variation or option fields to pick again (ADR-037). */
	private function editable( CartItem $item ): bool {
		if ( $item->product === null ) {
			return false;
		}
		if ( $item->product->is_variable() || $item->line->options !== [] ) {
			return true;
		}
		return $this->product_options !== null && ProductOptions::in_use() && ( $this->product_options )()->sets_for( $item->product ) !== [];
	}

	private function line_max( CartItem $item ): int {
		return $item->product !== null && $item->variant !== null
			? $this->cart->line_max_quantity( $item->product, $item->variant )
			: 0;
	}
}
