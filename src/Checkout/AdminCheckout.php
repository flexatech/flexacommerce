<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\Cart;
use Flexa\Commerce\Cart\CartItem;
use Flexa\Commerce\Cart\CartPresenter;
use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Catalog\BundlePart;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\VariantRepository;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Database\Transaction as DbTransaction;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\PaymentService;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Orders the admin makes or edits (ADR-061). A draft is priced by the code
 * that prices a shopper's cart and quoted as checkout quotes it, with the
 * customer's prices, rules, coupons and taxes; the order is built from the
 * same lines. The admin may leave the address incomplete and the payment
 * method empty: the customer pays from the order's payment link.
 */
final class AdminCheckout {
	/** @param ?\Closure():CouponService $coupons */
	public function __construct(
		private readonly CartService $cart,
		private readonly CheckoutService $checkout,
		private readonly CartPresenter $presenter,
		private readonly OrderService $orders,
		private readonly PaymentService $payments,
		private readonly DbTransaction $transaction,
		private readonly MoneyFactory $money,
		private readonly VariantRepository $variants,
		private readonly ?\Closure $coupons = null,
	) {}

	/**
	 * What the editor shows for a draft: the priced lines, the coupons, the
	 * rates, the payment methods, the totals and what stops the order.
	 *
	 * @return array<string,mixed>
	 */
	public function quote( AdminDraft $draft ): array {
		[ $state, $quote, $problems ] = $this->price( $draft );
		$errors                       = $this->errors( $draft, $state, $problems );
		$rates                        = [];
		foreach ( $quote->rates as $rate ) {
			$rates[] = [
				'id'    => $rate->id,
				'label' => $rate->label,
				'cost'  => $this->presenter->money( $rate->cost ),
			];
		}
		$coupons = [];
		foreach ( $state->cart->coupons as $code ) {
			$applied = $state->discounts?->find( $code );
			if ( $applied !== null && $applied->coupon->automatic ) {
				continue;
			}
			$coupons[] = [
				'code'      => $applied->coupon->code ?? $code,
				'automatic' => false,
				'applied'   => $applied !== null,
				'amount'    => $applied !== null ? $this->presenter->money( new Money( $applied->total(), $state->subtotal->currency ) ) : null,
				'message'   => (string) ( $state->discounts->rejected[ $code ] ?? $state->discounts->waiting[ $code ] ?? '' ),
			];
		}
		foreach ( $state->discounts->applied ?? [] as $applied ) {
			if ( $applied->coupon->automatic ) {
				$coupons[] = [
					'code'      => $applied->coupon->code,
					'automatic' => true,
					'applied'   => true,
					'amount'    => $this->presenter->money( new Money( $applied->total(), $state->subtotal->currency ) ),
					'message'   => '',
				];
			}
		}
		return [
			'lines'              => array_map( fn ( CartItem $item ): array => $this->line( $state, $item ), $state->items ),
			'coupons'            => $coupons,
			'fees'               => $this->presenter->fees( $state ),
			'needs_shipping'     => $quote->needs_shipping,
			'shipping_rates'     => $rates,
			'shipping_rate'      => $quote->rate->id ?? '',
			'payment_methods'    => $this->methods(),
			'payment_method'     => $draft->payment_method,
			'prices_include_tax' => $quote->prices_include_tax,
			'totals'             => [
				'subtotal' => $this->presenter->money( $quote->items_net ),
				'discount' => $this->presenter->money( $quote->discount() ),
				'fees'     => $this->presenter->money( $state->fee_total() ),
				'shipping' => $this->presenter->money( $quote->shipping ),
				'tax'      => $this->presenter->money( $quote->tax ),
				'total'    => $this->presenter->money( $quote->total ),
			],
			'errors'             => $errors,
			'notices'            => array_values( array_unique( array_column( $state->notices, 'message' ) ) ),
		];
	}

	/**
	 * A new order from the draft, pending payment (on hold for an offline
	 * method, processing when nothing is to pay), with its stock taken and
	 * its coupons used.
	 *
	 * @throws InvalidCheckout what stops the order, by field
	 */
	public function place( AdminDraft $draft ): Order {
		[ $state, $quote, $problems ] = $this->price( $draft );
		$errors                       = $this->errors( $draft, $state, $problems );
		if ( $errors !== [] ) {
			throw new InvalidCheckout( $errors );
		}
		$gateway = $this->gateway( $draft->payment_method );
		$new     = $this->new_order( $draft, $quote, $this->payments->initial_status( $gateway, $quote->total->amount ), $gateway );
		$number  = $this->orders->next_number();
		$ctx     = TransitionContext::admin( __( 'Order created by the admin.', 'flexacommerce' ) );
		try {
			$order = $this->transaction->run(
				function () use ( $new, $number, $ctx, $state, $draft ): Order {
					$order = $this->orders->create( $new, $number, $ctx );
					if ( $state->discounts !== null && $state->discounts->applied !== [] ) {
						$this->coupons()->redeem( $state->discounts, $order->id, $draft->customer_id, $new->customer_email );
					}
					return $order;
				}
			);
		} catch ( InsufficientStock $e ) {
			throw new InvalidCheckout( [ 'lines' => $e->getMessage() ] );
		}
		return $this->orders->get( $order->id );
	}

	/**
	 * The order with the draft's lines, customer and addresses (ADR-061).
	 * Its stock and coupon uses are given back and taken again in one
	 * transaction, so the draft is priced with them free.
	 *
	 * @throws InvalidCheckout what stops the change, by field
	 * @throws \Flexa\Commerce\Contracts\Exceptions\InvalidOrderRequest the order cannot be edited
	 */
	public function edit( int $order_id, AdminDraft $draft ): Order {
		try {
			return $this->orders->edit(
				$order_id,
				function ( Order $order ) use ( $draft ): NewOrder {
					[ $state, $quote, $problems ] = $this->price( $draft );
					$errors                       = $this->errors( $draft, $state, $problems );
					if ( $errors !== [] ) {
						throw new InvalidCheckout( $errors );
					}
					$new = $this->new_order( $draft, $quote, $order->status, $this->gateway( $draft->payment_method ), $order->locale );
					if ( $state->discounts !== null && $state->discounts->applied !== [] ) {
						$this->coupons()->redeem( $state->discounts, $order->id, $draft->customer_id, $new->customer_email );
					}
					return $new;
				},
				TransitionContext::admin( __( 'Order edited by the admin.', 'flexacommerce' ) )
			);
		} catch ( InsufficientStock $e ) {
			throw new InvalidCheckout( [ 'lines' => $e->getMessage() ] );
		}
	}

	/**
	 * The editor's draft of a stored order. Lines placed before ADR-061
	 * carry no raw choices: the variation's own values stand in, and option
	 * values have to be picked again.
	 *
	 * @return array<string,mixed>
	 */
	public function draft_of( Order $order ): array {
		$lines = [];
		foreach ( $order->items_of_type( OrderItem::PRODUCT ) as $item ) {
			// The bundle line brings its parts back when the order is saved again (ADR-071).
			if ( $item->is_bundle_part() ) {
				continue;
			}
			$selection = is_array( $item->meta['selection'] ?? null ) ? $item->meta['selection'] : null;
			if ( $selection === null && $item->variant_id !== null ) {
				$selection = [];
				foreach ( $this->variants->find( $item->variant_id )?->combination->to_array() ?? [] as $key => $value ) {
					if ( $value !== Combination::WILDCARD ) {
						$selection[ $key ] = $value;
					}
				}
			}
			$lines[] = [
				'product_id' => (int) $item->product_id,
				'variant_id' => (int) $item->variant_id,
				'quantity'   => $item->quantity,
				'attributes' => (object) ( $selection ?? [] ),
				'options'    => (object) ( is_array( $item->meta['options'] ?? null ) ? $item->meta['options'] : [] ),
				'name'       => $item->name,
				'sku'        => (string) $item->sku,
				'labels'     => array_map( static fn ( array $a ): string => $a['label'] . ': ' . $a['value'], $item->attributes ),
			];
		}
		$coupons = [];
		foreach ( $order->items_of_type( OrderItem::DISCOUNT ) as $item ) {
			if ( empty( $item->meta['automatic'] ) ) {
				$coupons[] = $item->code;
			}
		}
		$shipping = $order->items_of_type( OrderItem::SHIPPING )[0] ?? null;
		$customer = $order->customer_id !== null ? get_userdata( $order->customer_id ) : false;
		return [
			'lines'          => $lines,
			'customer_id'    => (int) $order->customer_id,
			'customer'       => $customer instanceof \WP_User ? [
				'id'    => $customer->ID,
				'name'  => $customer->display_name,
				'email' => $customer->user_email,
			] : null,
			'billing'        => ( $order->billing ?? new AddressView( 'billing', email: $order->customer_email ) )->to_array(),
			'shipping'       => $order->shipping?->to_array(),
			'coupons'        => $coupons,
			'shipping_rate'  => $shipping->code ?? '',
			'payment_method' => $order->payment_method,
			'customer_note'  => $order->customer_note,
			'editable'       => $order->is_editable(),
		];
	}

	/**
	 * Enabled payment methods the admin can name on an order, whatever the
	 * cart: the customer may still pick another one when paying.
	 *
	 * @return list<array{id:string,title:string}>
	 */
	public function methods(): array {
		$registry = $this->payments->gateways();
		$registry->settings()->prime( $registry->ids() );
		$out = [];
		foreach ( $registry->ids() as $id ) {
			try {
				$gateway = $registry->get( $id );
			} catch ( \Throwable $e ) {
				unset( $e );
				continue;
			}
			if ( $gateway !== null && $registry->settings()->enabled( $gateway ) ) {
				$out[] = [
					'id'    => $id,
					'title' => $registry->title( $gateway ),
				];
			}
		}
		return $out;
	}

	/**
	 * The draft priced for its customer and quoted at its address. A rate
	 * that is not offered gives way to the first one, priced again, as at
	 * checkout; the payment method is never filled in.
	 *
	 * @return array{0:CartState,1:Quote,2:list<string>} the state, the quote and the lines that could not be added
	 */
	private function price( AdminDraft $draft ): array {
		$user_id  = $draft->customer_id > 0 && get_userdata( $draft->customer_id ) instanceof \WP_User ? $draft->customer_id : 0;
		$lines    = [];
		$problems = [];
		foreach ( $draft->lines as $i => $entry ) {
			try {
				$line = $this->cart->admin_line( $entry['product_id'], $entry['variant_id'], $entry['quantity'], $entry['attributes'], $entry['options'] );
			} catch ( CommerceException $e ) {
				$problems[] = sprintf(
					/* translators: 1: line number, 2: why the product cannot be added. */
					__( 'Line %1$d: %2$s', 'flexacommerce' ),
					$i + 1,
					$e->getMessage()
				);
				continue;
			}
			$lines[ $line->key ] = isset( $lines[ $line->key ] ) ? $lines[ $line->key ]->with_quantity( $lines[ $line->key ]->quantity + $line->quantity ) : $line;
		}
		$destination = $this->destination( $draft );
		$address     = [
			'country'  => $destination->country,
			'state'    => $destination->state,
			'postcode' => $destination->postcode,
			'city'     => $destination->city,
		];
		$cart        = new Cart( lines: array_values( $lines ), coupons: $draft->coupons );
		$billing     = $draft->billing->country !== '' ? $draft->billing : null;
		$priced      = fn ( string $rate ): CartState => $this->cart->preview( $cart->with_shipping( $address, $rate )->with_payment_method( $draft->payment_method ), $user_id, true, CartService::PLAIN );
		$state       = $priced( $draft->shipping_rate );
		$quote       = $this->checkout->quote( $state, $destination, $draft->shipping_rate, $billing, $user_id );
		$rate        = $quote->rate->id ?? '';
		if ( $rate !== $draft->shipping_rate ) {
			$state = $priced( $rate );
			$quote = $this->checkout->quote( $state, $destination, $rate, $billing, $user_id );
		}
		return [ $state, $quote, $problems ];
	}

	/** Shipped to the shipping address, else the billing one, else quoted at the store. */
	private function destination( AdminDraft $draft ): Destination {
		foreach ( [ $draft->shipping, $draft->billing ] as $address ) {
			if ( $address !== null && $address->country !== '' ) {
				return new Destination( $address->country, Countries::state_code( $address->country, $address->state ), $address->postcode, $address->city );
			}
		}
		return new Destination( Settings::string( 'store_country' ), Settings::string( 'store_state' ), Settings::string( 'store_postcode' ), Settings::string( 'store_city' ) );
	}

	/**
	 * What stops the order, by field: lines that cannot be ordered, codes
	 * that do not apply, no way to reach the customer, a payment method
	 * that is off.
	 *
	 * @param list<string> $problems
	 * @return array<string,string>
	 */
	private function errors( AdminDraft $draft, CartState $state, array $problems ): array {
		$errors = [];
		foreach ( $state->items as $item ) {
			if ( $item->status === CartItem::UNAVAILABLE ) {
				/* translators: %s: product name */
				$problems[] = sprintf( __( '%s cannot be ordered.', 'flexacommerce' ), $item->name() );
			}
		}
		if ( $problems !== [] ) {
			$errors['lines'] = implode( ' ', $problems );
		} elseif ( $state->items === [] ) {
			$errors['lines'] = __( 'Add at least one product.', 'flexacommerce' );
		}
		$refused = array_values( ( $state->discounts->rejected ?? [] ) + ( $state->discounts->waiting ?? [] ) );
		if ( $refused !== [] ) {
			$errors['coupons'] = (string) $refused[0];
		}
		$email = $this->email( $draft );
		if ( $email === '' ) {
			$errors['billing.email'] = __( 'Enter the customer\'s email address or pick a customer account.', 'flexacommerce' );
		}
		foreach ( [
			'billing'  => $draft->billing,
			'shipping' => $draft->shipping,
		] as $type => $address ) {
			if ( $address !== null && $address->country !== '' && ! Countries::exists( $address->country ) ) {
				$errors[ $type . '.country' ] = __( 'Choose a country from the list.', 'flexacommerce' );
			}
		}
		if ( $draft->payment_method !== '' && ! in_array( $draft->payment_method, array_column( $this->methods(), 'id' ), true ) ) {
			$errors['payment_method'] = __( 'This payment method is not enabled.', 'flexacommerce' );
		}
		if ( ! isset( $errors['coupons'] ) && $email !== '' && $state->discounts !== null && $state->discounts->applied !== [] ) {
			$account = $draft->customer_id > 0 ? (string) ( get_userdata( $draft->customer_id )->user_email ?? '' ) : '';
			$errors += $this->coupons()->check_customer( $state->discounts, $draft->customer_id, $email, $account );
		}
		return $errors;
	}

	/** The billing email, else the customer account's. */
	private function email( AdminDraft $draft ): string {
		if ( $draft->billing->email !== '' && is_email( $draft->billing->email ) ) {
			return $draft->billing->email;
		}
		$user = $draft->customer_id > 0 ? get_userdata( $draft->customer_id ) : false;
		return $user instanceof \WP_User ? $user->user_email : '';
	}

	private function gateway( string $id ): ?PaymentGateway {
		return $id !== '' ? $this->payments->gateways()->get( $id ) : null;
	}

	private function new_order( AdminDraft $draft, Quote $quote, string $status, ?PaymentGateway $gateway, string $locale = '' ): NewOrder {
		[ 'items' => $items, 'discount' => $discount, 'fees' => $fees ] = $this->checkout->order_items( $quote );
		$email    = $this->email( $draft );
		$billing  = new AddressView( 'billing', ...array_merge( $draft->billing->to_array(), [ 'email' => $email ] ) );
		$currency = $quote->total->currency;
		if ( $locale === '' ) {
			$locale = $draft->customer_id > 0 ? get_user_locale( $draft->customer_id ) : get_locale();
		}
		return new NewOrder(
			status: $status,
			currency: $currency,
			currency_exponent: $this->money->exponent( $currency ),
			items: $items,
			subtotal: $quote->items_net->amount,
			shipping_total: $quote->shipping->amount,
			total: $quote->total->amount,
			customer_email: $email,
			customer_id: $draft->customer_id > 0 ? $draft->customer_id : null,
			billing: $billing,
			shipping: $draft->shipping,
			customer_note: $draft->customer_note,
			payment_method: $gateway?->id() ?? '',
			payment_method_title: $gateway !== null ? $this->payments->gateways()->title( $gateway ) : '',
			created_via: 'admin',
			locale: $locale,
			discount_total: $discount,
			fee_total: $fees,
			tax_total: $quote->tax->amount,
			meta: $quote->taxes?->exempt === true ? [ '_fxc_tax_exempt' => '1' ] : [],
			prices_include_tax: $quote->prices_include_tax,
			attention: CheckoutService::tax_attention( $quote ),
		);
	}

	/** @return array<string,mixed> */
	private function line( CartState $state, CartItem $item ): array {
		return [
			'key'        => $item->line->key,
			'product_id' => $item->line->product_id,
			'variant_id' => $item->line->variant_id,
			'name'       => $item->name(),
			'sku'        => (string) $item->sku(),
			'labels'     => [
				...array_map( static fn ( array $label ): string => $label['name'] . ': ' . $label['value'], $item->line->labels ),
				// What one bundle holds (ADR-071).
				...array_map(
					static fn ( BundlePart $part ): string => sprintf(
						/* translators: 1: units in one bundle, 2: product name */
						__( 'Includes %1$d × %2$s', 'flexacommerce' ),
						$part->quantity,
						$part->product->title
					),
					$item->parts
				),
			],
			'quantity'   => $item->line->quantity,
			'status'     => $item->status,
			'unit'       => $item->unit !== null ? $this->presenter->money( $item->unit->active ) : null,
			'total'      => $item->total !== null ? $this->presenter->money( $item->total ) : null,
			'discount'   => $this->presenter->money( new Money( $state->line_discount( $item->line->key ), $state->subtotal->currency ) ),
		];
	}

	private function coupons(): CouponService {
		if ( $this->coupons === null ) {
			throw new \LogicException( 'Coupons are not available.' );
		}
		return ( $this->coupons )();
	}
}
