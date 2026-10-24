<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Cart\PurchaseOptions;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\FormHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Add-to-cart form (doc 02 §6.3, §7.2). Without JavaScript it is a plain
 * form posting to `admin-post.php`; for a variable product the server
 * matches the selected options. With JavaScript the Interactivity store
 * matches against the inlined matrix, disables options with no stock and
 * posts to `store/cart/items`.
 *
 * In a product list ("loop") a simple product gets a one-click button and a
 * variable product a link to its page.
 */
final class AddToCartForm {
	public const LOOP   = 'loop';
	public const SINGLE = 'single';

	public function __construct( private readonly PurchaseOptions $options ) {}

	public function html( Product $product, string $mode = self::SINGLE, int $user_id = 0 ): string {
		return Component::wrap(
			'add_to_cart',
			fn (): string => $this->markup( $product, $mode, $user_id ),
			[
				'product_id' => $product->id,
				'mode'       => $mode,
			]
		);
	}

	private function markup( Product $product, string $mode, int $user_id ): string {
		if ( $mode === self::LOOP && $product->is_variable() ) {
			return sprintf(
				'<p class="fxc-add-to-cart fxc-add-to-cart--loop"><a class="wp-element-button" href="%1$s" aria-label="%2$s">%3$s</a></p>',
				esc_url( (string) get_permalink( $product->id ) ),
				/* translators: %s: product name */
				esc_attr( sprintf( __( 'Choose options for %s', 'flexacommerce' ), $product->title ) ),
				esc_html__( 'Choose options', 'flexacommerce' )
			);
		}

		$options = $this->options->for_product( $product, $user_id );
		if ( ! $options['purchasable'] ) {
			return '';
		}
		if ( ! $options['in_stock'] ) {
			return '<p class="fxc-stock fxc-stock--out">' . esc_html__( 'Out of stock', 'flexacommerce' ) . '</p>';
		}

		Assets::use_store();
		$inline   = count( $options['variants'] ) <= PurchaseOptions::INLINE_LIMIT;
		$variable = $product->is_variable();
		$simple   = $variable ? null : $options['variants'][0];
		if ( $variable && $inline ) {
			wp_interactivity_state(
				'flexacommerce/cart',
				[
					'products' => [
						(string) $product->id => [
							'attributes' => array_column( $options['attributes'], 'key' ),
							'variants'   => $options['variants'],
						],
					],
				]
			);
		}

		$max     = $simple !== null && $simple['max'] !== null ? min( $simple['max'], $options['max_quantity'] ) : $options['max_quantity'];
		$context = [
			'productId' => $product->id,
			'variantId' => $simple !== null ? $simple['id'] : 0,
			'variable'  => $variable,
			'remote'    => $variable && ! $inline,
			'selection' => (object) [],
			'quantity'  => 1,
			'max'       => $max,
			'limit'     => $options['max_quantity'],
			'price'     => '',
			'regular'   => '',
			'stock'     => '',
			'ready'     => ! $variable,
			'busy'      => false,
			'message'   => '',
			'failed'    => false,
		];

		$html  = sprintf(
			'<form class="fxc-add-to-cart fxc-add-to-cart--%1$s" method="post" action="%2$s" data-wp-interactive="flexacommerce/cart" %3$s data-wp-on--submit="actions.add">',
			esc_attr( $mode ),
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_interactivity_data_wp_context( $context )
		);
		$html .= FormHandler::hidden_fields( 'add' );
		$html .= sprintf( '<input type="hidden" name="product_id" value="%d">', $product->id );
		$html .= sprintf(
			'<input type="hidden" name="variant_id" value="%d" data-wp-bind--value="context.variantId">',
			$simple !== null ? $simple['id'] : 0
		);

		if ( $variable ) {
			$html .= self::selectors( $options['attributes'] );
			$html .= '<div class="fxc-add-to-cart__variant" data-wp-bind--hidden="!context.ready" hidden>';
			$html .= '<p class="fxc-price"><del data-wp-bind--hidden="!context.regular" data-wp-text="context.regular" hidden></del> <span class="fxc-price__amount" data-wp-text="context.price"></span></p>';
			$html .= '<p class="fxc-stock" data-wp-text="context.stock"></p>';
			$html .= '</div>';
		}

		if ( $mode === self::SINGLE ) {
			$id    = wp_unique_id( 'fxc-qty-' );
			$html .= sprintf(
				'<label class="screen-reader-text" for="%1$s">%2$s</label><input class="fxc-add-to-cart__quantity" id="%1$s" type="number" name="quantity" value="1" min="1" max="%3$d" step="1" inputmode="numeric" data-wp-bind--max="context.max" data-wp-on--input="actions.setQuantity">',
				esc_attr( $id ),
				/* translators: %s: product name */
				esc_html( sprintf( __( 'Quantity of %s', 'flexacommerce' ), $product->title ) ),
				$max
			);
		} else {
			$html .= '<input type="hidden" name="quantity" value="1">';
		}

		$html .= sprintf(
			'<button type="submit" class="wp-element-button fxc-add-to-cart__button" data-wp-bind--aria-disabled="state.cannotAdd" data-wp-class--is-busy="context.busy">%s</button>',
			esc_html__( 'Add to cart', 'flexacommerce' )
		);
		$html .= '<p class="fxc-add-to-cart__message" role="status" aria-live="polite" data-wp-text="context.message" data-wp-class--is-error="context.failed"></p>';
		$html .= FormHandler::notice_for( $product->id );
		return $html . '</form>';
	}

	/** @param list<array{key:string,name:string,options:list<array{key:string,label:string}>}> $attributes */
	private static function selectors( array $attributes ): string {
		$html = '';
		foreach ( $attributes as $attribute ) {
			$id    = wp_unique_id( 'fxc-attr-' );
			$html .= '<p class="fxc-add-to-cart__option" ' . wp_interactivity_data_wp_context( [ 'attribute' => $attribute['key'] ] ) . '>';
			$html .= sprintf( '<label for="%1$s">%2$s</label> ', esc_attr( $id ), esc_html( $attribute['name'] ) );
			$html .= sprintf(
				'<select id="%1$s" name="attributes[%2$s]" required data-attribute="%2$s" data-wp-on--change="actions.choose">',
				esc_attr( $id ),
				esc_attr( $attribute['key'] )
			);
			$html .= '<option value="">' . esc_html__( 'Choose an option', 'flexacommerce' ) . '</option>';
			foreach ( $attribute['options'] as $option ) {
				$html .= sprintf(
					'<option value="%1$s" data-wp-bind--disabled="state.optionUnavailable">%2$s</option>',
					esc_attr( $option['key'] ),
					esc_html( $option['label'] )
				);
			}
			$html .= '</select></p>';
		}
		return $html;
	}
}
