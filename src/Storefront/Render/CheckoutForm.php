<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Checkout\FieldSchema;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\CheckoutPage;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout form (doc 02 §7.5). It is a plain form that posts to the
 * checkout page itself, so an order can be placed without JavaScript;
 * `checkout.js` takes over the submit and requotes shipping over REST
 * when it loads. After a failed post the same request renders the form
 * again with the posted values and the errors next to their fields.
 */
final class CheckoutForm {
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

		$html .= $this->summary( $errors );
		$html .= '<form class="fxc-checkout__form" method="post" action="' . esc_url( CheckoutPage::form_action() ) . '">';
		$html .= CheckoutPage::hidden_fields( (string) $state['checkout_token'], (int) ( $state['totals']['total']['amount'] ?? 0 ) );

		$html .= '<div class="fxc-checkout__columns"><div class="fxc-checkout__details">';
		$html .= $this->address( 'billing', __( 'Billing details', 'flexacommerce' ), $state, $posted, $errors );

		$elsewhere = ! empty( $posted['ship_to_different_address'] );
		if ( ! empty( $state['needs_address'] ) ) {
			$html .= sprintf(
				'<p class="fxc-checkout__toggle"><label><input type="checkbox" name="ship_to_different_address" value="1" data-fxc-ship-toggle%1$s> %2$s</label></p>',
				$elsewhere ? ' checked' : '',
				esc_html__( 'Ship to a different address', 'flexacommerce' )
			);
			$html .= '<div class="fxc-checkout__shipping-address" data-fxc-shipping-address>'
				. $this->address( 'shipping', __( 'Shipping address', 'flexacommerce' ), $state, $posted, $errors )
				. '</div>';
		}

		$note  = is_string( $posted['customer_note'] ?? null ) ? $posted['customer_note'] : '';
		$html .= sprintf(
			'<p class="fxc-field fxc-field--note"><label for="fxc-customer-note">%1$s</label><textarea id="fxc-customer-note" name="customer_note" rows="3" maxlength="1000"%2$s>%3$s</textarea>%4$s</p>',
			esc_html__( 'Order notes (optional)', 'flexacommerce' ),
			$this->invalid( 'customer_note', $errors ),
			esc_textarea( $note ),
			$this->error( 'customer_note', $errors )
		);
		$html .= '</div><div class="fxc-checkout__review">';

		$html .= $this->rates( $state, $errors );
		$html .= $this->review( $state );
		$html .= $this->methods( $state, $posted, $errors );
		$html .= $this->terms( $state, $posted, $errors );

		$html .= '<p class="fxc-checkout__actions">'
			. '<button type="submit" class="wp-element-button fxc-checkout__place" data-fxc-place>' . esc_html__( 'Place order', 'flexacommerce' ) . '</button>'
			. '</p><p class="fxc-checkout__message" role="status" aria-live="polite" data-fxc-message></p>';
		return $html . '</div></div></form></div>';
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
		$values  = is_array( $posted[ $type ] ?? null ) ? $posted[ $type ] : $this->defaults( $state );
		$country = is_string( $values['country'] ?? null ) && $values['country'] !== '' ? strtoupper( $values['country'] ) : (string) ( $state['destination']['country'] ?? '' );
		$fields  = FieldSchema::for_address( $type, $country )->to_array();
		$html    = '<fieldset class="fxc-checkout__address" data-fxc-address="' . esc_attr( $type ) . '"><legend>' . esc_html( $legend ) . '</legend>';
		foreach ( $fields as $field ) {
			$html .= $this->field( $type, $field, is_scalar( $values[ $field['id'] ] ?? null ) ? (string) $values[ $field['id'] ] : '', $state, $errors );
		}
		if ( $type === 'billing' ) {
			$html .= '<p class="fxc-checkout__refresh"><button type="submit" name="fxc_refresh" value="1" formnovalidate class="fxc-checkout__refresh-button" data-fxc-refresh>' . esc_html__( 'Update shipping and totals', 'flexacommerce' ) . '</button></p>';
		}
		return $html . '</fieldset>';
	}

	/**
	 * @param array<string,mixed>  $field CheckoutField::to_array()
	 * @param array<string,mixed>  $state
	 * @param array<string,string> $errors
	 */
	private function field( string $type, array $field, string $value, array $state, array $errors ): string {
		$id       = (string) $field['id'];
		$path     = $type . '.' . $id;
		$dom_id   = self::field_id( $path );
		$name     = $type . '[' . $id . ']';
		$required = ! empty( $field['required'] );
		$attrs    = Component::attributes(
			[
				'id'            => $dom_id,
				'name'          => $name,
				// The shipping address is hidden by CSS until its checkbox is ticked, and a
				// hidden required control would block the submit; the server checks it.
				'required'      => $required && $type !== 'shipping',
				'aria-required' => $required && $type === 'shipping' ? 'true' : null,
				'autocomplete'  => (string) $field['autocomplete'] !== '' ? (string) $field['autocomplete'] : null,
				'data-fxc-key'  => $id,
			]
		) . $this->invalid( $path, $errors );
		$label    = sprintf(
			'<label for="%1$s">%2$s%3$s</label>',
			esc_attr( $dom_id ),
			esc_html( (string) $field['label'] ),
			$required ? ' <abbr class="fxc-required" title="' . esc_attr__( 'required', 'flexacommerce' ) . '">*</abbr>' : ''
		);

		$options = [];
		if ( $field['type'] === 'country' ) {
			$options = is_array( $state['countries'] ?? null ) ? $state['countries'] : [];
		} elseif ( in_array( $field['type'], [ 'state', 'select' ], true ) ) {
			$options = is_array( $field['options'] ) ? $field['options'] : [];
		}

		if ( $field['type'] === 'checkbox' ) {
			$control = '<input type="checkbox" value="1"' . $attrs . ( $value !== '' && $value !== '0' ? ' checked' : '' ) . '>';
			return '<p class="fxc-field fxc-field--checkbox">' . $control . ' ' . $label . $this->error( $path, $errors ) . '</p>';
		}
		if ( $options !== [] ) {
			$control = '<select' . $attrs . '>';
			if ( $field['type'] !== 'country' ) {
				$control .= '<option value="">' . esc_html__( 'Select an option', 'flexacommerce' ) . '</option>';
			}
			foreach ( $options as $code => $text ) {
				$control .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $code ), selected( (string) $code, $value, false ), esc_html( (string) $text ) );
			}
			$control .= '</select>';
		} else {
			$input = match ( $field['type'] ) {
				'email' => 'email',
				'tel' => 'tel',
				default => 'text',
			};
			$control = sprintf(
				'<input type="%1$s" value="%2$s" maxlength="%3$d"%4$s>',
				$input,
				esc_attr( $value ),
				(int) $field['max_length'],
				$attrs
			);
		}
		return '<p class="fxc-field fxc-field--' . esc_attr( $id ) . '">' . $label . $control . $this->error( $path, $errors ) . '</p>';
	}

	/**
	 * @param array<string,mixed>  $state
	 * @param array<string,string> $errors
	 */
	private function rates( array $state, array $errors ): string {
		if ( empty( $state['needs_shipping'] ) ) {
			return '';
		}
		$rates = is_array( $state['shipping_rates'] ?? null ) ? $state['shipping_rates'] : [];
		$html  = '<fieldset class="fxc-checkout__rates" id="fxc-shipping-rate" data-fxc-rates><legend>' . esc_html__( 'Shipping', 'flexacommerce' ) . '</legend>';
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
	private function review( array $state ): string {
		$html = '<table class="fxc-checkout__summary"><caption>' . esc_html__( 'Your order', 'flexacommerce' ) . '</caption><tbody>';
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
		}
		if ( ! empty( $tax['included'] ) ) {
			$html .= self::tax_rows( $tax );
		}
		return $html . '</tfoot></table>';
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
	private function methods( array $state, array $posted, array $errors ): string {
		$methods = is_array( $state['payment_methods'] ?? null ) ? $state['payment_methods'] : [];
		if ( $methods === [] && (int) ( $state['totals']['total']['amount'] ?? 0 ) === 0 ) {
			return '<div data-fxc-methods></div>';
		}
		$chosen = is_string( $posted['payment_method'] ?? null ) ? $posted['payment_method'] : (string) ( $methods[0]['id'] ?? '' );
		$input  = is_array( $posted['gateway_input'] ?? null ) ? $posted['gateway_input'] : [];
		return '<div data-fxc-methods>' . self::methods_html( $methods, $chosen, $input, $errors ) . '</div>';
	}

	/**
	 * The payment method radios with each gateway's fields, shared with the
	 * order-pay page. Gateway fields are named `gateway_input[{gateway}][{field}]`.
	 *
	 * @param list<array<string,mixed>> $methods
	 * @param array<array-key,mixed>    $input
	 * @param array<string,string>      $errors
	 */
	public static function methods_html( array $methods, string $chosen, array $input, array $errors ): string {
		$html = '<fieldset class="fxc-checkout__methods" id="fxc-payment-method"><legend>' . esc_html__( 'Payment', 'flexacommerce' ) . '</legend>';
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
				$gateway .= '<span class="fxc-field__error" role="alert">' . esc_html( $message ) . '</span>';
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
				'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'terms and conditions', 'flexacommerce' ) . '</a>'
			),
			$this->error( 'terms', $errors )
		);
	}

	/**
	 * Address values before anything was posted: the saved destination and
	 * the account's email.
	 *
	 * @param array<string,mixed> $state
	 * @return array<string,string>
	 */
	private function defaults( array $state ): array {
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
				'placing'  => __( 'Placing your order…', 'flexacommerce' ),
				'failed'   => __( 'Could not place the order. Please try again.', 'flexacommerce' ),
				'changed'  => __( 'Your order total changed. Review the new total and place the order again.', 'flexacommerce' ),
				/* translators: 1: tax amount, 2: tax label such as "CA State Tax". */
				'includes' => __( 'Includes %1$s %2$s', 'flexacommerce' ),
				'summary'  => __( 'Please fix the following before placing your order:', 'flexacommerce' ),
				'noRates'  => __( 'There are no shipping options for this address. Check the address or contact us.', 'flexacommerce' ),
				'select'   => __( 'Select an option', 'flexacommerce' ),
				'required' => __( 'required', 'flexacommerce' ),
			],
		];
	}

	/** @param array<string,string> $errors */
	private function invalid( string $path, array $errors ): string {
		return isset( $errors[ $path ] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( self::field_id( $path ) ) . '-error"' : '';
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
		return '<span class="fxc-field__error" id="' . esc_attr( self::field_id( $path ) ) . '-error">' . esc_html( $errors[ $path ] ) . '</span>';
	}

	/** DOM id of the control for an error path; '' for errors without a field (cart). */
	public static function field_id( string $path ): string {
		if ( preg_match( '/^(billing|shipping)\.([a-z0-9_]+)$/', $path, $m ) === 1 ) {
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
