<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Cart\PurchaseOptions;
use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Options\OptionSet;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\FormHandler;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Add-to-cart form (doc 02 §6.3, §7.2). Without JavaScript it is a plain
 * form posting to `admin-post.php`; for a variable product the server
 * matches the selected options. With JavaScript the Interactivity store
 * matches against the inlined matrix, disables options with no stock and
 * posts to `store/cart/items`.
 *
 * In a product list ("loop") a simple product gets a one-click button; a
 * variable product, or one with option fields to fill in, a link to its
 * page.
 */
final class AddToCartForm {
	public const LOOP   = 'loop';
	public const SINGLE = 'single';

	/** @param ?\Closure():ProductOptions $product_options built only when the store has option sets */
	public function __construct(
		private readonly PurchaseOptions $options,
		private readonly ?PricingService $pricing = null,
		private readonly ?\Closure $product_options = null,
	) {}

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
		$sets = $this->option_sets( $product, $mode );
		if ( $mode === self::LOOP && ( $product->is_variable() || $sets !== [] ) ) {
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
		$percent  = $sets !== [] && $this->pricing !== null && OptionFields::has_percent( $sets );
		if ( $variable && $inline ) {
			$variants = $options['variants'];
			if ( $percent ) {
				// What a percent option is taken of; only products with one carry it (P3b).
				foreach ( $variants as $i => $row ) {
					$variants[ $i ]['amount'] = $this->amount( $product, $row['id'], $user_id );
				}
			}
			wp_interactivity_state(
				'flexacommerce/cart',
				[
					'products' => [
						(string) $product->id => [
							'attributes' => array_column( $options['attributes'], 'key' ),
							'variants'   => $variants,
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
			'suffix'    => '',
			'stock'     => '',
			'ready'     => ! $variable,
			'busy'      => false,
			'message'   => '',
			'failed'    => false,
		];
		if ( $sets !== [] ) {
			$context['fields'] = OptionFields::spec( $sets );
			$context['base']   = $simple !== null && $percent ? $this->amount( $product, $simple['id'], $user_id ) : null;
			$context['extra']  = '';
			self::money_config();
		}

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
			$html .= '<p class="fxc-price"><del data-wp-bind--hidden="!context.regular" data-wp-text="context.regular" hidden></del> <span class="fxc-price__amount" data-wp-text="context.price"></span> <small class="fxc-price__suffix" data-wp-bind--hidden="!context.suffix" data-wp-text="context.suffix" hidden></small></p>';
			$html .= '<p class="fxc-stock" data-wp-text="context.stock"></p>';
			$html .= '</div>';
		}

		if ( $sets !== [] && $this->pricing !== null ) {
			$html .= OptionFields::html( $sets, $this->pricing );
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
		if ( $mode === self::SINGLE && $this->pricing !== null ) {
			$html .= QuantityTiers::html( $this->pricing, $product, $user_id );
		}
		$html .= '<p class="fxc-add-to-cart__message" role="status" aria-live="polite" data-wp-text="context.message" data-wp-class--is-error="context.failed"></p>';
		$html .= FormHandler::notice_for( $product->id );
		return $html . '</form>';
	}

	/**
	 * The option sets shown on this product. In a list only a simple
	 * product needs to know: a variable one links to its page anyway.
	 *
	 * @return list<OptionSet>
	 */
	private function option_sets( Product $product, string $mode ): array {
		if ( $this->product_options === null || ( $mode === self::LOOP && $product->is_variable() ) || get_option( ProductOptions::OPTION, [] ) === [] ) {
			return [];
		}
		return ( $this->product_options )()->sets_for( $product );
	}

	/** The unit price of one row before options, in minor units; null without a price. */
	private function amount( Product $product, int $variant_id, int $user_id ): ?int {
		$variant = $product->sellable( $variant_id );
		if ( $variant === null || $this->pricing === null ) {
			return null;
		}
		return $this->pricing->unit_price( $product, $variant, 1, $user_id )?->active->amount;
	}

	/** How the store writes an amount, for the options total. */
	private static function money_config(): void {
		$currency = Settings::string( 'currency' );
		wp_interactivity_config(
			'flexacommerce/cart',
			[
				'money' => [
					'symbol'   => html_entity_decode( Currency::symbol( $currency ), ENT_QUOTES ),
					'position' => Settings::string( 'currency_position' ),
					'decimals' => Currency::exponent( $currency ),
					'thousand' => Settings::string( 'thousand_separator' ),
					'decimal'  => Settings::string( 'decimal_separator' ),
				],
			]
		);
	}

	/** @param list<array{key:string,name:string,swatch:string,options:list<array{key:string,label:string,color:string,image:string}>}> $attributes */
	private static function selectors( array $attributes ): string {
		$html = '';
		foreach ( $attributes as $attribute ) {
			if ( $attribute['swatch'] !== AttributeRepository::DROPDOWN ) {
				$html .= self::swatches( $attribute );
				continue;
			}
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

	/**
	 * A group of radio buttons drawn as colours, images or buttons (ADR-022).
	 * They post the same `attributes[key]` field as the dropdown, so the
	 * form works without JavaScript; keyboard and screen readers get a plain
	 * radio group.
	 *
	 * @param array{key:string,name:string,swatch:string,options:list<array{key:string,label:string,color:string,image:string}>} $attribute
	 */
	private static function swatches( array $attribute ): string {
		$classes = [
			'fxc-add-to-cart__option',
			'fxc-swatches',
			'fxc-swatches--' . $attribute['swatch'],
			'fxc-swatches--' . Settings::string( 'swatch_shape' ),
		];
		if ( Settings::string( 'swatch_unavailable' ) === 'hide' ) {
			$classes[] = 'fxc-swatches--hide-unavailable';
		}
		$labels = [];
		foreach ( $attribute['options'] as $option ) {
			$labels[ $option['key'] ] = $option['label'];
		}

		$html  = sprintf(
			'<fieldset class="%1$s" style="--fxc-swatch-size:%2$dpx" %3$s>',
			esc_attr( implode( ' ', $classes ) ),
			Settings::int( 'swatch_size' ),
			wp_interactivity_data_wp_context(
				[
					'attribute' => $attribute['key'],
					'labels'    => (object) $labels,
				]
			)
		);
		$html .= '<legend class="fxc-swatches__legend">' . esc_html( $attribute['name'] );
		if ( Settings::bool( 'swatch_show_choice' ) ) {
			$html .= '<span class="fxc-swatches__chosen" data-wp-text="state.chosenLabel"></span>';
		}
		$html .= '</legend><div class="fxc-swatches__list">';
		foreach ( $attribute['options'] as $option ) {
			if ( $option['image'] !== '' ) {
				$face = sprintf( '<img src="%s" alt="" loading="lazy" decoding="async">', esc_url( $option['image'] ) );
				$kind = 'image';
			} elseif ( $option['color'] !== '' ) {
				$face = '';
				$kind = 'color';
			} else {
				$face = esc_html( $option['label'] );
				$kind = 'button';
			}
			$html .= sprintf(
				'<label class="fxc-swatch fxc-swatch--%1$s"%2$s><input class="fxc-swatch__input" type="radio" name="attributes[%3$s]" value="%4$s" required data-wp-on--change="actions.choose" data-wp-bind--disabled="state.optionUnavailable"><span class="fxc-swatch__face"%5$s>%6$s</span>%7$s</label>',
				esc_attr( $kind ),
				$kind !== 'button' ? ' title="' . esc_attr( $option['label'] ) . '"' : '',
				esc_attr( $attribute['key'] ),
				esc_attr( $option['key'] ),
				$kind === 'color' ? ' style="--fxc-swatch-color:' . esc_attr( $option['color'] ) . '"' : '',
				$face,
				$kind !== 'button' ? '<span class="screen-reader-text">' . esc_html( $option['label'] ) . '</span>' : ''
			);
		}
		return $html . '</div></fieldset>';
	}
}
