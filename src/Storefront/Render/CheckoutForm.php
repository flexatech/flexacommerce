<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Checkout\CartFacts;
use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Checkout\FieldSchema;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\CheckoutPage;
use Flexa\Commerce\Storefront\FormHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout form (doc 02 §7.5). It is a plain form that posts to the
 * checkout page itself, so an order can be placed without JavaScript;
 * `checkout.js` takes over the submit and requotes shipping over REST
 * when it loads. After a failed post the same request renders the form
 * again with the posted values and the errors next to their fields.
 */
final class CheckoutForm {
	private const COUPON_FORM = 'fxc-checkout-coupon';

	/**
	 * @param array<string,mixed>  $state  CheckoutService::form_state()
	 * @param array<string,mixed>  $posted the raw submission, empty on a plain visit
	 * @param array<string,string> $errors field path => message
	 */
	public function html( array $state, array $posted = [], array $errors = [] ): string {
		return Component::wrap(
			'checkout',
			fn (): string => $this->markup( $state, $posted, $errors ),
			[
				'state'  => $state,
				'errors' => $errors,
			]
		);
	}

	/**
	 * @param array<string,mixed>  $state
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	private function markup( array $state, array $posted, array $errors ): string {
		Assets::use_checkout( $this->config( $state ) );

		$cart = is_array( $state['cart'] ?? null ) ? $state['cart'] : [];
		$html = '<div class="fxc-checkout" data-fxc-checkout>';

		if ( ( $cart['items'] ?? [] ) === [] ) {
			$shop = get_post_type_archive_link( PostTypes::PRODUCT );
			return $html . '<p>' . esc_html__( 'Your cart is empty.', 'flexacommerce' ) . '</p>'
				. ( is_string( $shop ) ? '<p><a class="wp-element-button" href="' . esc_url( $shop ) . '">' . esc_html__( 'Continue shopping', 'flexacommerce' ) . '</a></p>' : '' )
				. '</div>';
		}
		if ( ! empty( $state['paused'] ) ) {
			return $html . '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html__( 'Checkout is paused for maintenance. Please try again shortly.', 'flexacommerce' ) . '</p></div>';
		}

		$layout = CheckoutLayout::current();
		$html  .= $this->summary( $errors );
		if ( ! empty( $state['preview'] ) ) {
			$html .= '<p class="fxc-notice fxc-checkout__preview" role="status">' . esc_html__( 'Preview of your unsaved checkout layout. Orders cannot be placed from this page.', 'flexacommerce' ) . '</p>';
		}
		$steps = $layout->flow() === CheckoutLayout::FLOW_STEPS;
		$html .= '<form class="fxc-checkout__form" method="post" action="' . esc_url( CheckoutPage::form_action() ) . '"' . ( $steps ? ' data-fxc-flow="steps"' : '' ) . '>';
		$html .= CheckoutPage::hidden_fields( (string) $state['checkout_token'], (int) ( $state['totals']['total']['amount'] ?? 0 ) );
		if ( $steps ) {
			$html .= $this->steps( ! empty( $state['needs_shipping'] ) );
		}

		$class = 'fxc-checkout__columns'
			. ( $layout->columns() === CheckoutLayout::ONE_COLUMN ? ' fxc-checkout__columns--one' : '' )
			. ( $layout->summary_position() === CheckoutLayout::SUMMARY_TOP ? ' fxc-checkout__columns--fold' : '' );
		$html .= '<div class="' . $class . '">';
		foreach ( [
			CheckoutLayout::MAIN => 'fxc-checkout__details',
			CheckoutLayout::SIDE => 'fxc-checkout__review',
		] as $column => $wrap ) {
			$blocks = $layout->blocks( $column );
			if ( $blocks === [] ) {
				continue;
			}
			$html .= '<div class="' . $wrap . '">';
			foreach ( $blocks as $index => $block ) {
				$part  = $this->block( $block, $layout, $state, $posted, $errors );
				$step  = $steps && $part !== '' ? $layout->step( $column, $index ) : '';
				$html .= $step !== '' ? '<div class="fxc-checkout__step" data-fxc-step="' . esc_attr( $step ) . '">' . $part . '</div>' : $part;
			}
			$html .= '</div>';
		}
		$html .= '</div></form>';
		if ( $layout->takes_coupons() ) {
			// Its own form, since forms do not nest: the code field and the remove buttons join it through `form`.
			$html .= '<form id="' . self::COUPON_FORM . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden data-fxc-coupon-form>'
				. FormHandler::hidden_fields( 'coupon' ) . '</form>';
		}
		return $html . '</div>';
	}

	/**
	 * The steps of a steps checkout (doc 11 §12). Without JavaScript the
	 * list stays hidden and the form is one page; `checkout.js` shows it
	 * and one step at a time. Shipping is left out when nothing ships.
	 */
	private function steps( bool $ships ): string {
		$names = [
			'information' => __( 'Information', 'flexacommerce' ),
			'shipping'    => __( 'Shipping', 'flexacommerce' ),
			'payment'     => __( 'Payment', 'flexacommerce' ),
		];
		$items = '';
		foreach ( CheckoutLayout::STEPS as $step ) {
			if ( $step === 'shipping' && ! $ships ) {
				continue;
			}
			$items .= '<li><button type="button" class="fxc-checkout__goto" data-fxc-goto="' . esc_attr( $step ) . '">' . esc_html( $names[ $step ] ) . '</button></li>';
		}
		return '<nav class="fxc-checkout__steps" aria-label="' . esc_attr__( 'Checkout steps', 'flexacommerce' ) . '" hidden data-fxc-steps><ol>' . $items . '</ol></nav>';
	}

	/**
	 * One section of the page, or a heading or paragraph of the merchant's.
	 *
	 * @param array{id:string,title?:string,type?:string,text?:string} $block
	 * @param array<string,mixed>                                       $state
	 * @param array<string,mixed>                                       $posted
	 * @param array<string,string>                                      $errors
	 */
	private function block( array $block, CheckoutLayout $layout, array $state, array $posted, array $errors ): string {
		$title = (string) ( $block['title'] ?? '' );
		switch ( $block['id'] ) {
			case 'billing':
				return $this->address( 'billing', $title !== '' ? $title : __( 'Billing details', 'flexacommerce' ), $state, $posted, $errors );
			case 'shipping':
				if ( empty( $state['needs_address'] ) ) {
					return '';
				}
				return sprintf(
					'<p class="fxc-checkout__toggle"><label><input type="checkbox" name="ship_to_different_address" value="1" aria-controls="fxc-shipping-address" data-fxc-ship-toggle%1$s> %2$s</label></p>',
					! empty( $posted['ship_to_different_address'] ) ? ' checked' : '',
					esc_html__( 'Ship to a different address', 'flexacommerce' )
				)
					. '<div class="fxc-checkout__shipping-address" id="fxc-shipping-address" data-fxc-shipping-address>'
					. $this->address( 'shipping', $title !== '' ? $title : __( 'Shipping address', 'flexacommerce' ), $state, $posted, $errors )
					. '</div>';
			case 'additional':
				return $this->additional( $state, $posted, $errors, $title );
			case 'shipping_method':
				return $this->rates( $state, $errors, $title );
			case 'summary':
				$table = $this->review( $state, $title, $layout->takes_coupons() ) . ( $layout->takes_coupons() ? self::coupon_box() : '' );
				if ( $layout->summary_position() !== CheckoutLayout::SUMMARY_TOP ) {
					return $table;
				}
				// Phones show it folded at the top; checkout.js closes it there and opens it on wider screens.
				return '<details class="fxc-checkout__fold" open data-fxc-fold><summary>'
					. esc_html( $title !== '' ? $title : __( 'Order summary', 'flexacommerce' ) )
					. ' <strong data-fxc-fold-total>' . esc_html( (string) ( $state['totals']['total']['formatted'] ?? '' ) ) . '</strong></summary>'
					. $table . '</details>';
			case 'payment':
				return $this->methods( $state, $posted, $errors, $title )
					. $this->terms( $state, $posted, $errors )
					. '<p class="fxc-checkout__actions">'
					. '<button type="submit" class="wp-element-button fxc-checkout__place" data-fxc-place' . ( ! empty( $state['preview'] ) ? ' disabled' : '' ) . '>' . esc_html__( 'Place order', 'flexacommerce' ) . '</button>'
					. '</p><p class="fxc-checkout__message" role="status" aria-live="polite" data-fxc-message></p>';
		}
		$text = (string) ( $block['text'] ?? '' );
		if ( ( $block['type'] ?? '' ) === 'heading' ) {
			return '<h2 class="fxc-checkout__heading">' . esc_html( $text ) . '</h2>';
		}
		return '<div class="fxc-checkout__text">' . wp_kses(
			wpautop( $text ),
			CheckoutLayout::PARAGRAPH_TAGS + [
				'p'  => [],
				'br' => [],
			]
		) . '</div>';
	}

	/**
	 * The order notes and the merchant's own fields (ADR-031). With the
	 * notes alone, they keep the markup of before the builder.
	 *
	 * @param array<string,mixed>  $state
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	private function additional( array $state, array $posted, array $errors, string $title = '' ): string {
		$fields = FieldSchema::additional( self::facts( $state ) )->to_array();
		if ( $fields === [] ) {
			return '';
		}
		$values                  = is_array( $posted['additional'] ?? null ) ? $posted['additional'] : [];
		$values['customer_note'] = is_string( $posted['customer_note'] ?? null ) ? $posted['customer_note'] : '';
		// They follow the country the order goes to, as the shipping rates do.
		$country = ! empty( $posted['ship_to_different_address'] ) ? $this->country( 'shipping', $state, $posted ) : $this->country( 'billing', $state, $posted );
		$html    = self::fields( 'additional', $fields, $values, [], $errors, true, $country );
		if ( count( $fields ) === 1 && $fields[0]['id'] === 'customer_note' ) {
			return $html;
		}
		$shown = array_filter( $fields, static fn ( array $f ): bool => $f['countries'] === [] || in_array( $country, $f['countries'], true ) );
		return '<fieldset class="fxc-checkout__additional"' . ( $shown === [] ? ' hidden' : '' ) . '><legend>' . esc_html( $title !== '' ? $title : __( 'Additional information', 'flexacommerce' ) ) . '</legend>' . $html . '</fieldset>';
	}

	/** @param array<string,string> $errors */
	private function summary( array $errors ): string {
		if ( $errors === [] ) {
			return '<div class="fxc-checkout__errors" data-fxc-errors tabindex="-1" hidden></div>';
		}
		$items = '';
		foreach ( $errors as $path => $message ) {
			$target = self::field_id( $path );
			$items .= $target !== ''
				? '<li><a href="#' . esc_attr( $target ) . '">' . esc_html( $message ) . '</a></li>'
				: '<li>' . esc_html( $message ) . '</li>';
		}
		return '<div class="fxc-checkout__errors fxc-notice fxc-notice--error" role="alert" data-fxc-errors tabindex="-1">'
			. '<p>' . esc_html__( 'Please fix the following before placing your order:', 'flexacommerce' ) . '</p>'
			. '<ul>' . $items . '</ul></div>';
	}

	/**
	 * @param array<string,mixed>  $state
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	private function address( string $type, string $legend, array $state, array $posted, array $errors ): string {
		$values  = is_array( $posted[ $type ] ?? null ) ? $posted[ $type ] : $this->defaults( $state, $type );
		$country = $this->country( $type, $state, $posted );
		$fields  = FieldSchema::for_address( $type, $country, self::facts( $state ) )->to_array();
		$html    = '<fieldset class="fxc-checkout__address" data-fxc-address="' . esc_attr( $type ) . '"><legend>' . esc_html( $legend ) . '</legend>';
		// The shipping address is hidden by CSS until its checkbox is ticked, and a
		// hidden required control would block the submit; the server checks it.
		$html .= self::fields( $type, $fields, $values, is_array( $state['countries'] ?? null ) ? $state['countries'] : [], $errors, $type !== 'shipping', $country );
		if ( $type === 'billing' ) {
			$html .= '<p class="fxc-checkout__refresh"><button type="submit" name="fxc_refresh" value="1" formnovalidate class="fxc-checkout__refresh-button" data-fxc-refresh>' . esc_html__( 'Update shipping and totals', 'flexacommerce' ) . '</button></p>';
		}
		return $html . '</fieldset>';
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $posted
	 */
	private function country( string $type, array $state, array $posted ): string {
		$values = is_array( $posted[ $type ] ?? null ) ? $posted[ $type ] : $this->defaults( $state, $type );
		return is_string( $values['country'] ?? null ) && $values['country'] !== '' ? strtoupper( $values['country'] ) : (string) ( $state['destination']['country'] ?? '' );
	}

	/** @param array<string,mixed> $state */
	private static function facts( array $state ): ?CartFacts {
		return is_array( $state['cart_facts'] ?? null ) ? CartFacts::from_array( $state['cart_facts'] ) : null;
	}

	/**
	 * The controls of one address, shared with the account page. With
	 * `$native_required` off, required controls get `aria-required` only.
	 * A field for other countries than `$country` is printed hidden and
	 * disabled, for checkout.js to show when the customer picks one of them.
	 *
	 * @param list<array<string,mixed>> $fields    FieldSchema::to_array()
	 * @param array<mixed>              $values    field id => value
	 * @param array<string,string>      $countries code => name
	 * @param array<string,string>      $errors
	 */
	public static function fields( string $type, array $fields, array $values, array $countries, array $errors, bool $native_required = true, string $country = '' ): string {
		$html = '';
		foreach ( $fields as $field ) {
			$html .= self::field( $type, $field, is_scalar( $values[ $field['id'] ] ?? null ) ? (string) $values[ $field['id'] ] : '', $countries, $errors, $native_required, $country );
		}
		return $html;
	}

	/**
	 * @param array<string,mixed>  $field CheckoutField::to_array()
	 * @param array<string,string> $countries
	 * @param array<string,string> $errors
	 */
	private static function field( string $type, array $field, string $value, array $countries, array $errors, bool $native_required, string $country = '' ): string {
		$id       = (string) $field['id'];
		$note     = $type === 'additional' && $id === 'customer_note';
		$path     = $note ? 'customer_note' : $type . '.' . $id;
		$dom_id   = self::field_id( $path );
		$name     = $note ? 'customer_note' : $type . '[' . $id . ']';
		$kind     = (string) $field['type'];
		$required = ! empty( $field['required'] );
		$help     = (string) ( $field['help'] ?? '' );
		$help_id  = $help !== '' ? $dom_id . '-help' : '';
		$invalid  = isset( $errors[ $path ] );
		$describe = trim( $help_id . ' ' . ( $invalid ? self::error_id( $path ) : '' ) );
		$only     = is_array( $field['countries'] ?? null ) ? $field['countries'] : [];
		$off      = $only !== [] && ! in_array( $country, $only, true );
		$attrs    = [
			'id'               => $dom_id,
			'name'             => $name,
			'disabled'         => $off,
			'required'         => $required && $native_required,
			'aria-required'    => $required && ! $native_required ? 'true' : null,
			'autocomplete'     => (string) $field['autocomplete'] !== '' ? (string) $field['autocomplete'] : null,
			'placeholder'      => (string) ( $field['placeholder'] ?? '' ) !== '' ? (string) $field['placeholder'] : null,
			'data-fxc-key'     => $id,
			'aria-invalid'     => $invalid ? 'true' : null,
			'aria-describedby' => $describe !== '' ? $describe : null,
		];
		$mark     = $required ? ' <abbr class="fxc-required" title="' . esc_attr__( 'required', 'flexacommerce' ) . '">*</abbr>' : '';
		$label    = sprintf( '<label for="%1$s">%2$s%3$s</label>', esc_attr( $dom_id ), esc_html( (string) $field['label'] ), $mark );
		$after    = ( $help !== '' ? '<span class="fxc-field__help" id="' . esc_attr( $help_id ) . '">' . esc_html( $help ) . '</span>' : '' ) . self::error_for( $path, $errors );
		$class    = 'fxc-field fxc-field--' . ( $note ? 'note' : $id ) . ( ( $field['width'] ?? '' ) === 'half' ? ' fxc-field--half' : '' );
		$wrap     = $only !== [] ? ' data-fxc-countries="' . esc_attr( implode( ' ', $only ) ) . '"' . ( $off ? ' hidden' : '' ) : '';

		$options = [];
		if ( $kind === 'country' ) {
			$options = $countries;
		} elseif ( in_array( $kind, [ 'state', 'select', 'radio' ], true ) ) {
			$options = is_array( $field['options'] ) ? $field['options'] : [];
		}

		if ( $kind === 'checkbox' ) {
			$control = '<input type="checkbox" value="1"' . Component::attributes( $attrs ) . ( $value !== '' && $value !== '0' ? ' checked' : '' ) . '>';
			return '<p class="' . esc_attr( str_replace( 'fxc-field--' . $id, 'fxc-field--checkbox', $class ) ) . '"' . $wrap . '>' . $control . ' ' . $label . $after . '</p>';
		}
		if ( $kind === 'radio' ) {
			// The group carries the id, so an error links to it; each choice is required natively.
			$group   = Component::attributes(
				[
					'id'               => $dom_id,
					'role'             => $required && ! $native_required ? 'radiogroup' : null,
					'aria-required'    => $attrs['aria-required'],
					'data-fxc-key'     => $id,
					'aria-describedby' => $attrs['aria-describedby'],
					'disabled'         => $off,
				]
			);
			$choices = '';
			$i       = 0;
			foreach ( $options as $code => $text ) {
				$choice   = $dom_id . '-' . ( ++$i );
				$choices .= sprintf(
					'<span class="fxc-field__choice"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s> <label for="%1$s">%6$s</label></span>',
					esc_attr( $choice ),
					esc_attr( $name ),
					esc_attr( (string) $code ),
					$required && $native_required ? ' required' : '',
					checked( (string) $code, $value, false ),
					esc_html( (string) $text )
				);
			}
			return '<fieldset class="' . esc_attr( $class . ' fxc-field--radio' ) . '"' . $group . $wrap . '><legend>' . esc_html( (string) $field['label'] ) . $mark . '</legend>' . $choices . $after . '</fieldset>';
		}
		if ( $options !== [] ) {
			$control = '<select' . Component::attributes( array_diff_key( $attrs, [ 'placeholder' => true ] ) ) . '>';
			if ( $kind !== 'country' ) {
				$control .= '<option value="">' . esc_html__( 'Select an option', 'flexacommerce' ) . '</option>';
			}
			foreach ( $options as $code => $text ) {
				$control .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $code ), selected( (string) $code, $value, false ), esc_html( (string) $text ) );
			}
			$control .= '</select>';
		} elseif ( $kind === 'textarea' ) {
			$control = sprintf( '<textarea%1$s rows="3" maxlength="%2$d">%3$s</textarea>', Component::attributes( $attrs ), (int) $field['max_length'], esc_textarea( $value ) );
		} else {
			$input = match ( $kind ) {
				'email', 'tel', 'date', 'number' => $kind,
				default => 'text',
			};
			$length  = in_array( $input, [ 'date', 'number' ], true ) ? '' : sprintf( ' maxlength="%d"', (int) $field['max_length'] );
			$control = sprintf(
				'<input type="%1$s" value="%2$s"%3$s%4$s%5$s>',
				$input,
				esc_attr( $value ),
				$length,
				$input === 'number' ? ' step="any"' : '',
				Component::attributes( $attrs )
			);
		}
		return '<p class="' . esc_attr( $class ) . '"' . $wrap . '>' . $label . $control . $after . '</p>';
	}

	/**
	 * @param array<string,mixed>  $state
	 * @param array<string,string> $errors
	 */
	private function rates( array $state, array $errors, string $title = '' ): string {
		if ( empty( $state['needs_shipping'] ) ) {
			return '';
		}
		$rates = is_array( $state['shipping_rates'] ?? null ) ? $state['shipping_rates'] : [];
		$html  = '<fieldset class="fxc-checkout__rates" id="fxc-shipping-rate"' . self::described( [ 'shipping_rate' ], $errors ) . ' data-fxc-rates><legend>' . esc_html( $title !== '' ? $title : __( 'Shipping', 'flexacommerce' ) ) . '</legend>';
		if ( $rates === [] ) {
			$html .= '<p>' . esc_html__( 'There are no shipping options for this address. Check the address or contact us.', 'flexacommerce' ) . '</p>';
		}
		foreach ( $rates as $rate ) {
			$html .= sprintf(
				'<p class="fxc-choice"><label><input type="radio" name="shipping_rate" value="%1$s" data-fxc-rate%2$s> <span>%3$s</span> <strong>%4$s</strong></label></p>',
				esc_attr( (string) $rate['id'] ),
				checked( (string) $rate['id'], (string) $state['shipping_rate'], false ),
				esc_html( (string) $rate['label'] ),
				esc_html( (string) $rate['cost']['formatted'] )
			);
		}
		return $html . $this->error( 'shipping_rate', $errors ) . '</fieldset>';
	}

	/** @param array<string,mixed> $state */
	private function review( array $state, string $title = '', bool $removable = false ): string {
		$html = '<table class="fxc-checkout__summary"><caption>' . esc_html( $title !== '' ? $title : __( 'Your order', 'flexacommerce' ) ) . '</caption><tbody>';
		foreach ( (array) ( $state['cart']['items'] ?? [] ) as $item ) {
			$attributes = '';
			foreach ( (array) $item['attributes'] as $label ) {
				$attributes .= '<li>' . esc_html( $label['name'] . ': ' . $label['value'] ) . '</li>';
			}
			$html .= sprintf(
				'<tr><th scope="row">%1$s <span class="fxc-checkout__qty">&times;&nbsp;%2$d</span>%3$s</th><td>%4$s</td></tr>',
				esc_html( (string) $item['name'] ),
				(int) $item['quantity'],
				$attributes !== '' ? '<ul class="fxc-cart__attributes">' . $attributes . '</ul>' : '',
				esc_html( (string) ( $item['total']['formatted'] ?? '' ) )
			);
		}
		$html  .= '</tbody><tfoot>';
		$totals = (array) $state['totals'];
		$tax    = (array) ( $state['tax'] ?? [] );
		$rows   = [ 'subtotal' => __( 'Subtotal', 'flexacommerce' ) ];
		if ( ! empty( $state['needs_shipping'] ) ) {
			$rows['shipping'] = __( 'Shipping', 'flexacommerce' );
		}
		if ( empty( $tax['enabled'] ) && (int) ( $totals['tax']['amount'] ?? 0 ) > 0 ) {
			$rows['tax'] = __( 'Tax', 'flexacommerce' );
		}
		$rows['total'] = __( 'Total', 'flexacommerce' );
		foreach ( $rows as $key => $label ) {
			if ( $key === 'total' && empty( $tax['included'] ) ) {
				$html .= self::tax_rows( $tax );
			}
			$html .= sprintf(
				'<tr class="fxc-checkout__%1$s"><th scope="row">%2$s</th><td data-fxc-total="%1$s">%3$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_html( (string) ( $totals[ $key ]['formatted'] ?? '' ) )
			);
			if ( $key === 'subtotal' ) {
				$html .= self::coupon_rows( (array) ( $state['coupons'] ?? [] ), $removable );
			}
		}
		if ( ! empty( $tax['included'] ) ) {
			$html .= self::tax_rows( $tax );
		}
		return $html . '</tfoot></table>';
	}

	/**
	 * The coupon code box under the summary (doc 11 §13), closed until the
	 * shopper asks for it. Without JavaScript it posts the coupon form and
	 * the page comes back open with the result; checkout.js applies the
	 * code over REST and requotes.
	 */
	private static function coupon_box(): string {
		$notice = FormHandler::notice_for( 0 );
		return '<details class="fxc-checkout__coupons" data-fxc-coupons' . ( $notice !== '' ? ' open' : '' ) . '>'
			. '<summary>' . esc_html__( 'Have a coupon code?', 'flexacommerce' ) . '</summary>'
			. '<p class="fxc-checkout__coupon-field">'
			. '<label for="fxc-checkout-coupon-code">' . esc_html__( 'Coupon code', 'flexacommerce' ) . '</label> '
			. '<input id="fxc-checkout-coupon-code" type="text" name="coupon_code" form="' . self::COUPON_FORM . '" maxlength="' . Coupon::MAX_CODE_LENGTH . '" autocomplete="off" aria-describedby="fxc-checkout-coupon-message" data-fxc-coupon-code> '
			. '<button type="submit" class="wp-element-button" form="' . self::COUPON_FORM . '">' . esc_html__( 'Apply', 'flexacommerce' ) . '</button>'
			. '</p>'
			. '<div class="fxc-checkout__coupon-message" id="fxc-checkout-coupon-message" data-fxc-coupon-message>' . $notice . '</div>'
			. '</details>';
	}

	/**
	 * One row per coupon, after the subtotal. checkout.js rebuilds the rows
	 * after a requote, since the shown discount follows the tax display of
	 * the address. With the coupon box a code the shopper entered has a
	 * remove button that posts the coupon form; an automatic discount has
	 * none.
	 *
	 * @param array<int,mixed> $coupons the `coupons` entry of the checkout state
	 */
	public static function coupon_rows( array $coupons, bool $removable = false ): string {
		$html = '';
		foreach ( $coupons as $coupon ) {
			if ( ! is_array( $coupon ) || empty( $coupon['valid'] ) ) {
				continue;
			}
			$code   = (string) ( $coupon['code'] ?? '' );
			$remove = $removable && empty( $coupon['automatic'] ) && $code !== ''
				? sprintf(
					' <button type="submit" class="fxc-checkout__remove-coupon" form="%1$s" name="remove_coupon" value="%2$s" aria-label="%3$s">&times;</button>',
					self::COUPON_FORM,
					esc_attr( $code ),
					/* translators: %s: coupon code */
					esc_attr( sprintf( __( 'Remove coupon %s', 'flexacommerce' ), $code ) )
				)
				: '';
			$html .= sprintf(
				'<tr class="fxc-checkout__coupon" data-fxc-coupon-row><th scope="row">%1$s%2$s</th><td>%3$s</td></tr>',
				empty( $coupon['automatic'] )
					/* translators: %s: coupon code */
					? esc_html( sprintf( __( 'Coupon: %s', 'flexacommerce' ), (string) $coupon['label'] ) )
					/* translators: %s: the name of an automatic discount */
					: esc_html( sprintf( __( 'Discount: %s', 'flexacommerce' ), (string) $coupon['label'] ) ),
				$remove,
				esc_html( self::coupon_amount( $coupon ) )
			);
		}
		return $html;
	}

	/**
	 * What an applied coupon row shows: the discount, or "Free shipping" for
	 * a coupon that takes nothing else off.
	 *
	 * @param array<mixed> $coupon a CartPresenter coupon row
	 */
	public static function coupon_amount( array $coupon ): string {
		$discount = is_array( $coupon['discount'] ?? null ) ? $coupon['discount'] : [];
		if ( ! empty( $coupon['free_shipping'] ) && (int) ( $discount['amount'] ?? 0 ) === 0 ) {
			return __( 'Free shipping', 'flexacommerce' );
		}
		return '-' . (string) ( $discount['formatted'] ?? '' );
	}

	/**
	 * One row per tax line (doc 02 §7.12.5). With tax-inclusive display the
	 * rows follow the total and read "Includes". checkout.js rebuilds them
	 * from the same `tax` view after a requote.
	 *
	 * @param array<string,mixed> $tax the `tax` entry of the checkout state
	 */
	public static function tax_rows( array $tax ): string {
		if ( empty( $tax['enabled'] ) ) {
			return '';
		}
		$html = '';
		foreach ( (array) ( $tax['lines'] ?? [] ) as $line ) {
			$amount = (string) ( $line['amount']['formatted'] ?? '' );
			$label  = (string) ( $line['label'] ?? '' );
			$html  .= ! empty( $tax['included'] )
				? sprintf(
					'<tr class="fxc-checkout__tax fxc-checkout__tax--included" data-fxc-tax-row><th scope="row"></th><td>%s</td></tr>',
					/* translators: 1: tax amount, 2: tax label such as "CA State Tax". */
					esc_html( sprintf( __( 'Includes %1$s %2$s', 'flexacommerce' ), $amount, $label ) )
				)
				: sprintf(
					'<tr class="fxc-checkout__tax" data-fxc-tax-row><th scope="row">%1$s</th><td>%2$s</td></tr>',
					esc_html( $label ),
					esc_html( $amount )
				);
		}
		return $html;
	}

	/**
	 * @param array<string,mixed>  $state
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	private function methods( array $state, array $posted, array $errors, string $title = '' ): string {
		$methods = is_array( $state['payment_methods'] ?? null ) ? $state['payment_methods'] : [];
		if ( $methods === [] && (int) ( $state['totals']['total']['amount'] ?? 0 ) === 0 ) {
			return '<div data-fxc-methods></div>';
		}
		$chosen = is_string( $posted['payment_method'] ?? null ) ? $posted['payment_method'] : (string) ( $state['payment_method'] ?? '' );
		if ( ! in_array( $chosen, array_column( $methods, 'id' ), true ) ) {
			$chosen = (string) ( $methods[0]['id'] ?? '' );
		}
		$input = is_array( $posted['gateway_input'] ?? null ) ? $posted['gateway_input'] : [];
		return '<div data-fxc-methods>' . self::methods_html( $methods, $chosen, $input, $errors, $title ) . '</div>';
	}

	/**
	 * The payment method radios with each gateway's fields, shared with the
	 * order-pay page. Gateway fields are named `gateway_input[{gateway}][{field}]`.
	 *
	 * @param list<array<string,mixed>> $methods
	 * @param array<array-key,mixed>    $input
	 * @param array<string,string>      $errors
	 */
	public static function methods_html( array $methods, string $chosen, array $input, array $errors, string $legend = '' ): string {
		$html = '<fieldset class="fxc-checkout__methods" id="fxc-payment-method"' . self::described( array_merge( [ 'payment_method' ], array_filter( array_keys( $errors ), static fn ( string $path ): bool => str_starts_with( $path, 'gateway.' ) ) ), $errors ) . '><legend>' . esc_html( $legend !== '' ? $legend : __( 'Payment', 'flexacommerce' ) ) . '</legend>';
		if ( $methods === [] ) {
			$html .= '<p>' . esc_html__( 'No payment methods are available for this order. Contact us to complete it.', 'flexacommerce' ) . '</p>';
		}
		foreach ( $methods as $method ) {
			$id     = (string) $method['id'];
			$own    = is_array( $input[ $id ] ?? null ) ? $input[ $id ] : [];
			$fields = '';
			foreach ( (array) $method['fields'] as $field ) {
				$fields .= self::gateway_field( $id, $field, $own );
			}
			$html .= sprintf(
				'<div class="fxc-method" data-fxc-method="%1$s"%2$s><p class="fxc-choice"><label><input type="radio" name="payment_method" value="%1$s"%3$s> %4$s</label></p>%5$s%6$s</div>',
				esc_attr( $id ),
				is_string( $method['script_module'] ?? null ) && $method['script_module'] !== '' ? ' data-fxc-module="' . esc_attr( $method['script_module'] ) . '"' : '',
				checked( $id, $chosen, false ),
				esc_html( (string) $method['title'] ),
				(string) $method['description'] !== '' ? '<div class="fxc-method__description">' . wp_kses_post( wpautop( (string) $method['description'] ) ) . '</div>' : '',
				$fields !== '' ? '<div class="fxc-method__fields">' . $fields . '</div>' : ''
			);
		}
		$gateway = '';
		foreach ( $errors as $path => $message ) {
			if ( str_starts_with( $path, 'gateway.' ) ) {
				$gateway .= '<span class="fxc-field__error" id="' . esc_attr( self::error_id( $path ) ) . '">' . esc_html( $message ) . '</span>';
			}
		}
		return $html . self::error_for( 'payment_method', $errors ) . $gateway . '</fieldset>';
	}

	/**
	 * @param array<string,mixed>    $field SettingField::to_array()
	 * @param array<array-key,mixed> $input
	 */
	private static function gateway_field( string $gateway, array $field, array $input ): string {
		$id    = 'fxc-gateway-' . $gateway . '-' . $field['id'];
		$name  = 'gateway_input[' . $gateway . '][' . $field['id'] . ']';
		$value = is_scalar( $input[ $field['id'] ] ?? null ) ? (string) $input[ $field['id'] ] : ( is_scalar( $field['default'] ) ? (string) $field['default'] : '' );
		$label = '<label for="' . esc_attr( $id ) . '">' . esc_html( (string) $field['label'] ) . '</label>';
		$help  = (string) $field['description'] !== '' ? '<span class="fxc-field__help">' . esc_html( (string) $field['description'] ) . '</span>' : '';

		switch ( $field['type'] ) {
			case 'checkbox':
				return sprintf( '<p class="fxc-field fxc-field--checkbox"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s%5$s</p>', esc_attr( $id ), esc_attr( $name ), $value !== '' && $value !== '0' ? ' checked' : '', $label, $help );
			case 'textarea':
				return sprintf( '<p class="fxc-field">%1$s<textarea id="%2$s" name="%3$s" rows="3">%4$s</textarea>%5$s</p>', $label, esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ), $help );
			case 'select':
				$options = '';
				foreach ( (array) $field['options'] as $code => $text ) {
					$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $code ), selected( (string) $code, $value, false ), esc_html( (string) $text ) );
				}
				return sprintf( '<p class="fxc-field">%1$s<select id="%2$s" name="%3$s">%4$s</select>%5$s</p>', $label, esc_attr( $id ), esc_attr( $name ), $options, $help );
		}
		$type = match ( $field['type'] ) {
			'secret' => 'password',
			'number', 'money' => 'number',
			default => 'text',
		};
		return sprintf(
			'<p class="fxc-field">%1$s<input type="%2$s" id="%3$s" name="%4$s" value="%5$s" autocomplete="off">%6$s</p>',
			$label,
			$type,
			esc_attr( $id ),
			esc_attr( $name ),
			$type === 'password' ? '' : esc_attr( $value ),
			$help
		);
	}

	/**
	 * @param array<string,mixed>  $state
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	private function terms( array $state, array $posted, array $errors ): string {
		$url = (string) ( $state['terms_url'] ?? '' );
		if ( $url === '' ) {
			return '';
		}
		return sprintf(
			'<p class="fxc-field fxc-field--checkbox fxc-checkout__terms"><input type="checkbox" id="fxc-terms" name="terms" value="1" required%1$s%2$s> <label for="fxc-terms">%3$s</label>%4$s</p>',
			! empty( $posted['terms'] ) ? ' checked' : '',
			$this->invalid( 'terms', $errors ),
			sprintf(
				/* translators: %s: link to the terms and conditions page */
				esc_html__( 'I have read and agree to the %s.', 'flexacommerce' ),
				'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'terms and conditions', 'flexacommerce' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'flexacommerce' ) . '</span></a>'
			),
			$this->error( 'terms', $errors )
		);
	}

	/**
	 * Address values before anything was posted: the account's saved
	 * address, else the cart's destination and the account's name and email.
	 *
	 * @param array<string,mixed> $state
	 * @return array<string,string>
	 */
	private function defaults( array $state, string $type ): array {
		$saved = is_array( $state['saved'][ $type ] ?? null ) ? $state['saved'][ $type ] : null;
		if ( $saved !== null ) {
			return array_map( 'strval', array_filter( $saved, 'is_scalar' ) );
		}
		$destination = is_array( $state['destination'] ?? null ) ? $state['destination'] : [];
		$values      = [
			'country'  => (string) ( $destination['country'] ?? '' ),
			'state'    => (string) ( $destination['state'] ?? '' ),
			'postcode' => (string) ( $destination['postcode'] ?? '' ),
			'city'     => (string) ( $destination['city'] ?? '' ),
		];
		$user        = wp_get_current_user();
		if ( $user->exists() ) {
			$values['email']      = $user->user_email;
			$values['first_name'] = $user->first_name;
			$values['last_name']  = $user->last_name;
		}
		return $values;
	}

	/**
	 * Read by checkout.js.
	 *
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function config( array $state ): array {
		return [
			'countries' => $state['countries'] ?? [],
			'states'    => $state['states'] ?? [],
			'i18n'      => [
				'placing'       => __( 'Placing your order…', 'flexacommerce' ),
				'failed'        => __( 'Could not place the order. Please try again.', 'flexacommerce' ),
				'changed'       => __( 'Your order total changed. Review the new total and place the order again.', 'flexacommerce' ),
				/* translators: 1: tax amount, 2: tax label such as "CA State Tax". */
				'includes'      => __( 'Includes %1$s %2$s', 'flexacommerce' ),
				/* translators: %s: coupon code */
				'coupon'        => __( 'Coupon: %s', 'flexacommerce' ),
				/* translators: %s: the name of an automatic discount */
				'discount'      => __( 'Discount: %s', 'flexacommerce' ),
				'freeShip'      => __( 'Free shipping', 'flexacommerce' ),
				'summary'       => __( 'Please fix the following before placing your order:', 'flexacommerce' ),
				'noRates'       => __( 'There are no shipping options for this address. Check the address or contact us.', 'flexacommerce' ),
				'select'        => __( 'Select an option', 'flexacommerce' ),
				'required'      => __( 'required', 'flexacommerce' ),
				/* translators: %s: the name of the next step, such as "Shipping" */
				'next'          => __( 'Continue to %s', 'flexacommerce' ),
				/* translators: %s: the name of the step before, such as "Information" */
				'back'          => __( 'Back to %s', 'flexacommerce' ),
				'contact'       => __( 'Contact', 'flexacommerce' ),
				'shipTo'        => __( 'Ship to', 'flexacommerce' ),
				'method'        => __( 'Shipping method', 'flexacommerce' ),
				'change'        => __( 'Change', 'flexacommerce' ),
				/* translators: %s: what the button changes, such as "Contact" */
				'changeIt'      => __( 'Change %s', 'flexacommerce' ),
				'couponEmpty'   => __( 'Enter a coupon code.', 'flexacommerce' ),
				'couponApplied' => __( 'Coupon applied.', 'flexacommerce' ),
				'couponRemoved' => __( 'Coupon removed.', 'flexacommerce' ),
				/* translators: %s: coupon code */
				'removeCoupon'  => __( 'Remove coupon %s', 'flexacommerce' ),
			],
		];
	}

	/** @param array<string,string> $errors */
	private function invalid( string $path, array $errors ): string {
		return self::invalid_for( $path, $errors );
	}

	/** @param array<string,string> $errors */
	private static function invalid_for( string $path, array $errors ): string {
		return isset( $errors[ $path ] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( self::error_id( $path ) ) . '"' : '';
	}

	/**
	 * `aria-describedby` for a fieldset (shipping, payment): a group takes
	 * no `aria-invalid`, so its errors are only described.
	 *
	 * @param list<string>         $paths
	 * @param array<string,string> $errors
	 */
	private static function described( array $paths, array $errors ): string {
		$ids = [];
		foreach ( $paths as $path ) {
			if ( isset( $errors[ $path ] ) ) {
				$ids[] = self::error_id( $path );
			}
		}
		return $ids === [] ? '' : ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"';
	}

	/** DOM id of the message for an error path. Gateway errors share a fieldset, so they keep their own key. */
	private static function error_id( string $path ): string {
		return str_starts_with( $path, 'gateway.' )
			? 'fxc-gateway-error-' . sanitize_html_class( str_replace( '.', '-', substr( $path, 8 ) ) )
			: self::field_id( $path ) . '-error';
	}

	/** @param array<string,string> $errors */
	private function error( string $path, array $errors ): string {
		return self::error_for( $path, $errors );
	}

	/** @param array<string,string> $errors */
	private static function error_for( string $path, array $errors ): string {
		if ( ! isset( $errors[ $path ] ) ) {
			return '';
		}
		return '<span class="fxc-field__error" id="' . esc_attr( self::error_id( $path ) ) . '">' . esc_html( $errors[ $path ] ) . '</span>';
	}

	/** DOM id of the control for an error path; '' for errors without a field (cart). */
	public static function field_id( string $path ): string {
		if ( preg_match( '/^(billing|shipping|additional)\.([a-z0-9_]+)$/', $path, $m ) === 1 ) {
			return 'fxc-' . $m[1] . '-' . $m[2];
		}
		return match ( $path ) {
			'shipping_rate' => 'fxc-shipping-rate',
			'payment_method' => 'fxc-payment-method',
			'terms' => 'fxc-terms',
			'customer_note' => 'fxc-customer-note',
			default => str_starts_with( $path, 'gateway.' ) ? 'fxc-payment-method' : '',
		};
	}
}
