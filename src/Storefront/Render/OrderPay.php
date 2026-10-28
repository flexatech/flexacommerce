<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\CheckoutPage;

defined( 'ABSPATH' ) || exit;

/**
 * The order-pay view: a pending order is paid again, possibly with another
 * method. Like the checkout form it posts to the page itself.
 */
final class OrderPay {
	public function __construct(
		private readonly CheckoutService $checkout,
		private readonly OrderConfirmation $details,
	) {}

	/**
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	public function html( Order $order, array $posted = [], array $errors = [] ): string {
		return Component::wrap(
			'order-pay',
			fn (): string => $this->markup( $order, $posted, $errors ),
			[ 'order_id' => $order->id ]
		);
	}

	/**
	 * @param array<string,mixed>  $posted
	 * @param array<string,string> $errors
	 */
	private function markup( Order $order, array $posted, array $errors ): string {
		Assets::use_checkout( [] );
		$html = '<div class="fxc-order fxc-order--pay">';
		if ( $order->status !== OrderStatus::PENDING || $order->total <= 0 ) {
			return $html . '<p class="fxc-notice" role="status">' . sprintf(
				/* translators: %s: link to the order details */
				esc_html__( 'This order does not need a payment. %s', 'flexacommerce' ),
				'<a href="' . esc_url( $this->checkout->pages()->confirmation_url( $order ) ) . '">' . esc_html__( 'View order', 'flexacommerce' ) . '</a>'
			) . '</p></div>';
		}

		$failure = isset( $errors['order'] ) || $posted !== [] ? '' : $this->checkout->last_failure( $order );
		if ( isset( $errors['order'] ) ) {
			$html .= '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html( $errors['order'] ) . '</p>';
		} elseif ( $failure !== '' ) {
			$html .= '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html(
				/* translators: %s: the reason the payment provider gave */
				sprintf( __( 'The payment did not go through: %s Try again or choose another payment method.', 'flexacommerce' ), $failure )
			) . '</p>';
		}
		$html .= $this->details->items( $order );

		$methods = $this->checkout->pay_methods( $order );
		$chosen  = is_string( $posted['payment_method'] ?? null ) ? $posted['payment_method'] : ( $order->payment_method !== '' ? $order->payment_method : (string) ( $methods[0]['id'] ?? '' ) );
		$input   = is_array( $posted['gateway_input'] ?? null ) ? $posted['gateway_input'] : [];

		$html .= '<form class="fxc-checkout__form fxc-order__pay-form" method="post" action="' . esc_url( $this->checkout->pages()->pay_url( $order ) ) . '" data-fxc-pay>';
		$html .= CheckoutPage::pay_fields();
		$html .= CheckoutForm::methods_html( $methods, $chosen, $input, $errors );
		if ( $methods !== [] ) {
			$html .= '<p class="fxc-checkout__actions"><button type="submit" class="wp-element-button">' . esc_html__( 'Pay for order', 'flexacommerce' ) . '</button></p>';
		}
		return $html . '</form></div>';
	}
}
