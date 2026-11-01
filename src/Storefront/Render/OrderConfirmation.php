<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Checkout\CheckoutService;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Notifications\OrderData;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderFields;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Plugin;
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

		$html .= self::invoice( $order );
		$html .= self::downloads( $order );
		$html .= self::shipments( $order );
		$html .= $this->items( $order );
		$html .= self::fields( $order );

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
		$fees     = [];
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
			if ( $item->type === OrderItem::FEE ) {
				$fees[] = [ $item->name !== '' ? $item->name : __( 'Fee', 'flexacommerce' ), $item->total ];
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
			if ( $item->is_bundle_part() ) {
				$html .= sprintf(
					'<tr class="fxc-order__part"><td>%1$s <span class="fxc-checkout__qty">&times;&nbsp;%2$d</span>%3$s</td><td></td></tr>',
					esc_html( $item->name ),
					$item->quantity,
					$attributes !== '' ? '<ul class="fxc-cart__attributes">' . $attributes . '</ul>' : ''
				);
				continue;
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
		array_push( $rows, ...$fees );
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

	/**
	 * The customer's invoice link when the invoice settings allow it
	 * (ADR-060), and a credit note link per refund (ADR-064).
	 */
	public static function invoice( Order $order ): string {
		$invoices = Plugin::instance()->invoices();
		if ( ! $invoices->customer_can( $order ) ) {
			return '';
		}
		$html  = '<p class="fxc-order__invoice"><a href="' . esc_url( $invoices->customer_url( $order ) ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Download invoice (PDF)', 'flexacommerce' ) . '</a></p>';
		$notes = $order->amount_refunded > 0 ? $invoices->credit_notes( $order->id ) : [];
		foreach ( $order->amount_refunded > 0 ? $invoices->refunds( $order ) : [] as $refund ) {
			if ( ! $invoices->customer_can_credit( $order, $refund->id ) ) {
				continue;
			}
			$label = isset( $notes[ $refund->id ] )
				/* translators: %s: credit note number */
				? sprintf( __( 'Download credit note %s (PDF)', 'flexacommerce' ), $notes[ $refund->id ]->number )
				/* translators: %s: refunded amount */
				: sprintf( __( 'Download the credit note for the %s refund (PDF)', 'flexacommerce' ), html_entity_decode( Plugin::instance()->services()->money()->format( $order->money( $refund->amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$html .= '<p class="fxc-order__invoice"><a href="' . esc_url( $invoices->customer_url( $order, $refund->id ) ) . '" target="_blank" rel="noopener">'
				. esc_html( $label ) . '</a></p>';
		}
		return $html;
	}

	/**
	 * The download links of a paid order (ADR-058). Orders without a
	 * downloadable line skip the query.
	 */
	public static function downloads( Order $order ): string {
		$has = false;
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			$has = $has || ! empty( $item->meta['downloadable'] );
		}
		if ( ! $has || ! in_array( $order->status, [ OrderStatus::PROCESSING, OrderStatus::COMPLETED ], true ) ) {
			return '';
		}
		$links = Plugin::instance()->downloads()->links( $order->id );
		return $links === [] ? '' : '<section class="fxc-order__downloads"><h2>' . esc_html__( 'Downloads', 'flexacommerce' ) . '</h2>' . self::download_table( $links ) . '</section>';
	}

	/**
	 * The packages of the order with their tracking links (ADR-067). An
	 * order that was never paid has none, so it skips the query.
	 */
	public static function shipments( Order $order ): string {
		if ( in_array( $order->status, [ OrderStatus::PENDING, OrderStatus::FAILED ], true ) ) {
			return '';
		}
		$list = Plugin::instance()->orders()->shipments()->for_order( $order->id );
		if ( $list === [] ) {
			return '';
		}
		$html = '<section class="fxc-order__shipments"><h2>' . esc_html__( 'Tracking', 'flexacommerce' ) . '</h2><table class="fxc-shipments"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Shipped', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Carrier', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Tracking number', 'flexacommerce' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $list as $shipment ) {
			$row    = OrderData::shipment_row( $order, $shipment );
			$number = esc_html( $row['number'] );
			if ( $row['number'] !== '' && $row['url'] !== '' ) {
				$number = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
					esc_url( $row['url'] ),
					$number,
					esc_html__( '(opens in a new tab)', 'flexacommerce' )
				);
			}
			$items = '';
			foreach ( $row['items'] as $item ) {
				$items .= '<li>' . esc_html( $item['name'] . ' × ' . $item['quantity'] ) . '</li>';
			}
			$html .= sprintf(
				'<tr><td>%1$s</td><td>%2$s%3$s</td><td>%4$s</td></tr>',
				esc_html( $row['date'] ),
				esc_html( $row['carrier'] ),
				$items !== '' ? '<ul class="fxc-shipments__items">' . $items . '</ul>' : '',
				$number
			);
		}
		return $html . '</tbody></table></section>';
	}

	/** @param list<array{name:string,product:string,url:string,remaining:?int,expires:?string}> $links */
	public static function download_table( array $links ): string {
		$html = '<table class="fxc-downloads"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Product', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Downloads left', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Expires', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'File', 'flexacommerce' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $links as $link ) {
			$html .= sprintf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td><a href="%4$s" rel="nofollow">%5$s</a></td></tr>',
				esc_html( $link['product'] ),
				esc_html( $link['remaining'] === null ? __( 'Unlimited', 'flexacommerce' ) : number_format_i18n( $link['remaining'] ) ),
				esc_html( $link['expires'] === null ? __( 'Never', 'flexacommerce' ) : mysql2date( (string) get_option( 'date_format' ), get_date_from_gmt( $link['expires'] ) ) ),
				esc_url( $link['url'] ),
				esc_html( $link['name'] )
			);
		}
		return $html . '</tbody></table>';
	}

	/** The answers to the merchant's own checkout fields (ADR-031). */
	public static function fields( Order $order ): string {
		$answers = OrderFields::for_order( Plugin::instance()->orders()->orders(), $order->id );
		if ( $answers === [] ) {
			return '';
		}
		$html = '<section class="fxc-order__fields"><h2>' . esc_html__( 'Additional information', 'flexacommerce' ) . '</h2><dl>';
		foreach ( $answers as $answer ) {
			$html .= '<dt>' . esc_html( $answer['label'] ) . '</dt><dd>' . nl2br( esc_html( $answer['value'] ) ) . '</dd>';
		}
		return $html . '</dl></section>';
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
