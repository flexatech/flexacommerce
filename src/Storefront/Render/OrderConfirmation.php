<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * The order-received view (doc 02 §7.5): number, status, payment
 * instructions, the items and the addresses. Shown to the holder of the
 * order key, the owner and staff only (OrderAccess).
 */
final class OrderConfirmation {
	public function __construct( private readonly CheckoutService $checkout ) {}

	public function html( Order $order ): string {
		return Component::wrap( 'order-confirmation', fn (): string => $this->markup( $order ), [ 'order_id' => $order->id ] );
	}

	private function markup( Order $order ): string {
		$html = '<div class="fxc-order">';
		if ( $order->status === OrderStatus::FAILED ) {
			$html .= '<p class="fxc-notice fxc-notice--error" role="alert">' . esc_html__( 'The payment for this order failed.', 'flexacommerce' ) . '</p>';
		} elseif ( $order->status === OrderStatus::PENDING && $order->total > 0 ) {
			// The gateway may still be confirming; offering a second payment here could charge twice.
			$html .= '<p class="fxc-notice" role="status">' . esc_html__( 'Thank you. We are waiting for the payment to be confirmed. Reload this page in a moment to see the new status.', 'flexacommerce' ) . '</p>';
		} else {
			$html .= '<p class="fxc-notice" role="status">' . esc_html__( 'Thank you. Your order has been received.', 'flexacommerce' ) . '</p>';
		}

		$overview = [
			__( 'Order number', 'flexacommerce' ) => $order->order_number,
			__( 'Date', 'flexacommerce' )         => mysql2date( get_option( 'date_format' ), get_date_from_gmt( $order->created_at ) ),
			__( 'Status', 'flexacommerce' )       => OrderStatus::label( $order->status ),
			__( 'Email', 'flexacommerce' )        => $order->customer_email,
			__( 'Total', 'flexacommerce' )        => $this->money( $order, $order->total ),
		];
		if ( $order->payment_method_title !== '' ) {
			$overview[ __( 'Payment method', 'flexacommerce' ) ] = $order->payment_method_title;
		}
		$html .= '<ul class="fxc-order__overview">';
		foreach ( $overview as $label => $value ) {
			$html .= '<li><span>' . esc_html( $label ) . '</span> <strong>' . esc_html( $value ) . '</strong></li>';
		}
		$html .= '</ul>';

		$instructions = $this->checkout->instructions( $order );
		if ( $instructions !== '' ) {
			$html .= '<section class="fxc-order__instructions"><h2>' . esc_html__( 'How to pay', 'flexacommerce' ) . '</h2>' . wpautop( esc_html( $instructions ) ) . '</section>';
		}

		$html .= $this->items( $order );

		$html .= '<div class="fxc-order__addresses">'
			. self::address( __( 'Billing address', 'flexacommerce' ), $order->billing )
			. self::address( __( 'Shipping address', 'flexacommerce' ), $order->shipping )
			. '</div>';
		return $html . '</div>';
	}

	public function items( Order $order ): string {
		$html     = '<table class="fxc-order__items"><caption>' . esc_html__( 'Order details', 'flexacommerce' ) . '</caption>'
			. '<thead><tr><th scope="col">' . esc_html__( 'Product', 'flexacommerce' ) . '</th><th scope="col">' . esc_html__( 'Total', 'flexacommerce' ) . '</th></tr></thead><tbody>';
		$shipping = [];
		$taxes    = [];
		$coupons  = [];
		foreach ( $order->items as $item ) {
			if ( $item->type === OrderItem::DISCOUNT ) {
				$label = ! empty( $item->meta['automatic'] )
					/* translators: %s: the name of an automatic discount */
					? sprintf( __( 'Discount: %s', 'flexacommerce' ), $item->name )
					/* translators: %s: coupon code */
					: sprintf( __( 'Coupon: %s', 'flexacommerce' ), $item->name );
				$coupons[] = [ $label, $item->total === 0 && ! empty( $item->meta['free_shipping'] ) ? __( 'Free shipping', 'flexacommerce' ) : -$item->total ];
				continue;
			}
			if ( $item->type === OrderItem::SHIPPING ) {
				$shipping[] = $item->name;
				continue;
			}
			if ( $item->type === OrderItem::TAX ) {
				$taxes[] = [ $item->name !== '' ? $item->name : __( 'Tax', 'flexacommerce' ), $item->total ];
				continue;
			}
			if ( $item->type !== OrderItem::PRODUCT ) {
				continue;
			}
			$attributes = '';
			foreach ( $item->attributes as $attribute ) {
				$attributes .= '<li>' . esc_html( $attribute['label'] . ': ' . $attribute['value'] ) . '</li>';
			}
			$html .= sprintf(
				'<tr><td>%1$s <span class="fxc-checkout__qty">&times;&nbsp;%2$d</span>%3$s</td><td>%4$s</td></tr>',
				esc_html( $item->name ),
				$item->quantity,
				$attributes !== '' ? '<ul class="fxc-cart__attributes">' . $attributes . '</ul>' : '',
				esc_html( $this->money( $order, $item->subtotal ) )
			);
		}
		$html .= '</tbody><tfoot>';
		$rows  = [ [ __( 'Subtotal', 'flexacommerce' ), $order->subtotal ], ...$coupons ];
		if ( $shipping !== [] ) {
			$rows[] = [ __( 'Shipping', 'flexacommerce' ) . ' (' . implode( ', ', $shipping ) . ')', $order->shipping_total ];
		}
		if ( $taxes !== [] ) {
			array_push( $rows, ...$taxes );
		} elseif ( $order->tax_total > 0 ) {
			$rows[] = [ __( 'Tax', 'flexacommerce' ), $order->tax_total ];
		}
		if ( $order->amount_refunded > 0 ) {
			$rows[] = [ __( 'Refunded', 'flexacommerce' ), -$order->amount_refunded ];
		}
		$rows[] = [ __( 'Total', 'flexacommerce' ), $order->total ];
		foreach ( $rows as [ $label, $amount ] ) {
			$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( is_string( $amount ) ? $amount : $this->money( $order, $amount ) ) . '</td></tr>';
		}
		return $html . '</tfoot></table>';
	}

	public static function address( string $title, ?AddressView $address ): string {
		if ( $address === null ) {
			return '';
		}
		$lines = array_filter(
			[
				trim( $address->first_name . ' ' . $address->last_name ),
				$address->company,
				$address->address_1,
				$address->address_2,
				trim( $address->city . ' ' . $address->state . ' ' . $address->postcode ),
				$address->country !== '' ? Countries::name( $address->country ) : '',
				$address->phone,
			],
			static function ( string $line ): bool {
				return $line !== '';
			}
		);
		return sprintf(
			'<section class="fxc-order__address"><h2>%s</h2><address>%s</address></section>',
			esc_html( $title ),
			implode( '<br>', array_map( 'esc_html', $lines ) )
		);
	}

	private function money( Order $order, int $amount ): string {
		return $this->checkout->format( $amount, $order->currency );
	}
}
