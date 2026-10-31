<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\Cart;
use Flexa\Commerce\Cart\CartPresenter;
use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Exceptions\OrderNotFound;
use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Contracts\Exceptions\TotalsChanged;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Contracts\Orders\AddressView;
use Flexa\Commerce\Contracts\Payments\CheckoutContext;
use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Contracts\Payments\PaymentResult;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;
use Flexa\Commerce\Database\Transaction as DbTransaction;
use Flexa\Commerce\Orders\NewOrder;
use Flexa\Commerce\Orders\NewOrderItem;
use Flexa\Commerce\Orders\Order;
use Flexa\Commerce\Orders\OrderAccess;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderService;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Orders\TransitionContext;
use Flexa\Commerce\Payments\Gateways\BankTransfer;
use Flexa\Commerce\Payments\OfflineGateway;
use Flexa\Commerce\Payments\PaymentService;
use Flexa\Commerce\Payments\Transaction;
use Flexa\Commerce\Shipping\ShippingService;
use Flexa\Commerce\Support\ClientIp;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\RateLimiter;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Contracts\Tax\TaxLine;
use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRequest;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Coupons\AppliedCoupon;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Tax\TaxService;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a cart into an order (doc 02 §7.5). Everything the customer saw is
 * recomputed on submit; the browser only names choices. The order number is
 * taken before the transaction, the gateway is called after it commits, and
 * a repeated submit with the same checkout token returns the first order.
 */
final class CheckoutService {
	public const RETURN_ARG = 'fxc_return';

	/** Wallets an express button pays with (ADR-053), named on the order. */
	public const WALLETS = [
		'apple_pay'  => 'Apple Pay',
		'google_pay' => 'Google Pay',
		'venmo'      => 'Venmo',
		'paylater'   => 'Pay Later',
	];

	private readonly CartTaxes $cart_taxes;

	private readonly CustomerProfile $profile;

	public function __construct(
		private readonly CartService $cart,
		private readonly CartPresenter $presenter,
		private readonly ShippingService $shipping,
		private readonly TaxService $tax,
		private readonly OrderService $orders,
		private readonly PaymentService $payments,
		private readonly CheckoutValidator $validator,
		private readonly CheckoutPages $pages,
		private readonly DbTransaction $transaction,
		private readonly MoneyFactory $money,
		private readonly Logger $log,
		private readonly RateLimiter $limiter = new RateLimiter(),
		?CartTaxes $cart_taxes = null,
		?CustomerProfile $profile = null,
		private readonly ?\Closure $coupons = null,
	) {
		$this->cart_taxes = $cart_taxes ?? new CartTaxes( $tax, $presenter );
		$this->profile    = $profile ?? new CustomerProfile();
	}

	public function pages(): CheckoutPages {
		return $this->pages;
	}

	/**
	 * Totals for a cart shipped to a destination. Without a valid rate id
	 * the first offered rate is shown; the submit must name one exactly.
	 *
	 * @throws \Flexa\Commerce\Contracts\Exceptions\TaxUnavailable a tax add-on failed and allows no fallback
	 */
	public function quote( CartState $state, Destination $destination, string $rate_id, ?AddressView $billing = null, int $user_id = 0 ): Quote {
		$needs   = $state->needs_shipping() && $this->shipping->is_configured();
		$package = $this->cart->package( $state, $destination );
		$rates   = $needs ? $this->shipping->rates( $package ) : [];
		$rate    = null;
		foreach ( $rates as $offered ) {
			if ( $offered->id === $rate_id ) {
				$rate = $offered;
				break;
			}
		}
		$rate   ??= $rates[0] ?? null;
		$zero     = $state->subtotal->multiply( 0 );
		$shipping = $rate !== null ? $rate->cost : $zero;
		if ( ! $this->tax->enabled() ) {
			// Taxes off: no tax request at all, so no tax classes load (PT-off).
			$net = $zero;
			foreach ( $state->items as $item ) {
				if ( $item->unit !== null && $item->total !== null && $item->product !== null && $item->variant !== null ) {
					$net = $net->add( $item->total );
				}
			}
			$discount = $state->discount();
			return new Quote( $state, $package, $needs, $rates, $rate, $shipping, $zero, $net->subtract( $discount )->add( $shipping )->add( $state->fee_total() ), null, $net, discount: $discount );
		}
		$to       = new TaxLocation( $destination->country, $destination->state, $destination->postcode, $destination->city );
		$from     = $billing !== null ? new TaxLocation( (string) $billing->country, (string) $billing->state, (string) $billing->postcode, (string) $billing->city ) : $to;
		$location = $this->tax->location( $needs ? $to : null, $from );
		$request  = $this->cart_taxes->request( $state, $rate, $location, TaxRequest::CHECKOUT, $user_id, (string) ( $billing->email ?? '' ) );
		$outcome  = $this->tax->calculate( $request );
		$taxes    = $outcome->result;
		$base     = $this->cart_taxes->undiscounted( $state, $location, $user_id, (string) ( $billing->email ?? '' ) );
		$net      = $zero;
		$before   = $zero;
		foreach ( $request->lines as $line ) {
			if ( $line->type === TaxLine::PRODUCT ) {
				$net    = $net->add( $taxes->line( $line->key )->net ?? $line->amount );
				$before = $before->add( ( $base ?? $taxes )->line( $line->key )->net ?? $line->amount );
			}
		}
		$tax = $taxes->total();

		// Fees carry no tax (ADR-043).
		return new Quote( $state, $package, $needs, $rates, $rate, $shipping, $tax, $net->add( $shipping )->add( $tax )->add( $state->fee_total() ), $taxes, $before, $request->prices_include_tax, $outcome->failure, $before->subtract( $net ), $base );
	}

	/**
	 * What the checkout page needs to render and to post back.
	 *
	 * @return array<string,mixed>
	 */
	public function form_state( int $user_id ): array {
		$stored                     = $this->cart->state( $user_id );
		$destination                = $this->stored_destination( $stored->cart, $user_id );
		[ $state, $quote, $method ] = $this->quoted( $user_id, $destination, $stored->cart->shipping_rate, $stored->cart->payment_method );
		$saved                      = [];
		foreach ( $this->profile->addresses( $user_id ) as $type => $address ) {
			$saved[ $type ] = $address->to_array();
		}
		if ( isset( $saved['billing'] ) && $state->cart->destination['country'] !== '' ) {
			// The form starts with billing as the shipping address: it must match what was quoted.
			$saved['billing'] = array_merge( $saved['billing'], $state->cart->destination );
		}
		$facts   = CartFacts::from_state( $state, $quote->needs_shipping );
		$allowed = Countries::allowed();
		$states  = [];
		foreach ( array_keys( $allowed ) as $code ) {
			$list = Countries::states( $code );
			if ( $list !== [] ) {
				$states[ $code ] = $list;
			}
		}

		return [
			'checkout_token' => $state->cart->is_empty() ? '' : $this->cart->checkout_token( $user_id, $state ),
			'cart'           => $this->presenter->to_array( $state ),
			'destination'    => $destination->to_array(),
			'countries'      => $allowed,
			'states'         => $states,
			'fields'         => [
				'billing'  => FieldSchema::for_address( 'billing', $destination->country, $facts )->to_array(),
				'shipping' => FieldSchema::for_address( 'shipping', $destination->country, $facts )->to_array(),
			],
			'cart_facts'     => $facts->to_array(),
			'paused'         => Settings::bool( 'checkout_paused' ),
			'terms_url'      => $this->pages->terms_url(),
			'logged_in'      => $user_id > 0,
			'saved'          => $saved,
			'payment_method' => $method,
		] + $this->quote_view( $quote, $user_id );
	}

	/**
	 * The form state with one sample product, for "Preview on site" with an
	 * empty cart (ADR-032). Nothing is stored: the page only shows it.
	 *
	 * @param array<string,mixed> $state form_state()
	 * @return array<string,mixed>
	 */
	public function sample_state( array $state, int $user_id ): array {
		$price   = $this->money->from_decimal_string( '20' );
		$ship    = $this->money->from_decimal_string( '5' );
		$total   = $price->add( $ship );
		$country = (string) ( $state['destination']['country'] ?? '' );
		$rate    = 'flat_rate:preview';
		$context = new CheckoutContext( $total, $country, $country, true, $rate, $user_id > 0 ? $user_id : null, 1 );

		$state['cart']['items']   = [
			[
				'name'       => __( 'Sample product', 'flexacommerce' ),
				'quantity'   => 1,
				'attributes' => [],
				'total'      => $this->presenter->money( $price ),
			],
		];
		$state['coupons']         = [];
		$state['needs_shipping']  = true;
		$state['needs_address']   = true;
		$state['cart_facts']      = ( new CartFacts( true ) )->to_array();
		$state['shipping_rates']  = [
			[
				'id'            => $rate,
				'label'         => __( 'Flat rate', 'flexacommerce' ),
				'meta'          => [],
				'cost'          => $this->presenter->money( $ship ),
				'needs_address' => true,
			],
		];
		$state['shipping_rate']   = $rate;
		$state['payment_methods'] = $this->methods_view( $context );
		$state['totals']          = [
			'subtotal' => $this->presenter->money( $price ),
			'shipping' => $this->presenter->money( $ship ),
			'tax'      => $this->presenter->money( $this->money->from_minor( 0 ) ),
			'total'    => $this->presenter->money( $total ),
		];
		$state['tax']             = [];
		return $state;
	}

	/**
	 * The customer changed the address, the rate or the payment method:
	 * requote and remember the choice so a reload shows the same thing.
	 *
	 * @return array<string,mixed>
	 */
	public function update_shipping( int $user_id, Destination $destination, string $rate_id, string $payment_method = '' ): array {
		[ $state, $quote, $method ] = $this->quoted( $user_id, $destination, $rate_id, $payment_method );
		$facts                      = CartFacts::from_state( $state, $quote->needs_shipping );
		$chose                      = $quote->rate->id ?? '';
		$stored                     = $this->cart->saved( $user_id );
		$saved                      = $stored->destination;
		if ( $state->has_session && ( $saved['country'] !== $destination->country || $saved['state'] !== $destination->state || $saved['postcode'] !== $destination->postcode || $saved['city'] !== $destination->city || $stored->shipping_rate !== $chose || $stored->payment_method !== $method ) ) {
			$this->cart->set_shipping(
				$user_id,
				[
					'country'  => $destination->country,
					'state'    => $destination->state,
					'postcode' => $destination->postcode,
					'city'     => $destination->city,
				],
				$chose,
				$method
			);
		}
		return [
			'destination'    => $destination->to_array(),
			'payment_method' => $method,
			'fields'         => [
				'billing'  => FieldSchema::for_address( 'billing', $destination->country, $facts )->to_array(),
				'shipping' => FieldSchema::for_address( 'shipping', $destination->country, $facts )->to_array(),
			],
		] + $this->quote_view( $quote, $user_id );
	}

	/**
	 * @throws InvalidCheckout the submission has errors; `errors` maps fields to messages
	 * @throws TotalsChanged the total differs from the one the customer saw
	 * @throws RateLimited   too many attempts from this cart or this IP (doc 06 §2.9)
	 */
	public function place_order( CheckoutRequest $request ): CheckoutResult {
		if ( Settings::bool( 'checkout_paused' ) ) {
			throw new InvalidCheckout( [ 'cart' => __( 'Checkout is paused for maintenance. Please try again shortly.', 'flexacommerce' ) ] );
		}
		if ( $request->checkout_token === '' ) {
			throw new InvalidCheckout( [ 'cart' => __( 'Your session has expired. Reload the page and try again.', 'flexacommerce' ) ] );
		}
		$existing = $this->orders->find_by_checkout_token( $request->checkout_token );
		if ( $existing !== null ) {
			return $this->duplicate( $existing );
		}

		$state = $this->cart->state( $request->user_id );
		if ( $state->cart->is_empty() || $state->cart->checkout_token !== $request->checkout_token ) {
			// A parallel submit of the same form may have placed the order and emptied the cart since the lookup above.
			$existing = $this->orders->find_by_checkout_token( $request->checkout_token );
			if ( $existing !== null ) {
				return $this->duplicate( $existing );
			}
		}
		if ( $state->cart->is_empty() ) {
			throw new InvalidCheckout( [ 'cart' => __( 'Your cart is empty.', 'flexacommerce' ) ] );
		}
		// Counted before validation: card testing fails validation or payment over and over.
		$this->limiter->hit( 'checkout_session', $state->cart->checkout_token );
		$this->limiter->hit( 'checkout_ip', $request->customer_ip );
		if ( $state->cart->checkout_token !== $request->checkout_token ) {
			throw new InvalidCheckout( [ 'cart' => __( 'Your cart changed in another window. Review it and place the order again.', 'flexacommerce' ) ] );
		}
		if ( $state->has_problems() ) {
			throw new InvalidCheckout( [ 'cart' => __( 'Some items in your cart are no longer available. Review your cart and try again.', 'flexacommerce' ) ] );
		}

		return $this->complete(
			$request,
			fn ( array $choices ): CartState => $this->cart->state( $request->user_id, $choices + [ 'final' => true ] ),
			true
		);
	}

	/**
	 * "Buy now" with a wallet on the product page (ADR-053): an order for
	 * a cart of one product that was never stored, so the shopper's own
	 * cart stays as it is. The wallet script makes the checkout token.
	 *
	 * @throws InvalidCheckout|TotalsChanged|RateLimited
	 */
	public function place_single( CheckoutRequest $request, Cart $cart ): CheckoutResult {
		if ( Settings::bool( 'checkout_paused' ) ) {
			throw new InvalidCheckout( [ 'cart' => __( 'Checkout is paused for maintenance. Please try again shortly.', 'flexacommerce' ) ] );
		}
		if ( preg_match( '/^[A-Za-z0-9-]{20,64}$/', $request->checkout_token ) !== 1 ) {
			throw new InvalidCheckout( [ 'cart' => __( 'Your session has expired. Reload the page and try again.', 'flexacommerce' ) ] );
		}
		$existing = $this->orders->find_by_checkout_token( $request->checkout_token );
		if ( $existing !== null ) {
			return $this->duplicate( $existing );
		}
		$this->limiter->hit( 'checkout_session', $request->checkout_token );
		$this->limiter->hit( 'checkout_ip', $request->customer_ip );
		if ( $this->cart->preview( $cart, $request->user_id )->has_problems() ) {
			throw new InvalidCheckout( [ 'cart' => __( 'This product is no longer available.', 'flexacommerce' ) ] );
		}
		return $this->complete(
			$request,
			fn ( array $choices ): CartState => $this->cart->preview(
				$cart->with_shipping( [ 'country' => $choices['country'] ] + $cart->destination, $choices['shipping_rate'] )->with_payment_method( $choices['payment_method'] ),
				$request->user_id,
				true
			),
			false
		);
	}

	/**
	 * What a wallet sheet shows (ADR-053): the cart, or one product, quoted
	 * for the address the wallet shares, without storing that address.
	 * Amounts are in minor units; the shipping line is not among `lines`,
	 * since the wallet adds the chosen rate itself.
	 *
	 * @param array{country:string,state:string,postcode:string,city:string}|null $address null before the wallet shares one
	 * @return array<string,mixed>
	 * @throws InvalidCheckout the cart is empty
	 */
	public function express_quote( int $user_id, ?Cart $single, ?array $address, string $rate_id, string $method ): array {
		$stored = $single === null ? $this->cart->state( $user_id ) : null;
		$cart   = $single ?? $stored->cart ?? new Cart();
		if ( $cart->is_empty() ) {
			throw new InvalidCheckout( [ 'cart' => __( 'Your cart is empty.', 'flexacommerce' ) ] );
		}
		$destination             = $address !== null && $address['country'] !== ''
			? new Destination( $address['country'], $address['state'], $address['postcode'], $address['city'] )
			: $this->stored_destination( $cart, $user_id );
		[ $state, $quote, $pay ] = $this->preview( $cart->with_shipping( $cart->destination, $rate_id )->with_payment_method( $method ), $user_id, $destination );
		$rates                   = [];
		foreach ( $quote->rates as $rate ) {
			$rates[] = [
				'id'     => $rate->id,
				'label'  => $rate->label,
				'amount' => $rate->cost->amount,
			];
		}
		$fees = $state->fee_total()->amount;
		// `kind` lets a gateway split the total the way its sheet does (PayPal, ADR-054).
		$lines = [
			[
				'kind'   => 'subtotal',
				'label'  => __( 'Subtotal', 'flexacommerce' ),
				'amount' => $quote->total->amount - $quote->shipping->amount - $quote->tax->amount - $fees,
			],
		];
		foreach ( $state->fees as $fee ) {
			$lines[] = [
				'kind'   => 'fee',
				'label'  => $fee->name,
				'amount' => $fee->amount->amount,
			];
		}
		if ( $quote->tax->amount > 0 ) {
			$lines[] = [
				'kind'   => 'tax',
				'label'  => __( 'Tax', 'flexacommerce' ),
				'amount' => $quote->tax->amount,
			];
		}
		return [
			'currency'       => $quote->total->currency,
			'total'          => $quote->total->amount,
			'payable'        => $quote->total->amount > 0 && $pay === $method && ! $state->has_problems(),
			'needs_shipping' => $quote->needs_shipping,
			'shipping_rates' => $rates,
			'shipping_rate'  => $quote->rate->id ?? '',
			'lines'          => $lines,
			'checkout_token' => $stored !== null ? $this->cart->checkout_token( $user_id, $stored ) : '',
		];
	}

	/**
	 * The order from a request whose cart is checked: priced for the
	 * choices on the form, validated, created, then paid. `$session` says
	 * the cart is the shopper's stored one, emptied after the order.
	 *
	 * @param \Closure(array{country:string,shipping_rate:string,payment_method:string}):CartState $priced
	 * @throws InvalidCheckout|TotalsChanged
	 */
	private function complete( CheckoutRequest $request, \Closure $priced, bool $session ): CheckoutResult {
		// Priced for the choices on the form; a condition still unknown is not met (ADR-041).
		$destination = $this->validator->destination( $request );
		$state       = $priced(
			[
				'country'        => $destination->country,
				'shipping_rate'  => $request->shipping_rate,
				'payment_method' => $request->payment_method,
			]
		);
		if ( $state->has_rejected_coupons() ) {
			throw new InvalidCheckout( [ 'coupon' => (string) ( array_values( $state->discounts->rejected ?? [] )[0] ?? '' ) ] );
		}
		$quote     = $this->quote( $state, $destination, $request->shipping_rate, null, $request->user_id );
		$addresses = $this->validator->addresses( $request, $quote->needs_address(), CartFacts::from_state( $state, $quote->needs_shipping ) );
		$errors    = $addresses['errors'];
		if ( $errors === [] ) {
			$quote = $this->quote( $state, $destination, $request->shipping_rate, $addresses['billing'], $request->user_id );
		}
		$context  = $this->context( $quote, $addresses['billing']->country, $addresses['shipping']->country ?? '', $request->user_id );
		$gateways = $this->payments->gateways()->available( $context );
		$errors  += $this->validator->choices( $request, $quote, $context, $gateways );
		if ( $errors === [] && $state->discounts !== null ) {
			$errors += $this->coupons()->check_customer( $state->discounts, $request->user_id, $addresses['billing']->email, self::account_email( $request->user_id ) );
		}
		if ( $errors !== [] ) {
			throw new InvalidCheckout( $errors );
		}
		if ( $request->expected_total !== null && $request->expected_total !== $quote->total->amount ) {
			throw new TotalsChanged( __( 'Your order total changed. Review the new total and place the order again.', 'flexacommerce' ) );
		}

		$gateway = $quote->total->amount > 0 ? $gateways[ $request->payment_method ] : null;
		$new     = $this->new_order( $request, $quote, $addresses['billing'], $addresses['shipping'], $addresses['meta'], $gateway );
		$ctx     = new TransitionContext( 'customer', $request->user_id );
		$number  = $this->orders->next_number();

		try {
			/** @var array{0:Order,1:?Transaction} $created */
			$created = $this->transaction->run(
				function () use ( $new, $number, $ctx, $gateway, $state, $request ): array {
					$order = $this->orders->create( $new, $number, $ctx );
					if ( $order->order_number !== $number ) {
						return [ $order, null ];
					}
					if ( $state->discounts !== null && $state->discounts->applied !== [] ) {
						$this->coupons()->redeem( $state->discounts, $order->id, $request->user_id, $new->customer_email );
					}
					return [ $order, $gateway !== null ? $this->payments->create_payment( $order, $gateway ) : null ];
				}
			);
		} catch ( InsufficientStock $e ) {
			throw new InvalidCheckout( [ 'cart' => $e->getMessage() ] );
		}
		[ $order, $txn ] = $created;
		if ( $order->order_number !== $number ) {
			// Read again outside the transaction: inside it, the other submit's items were not visible.
			return $this->duplicate( $this->orders->get( $order->id ) );
		}

		$result   = null;
		$redirect = $this->pages->confirmation_url( $order );
		if ( $txn !== null ) {
			$result   = $this->payments->initiate( $txn->id, $this->return_url( $order, $txn ), $this->pages->pay_url( $order ), $request->gateway_input, $order );
			$redirect = $this->redirect_for( $order, $result, $txn );
		}

		if ( $request->user_id > 0 ) {
			// The next checkout and the account page start from the addresses used (doc 03 §7).
			$this->profile->save( $request->user_id, $addresses['billing'] );
			if ( $addresses['shipping'] !== null ) {
				$this->profile->save( $request->user_id, $addresses['shipping'] );
			}
		}

		try {
			if ( $session ) {
				$this->cart->finish_checkout( $request->user_id, $request->checkout_token );
			}
		} catch ( \Throwable $e ) {
			$this->log->warning(
				'Could not empty the cart after order {order_id}: {message}',
				[
					'order_id' => $order->id,
					'message'  => $e->getMessage(),
				]
			);
		}

		return new CheckoutResult( $this->orders->get( $order->id ), $redirect, $result, false );
	}

	/**
	 * Pay a pending order again from the order-pay page, possibly with
	 * another gateway.
	 *
	 * @param array<string,mixed> $input gateway fields
	 * @throws OrderNotFound   no such order or the key does not match
	 * @throws InvalidCheckout the order cannot be paid or the gateway refused the input
	 * @throws RateLimited     too many wrong keys from this IP
	 */
	public function retry_payment( int $order_id, string $key, string $gateway_id, array $input, int $user_id ): CheckoutResult {
		$order = $this->orders->find( $order_id );
		if ( ! $this->may_view( $order, $key, $user_id ) ) {
			throw new OrderNotFound( __( 'Order not found.', 'flexacommerce' ) );
		}
		if ( $order->status !== OrderStatus::PENDING || $order->total <= 0 ) {
			throw new InvalidCheckout( [ 'order' => __( 'This order does not need a payment.', 'flexacommerce' ) ] );
		}

		$context  = new CheckoutContext(
			$this->money->from_minor( $order->total, $order->currency ),
			$order->billing->country ?? '',
			$order->shipping->country ?? '',
			$order->shipping_total > 0 || $order->shipping !== null,
			null,
			$order->customer_id,
			$this->item_count( $order ),
		);
		$gateways = $this->payments->gateways()->available( $context );
		$gateway  = $gateways[ $gateway_id ] ?? null;
		if ( $gateway === null ) {
			throw new InvalidCheckout( [ 'payment_method' => __( 'Choose a payment method.', 'flexacommerce' ) ] );
		}
		$errors = [];
		foreach ( $gateway->validate( $context, $input )->errors as $error ) {
			$errors[ 'gateway.' . $error['code'] ] = $error['message'];
		}
		if ( $errors !== [] ) {
			throw new InvalidCheckout( $errors );
		}

		$ctx   = new TransitionContext( 'customer', $user_id );
		$order = $this->orders->change_payment_method( $order->id, $gateway->id(), $this->payments->gateways()->title( $gateway ), $ctx );
		$txn   = $this->transaction->run( fn (): Transaction => $this->payments->create_payment( $order, $gateway ) );

		if ( $gateway instanceof OfflineGateway ) {
			$status = $this->payments->initial_status( $gateway, $order->total );
			if ( $status !== OrderStatus::PENDING ) {
				$order = $this->orders->transition( $order->id, $status, $ctx );
			}
		}
		$result = $this->payments->initiate( $txn->id, $this->return_url( $order, $txn ), $this->pages->pay_url( $order ), $input );

		return new CheckoutResult( $this->orders->get( $order->id ), $this->redirect_for( $order, $result, $txn ), $result, false );
	}

	/** Why the last attempt to pay this order failed, for the order-pay page; '' when it did not fail. */
	public function last_failure( Order $order ): string {
		foreach ( $this->payments->for_order( $order->id ) as $txn ) {
			if ( $txn->type !== Transaction::PAYMENT ) {
				continue;
			}
			if ( $txn->status !== Transaction::FAILED ) {
				return '';
			}
			$message = rtrim( trim( (string) $txn->failure_message ), '.' );
			return $message !== '' ? $message . '.' : __( 'The last payment attempt did not go through.', 'flexacommerce' );
		}
		return '';
	}

	/** @return list<array<string,mixed>> gateways the order-pay page offers for a pending order */
	public function pay_methods( Order $order ): array {
		$context = new CheckoutContext(
			$this->money->from_minor( $order->total, $order->currency ),
			$order->billing->country ?? '',
			$order->shipping->country ?? '',
			$order->shipping !== null,
			null,
			$order->customer_id,
			$this->item_count( $order ),
		);
		return $this->methods_view( $context );
	}

	/**
	 * The order the customer may see, or null. Order pages use it so a wrong
	 * key and a missing order look the same.
	 */
	public function viewable_order( int $order_id, string $key, int $user_id ): ?Order {
		$order = $order_id > 0 ? $this->orders->find( $order_id ) : null;
		try {
			return $this->may_view( $order, $key, $user_id ) ? $order : null;
		} catch ( RateLimited ) {
			return null;
		}
	}

	/**
	 * Owner and staff pass without a key. A key is checked only while the IP
	 * is under the failure limit, and a wrong key or an unknown order with a
	 * key counts as one failure (doc 06 §2.7, §2.9).
	 *
	 * @phpstan-assert-if-true !null $order
	 * @throws RateLimited
	 */
	private function may_view( ?Order $order, string $key, int $user_id ): bool {
		if ( $order !== null && OrderAccess::can_view( $order, '', $user_id ) ) {
			return true;
		}
		if ( $key === '' ) {
			return false;
		}
		$ip = ClientIp::get();
		$this->limiter->check( 'order_key_ip', $ip );
		if ( $order !== null && OrderAccess::key_matches( $order, $key ) ) {
			return true;
		}
		$this->limiter->count( 'order_key_ip', $ip );
		return false;
	}

	/**
	 * The customer is back from the gateway with a transaction id in the
	 * URL. The id must belong to the order; a failure here is logged and the
	 * webhook stays in charge (doc 02 §7.8). Returns what handle_return()
	 * said; `failed` sends the customer to the order-pay page to try again.
	 *
	 * @param array<string,mixed> $query
	 */
	public function returned( Order $order, int $transaction_id, array $query ): string {
		$txn = $this->payments->find( $transaction_id );
		if ( $txn === null || $txn->order_id !== $order->id ) {
			return 'unknown';
		}
		try {
			return $this->payments->handle_return( $txn->id, $query );
		} catch ( \Throwable $e ) {
			$this->log->warning(
				'Return from gateway for order {order_id} failed: {message}',
				[
					'order_id' => $order->id,
					'message'  => $e->getMessage(),
				]
			);
			return 'error';
		}
	}

	/** Payment instructions to show on the confirmation page (bank transfer), else ''. */
	public function instructions( Order $order ): string {
		if ( ! in_array( $order->status, [ OrderStatus::PENDING, OrderStatus::ON_HOLD ], true ) || $order->payment_method === '' ) {
			return '';
		}
		$gateway = $this->payments->gateways()->get( $order->payment_method );
		return $gateway instanceof BankTransfer ? $gateway->instructions( $order->order_number ) : '';
	}

	private function coupons(): CouponService {
		if ( $this->coupons === null ) {
			throw new \LogicException( 'Coupons are not available.' );
		}
		return ( $this->coupons )();
	}

	private static function account_email( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user instanceof \WP_User ? $user->user_email : '';
	}

	public function format( int $amount, string $currency ): string {
		return $this->money->format( $this->money->from_minor( $amount, $currency ) );
	}

	/**
	 * The cart priced for the checkout choices, so conditions on the
	 * country, the shipping or the payment method see them (ADR-041). When
	 * the rate asked for is not offered, or no available payment method is
	 * named, the quote picks one and the cart is priced again for it.
	 *
	 * @return array{0:CartState,1:Quote,2:string} the state, the quote and the payment method
	 */
	private function quoted( int $user_id, Destination $destination, string $rate_id, string $method ): array {
		return $this->requote(
			fn ( string $rate, string $pay ): CartState => $this->cart->state(
				$user_id,
				[
					'country'        => $destination->country,
					'shipping_rate'  => $rate,
					'payment_method' => $pay,
				]
			),
			$user_id,
			$destination,
			$rate_id,
			$method
		);
	}

	/**
	 * A cart that is never stored, quoted as checkout would quote it, for
	 * the rule simulator (ADR-046): the rate and the payment method chosen,
	 * or the first ones offered.
	 *
	 * @return array{0:CartState,1:Quote,2:string} the state, the quote and the payment method
	 */
	public function preview( Cart $cart, int $user_id, Destination $destination, bool $final = false ): array {
		$address = [
			'country'  => $destination->country,
			'state'    => $destination->state,
			'postcode' => $destination->postcode,
			'city'     => $destination->city,
		];
		return $this->requote(
			fn ( string $rate, string $pay ): CartState => $this->cart->preview( $cart->with_shipping( $address, $rate )->with_payment_method( $pay ), $user_id, $final ),
			$user_id,
			$destination,
			$cart->shipping_rate,
			$cart->payment_method
		);
	}

	/**
	 * Quotes the cart with the rate and the payment method named; when one
	 * is not offered the first one is used and the cart is priced again.
	 *
	 * @param \Closure(string,string):CartState $priced the cart priced with a rate and a payment method
	 * @return array{0:CartState,1:Quote,2:string}
	 */
	private function requote( \Closure $priced, int $user_id, Destination $destination, string $rate_id, string $method ): array {
		$state = $priced( $rate_id, $method );
		$quote = $this->quote( $state, $destination, $rate_id, null, $user_id );
		$rate  = $quote->rate->id ?? '';
		$pay   = $method;
		if ( $quote->total->amount > 0 ) {
			$ids = array_keys( $this->payments->gateways()->available( $this->context( $quote, $destination->country, '', $user_id ) ) );
			$pay = in_array( $method, $ids, true ) ? $method : (string) ( $ids[0] ?? '' );
		}
		if ( $rate !== $rate_id || $pay !== $method ) {
			$state = $priced( $rate, $pay );
			$quote = $this->quote( $state, $destination, $rate, null, $user_id );
		}
		return [ $state, $quote, $pay ];
	}

	private function duplicate( Order $order ): CheckoutResult {
		return new CheckoutResult( $order, $this->pages->confirmation_url( $order ), null, true );
	}

	private function stored_destination( Cart $cart, int $user_id = 0 ): Destination {
		$saved = $cart->destination;
		if ( $saved['country'] !== '' ) {
			return new Destination( $saved['country'], $saved['state'], $saved['postcode'], $saved['city'] );
		}
		// The form opens with "ship to a different address" off, so billing is the address quoted.
		$address = $this->profile->address( $user_id, 'billing' ) ?? $this->profile->address( $user_id, 'shipping' );
		if ( $address !== null && $address->country !== '' ) {
			return new Destination( $address->country, $address->state, $address->postcode, $address->city );
		}
		// Before the shopper types an address, quote at the store address,
		// as WooCommerce does, so tax for the store's state shows.
		$store = Settings::string( 'store_country' );
		$sells = Countries::allowed();
		if ( ! isset( $sells[ $store ] ) && $sells !== [] ) {
			return new Destination( (string) array_key_first( $sells ) );
		}
		return new Destination( $store, Settings::string( 'store_state' ), Settings::string( 'store_postcode' ), Settings::string( 'store_city' ) );
	}

	private function context( Quote $quote, string $billing_country, string $shipping_country, int $user_id ): CheckoutContext {
		return new CheckoutContext(
			$quote->total,
			$billing_country,
			$shipping_country !== '' ? $shipping_country : $quote->package->destination->country,
			$quote->needs_shipping,
			$quote->rate?->id,
			$user_id > 0 ? $user_id : null,
			$quote->state->item_count(),
		);
	}

	/** @return array<string,mixed> */
	private function quote_view( Quote $quote, int $user_id ): array {
		$rates = [];
		foreach ( $quote->rates as $rate ) {
			$rates[] = [
				'id'            => $rate->id,
				'label'         => $rate->label,
				'meta'          => $rate->meta,
				'cost'          => $this->presenter->money( $rate->cost ),
				'needs_address' => $rate->needs_address,
			];
		}
		$context = $this->context( $quote, $quote->package->destination->country, '', $user_id );
		$totals  = [
			'subtotal' => $this->presenter->money( $this->display_subtotal( $quote ) ),
			'shipping' => $this->presenter->money( $quote->shipping ),
			'tax'      => $this->presenter->money( $quote->tax ),
			'total'    => $this->presenter->money( $quote->total ),
		];
		if ( $quote->state->fees !== [] ) {
			$totals = array_slice( $totals, 0, -2, true ) + [ 'fees' => $this->presenter->money( $quote->state->fee_total() ) ] + array_slice( $totals, -2, null, true );
		}
		$coupons = [];
		if ( $quote->state->discounts !== null ) {
			$discount = $this->display_discount( $quote );
			$totals   = array_slice( $totals, 0, 1, true ) + [ 'discount' => $this->presenter->money( $discount ) ] + $totals;
			$coupons  = $this->cart_taxes->coupon_rows( $this->presenter->coupons( $quote->state ), $quote->state, $discount );
		}

		return [
			'coupons'         => $coupons,
			'fees'            => $this->presenter->fees( $quote->state ),
			'needs_shipping'  => $quote->needs_shipping,
			'needs_address'   => $quote->needs_address(),
			'shipping_rates'  => $rates,
			'shipping_rate'   => $quote->rate->id ?? '',
			'payment_methods' => $quote->total->amount > 0 ? $this->methods_view( $context ) : [],
			'totals'          => $totals,
			'tax'             => $this->cart_taxes->view( $quote->taxes, $quote->package->destination->country ),
		];
	}

	/** Items subtotal with or without tax, as `tax_display_cart` says (doc 02 §7.12.5). */
	private function display_subtotal( Quote $quote ): \Flexa\Commerce\Contracts\Money {
		if ( $quote->taxes === null ) {
			return $quote->state->subtotal;
		}
		$settings = $this->tax->settings( $quote->package->destination->country );
		return $settings['tax_display_cart'] === 'incl' ? $quote->items_net->add( ( $quote->undiscounted ?? $quote->taxes )->items_total() ) : $quote->items_net;
	}

	/** The coupons together, shown the way the subtotal is, so subtotal - discount matches the items with tax as shown. */
	private function display_discount( Quote $quote ): Money {
		if ( $quote->taxes === null ) {
			return $quote->state->discount();
		}
		$settings = $this->tax->settings( $quote->package->destination->country );
		$after    = $quote->items_net->subtract( $quote->discount() );
		if ( $settings['tax_display_cart'] === 'incl' ) {
			$after = $after->add( $quote->taxes->items_total() );
		}
		return $this->display_subtotal( $quote )->subtract( $after );
	}

	/** @return list<array<string,mixed>> */
	private function methods_view( CheckoutContext $context ): array {
		$out = [];
		foreach ( $this->payments->gateways()->available( $context ) as $id => $gateway ) {
			try {
				$fields = $gateway->checkout_fields( $context );
			} catch ( \Throwable $e ) {
				$this->log->error(
					'Gateway {gateway} failed to build its checkout fields: {message}',
					[
						'gateway'   => $id,
						'message'   => $e->getMessage(),
						'exception' => $e,
					]
				);
				continue;
			}
			$out[] = [
				'id'            => $id,
				'title'         => $this->payments->gateways()->title( $gateway ),
				'description'   => $fields->description,
				'fields'        => array_map( static fn ( $field ): array => $field->to_array(), $fields->fields ),
				'script_module' => $fields->script_module,
				'config'        => $fields->data,
			];
		}
		return $out;
	}

	/** @param array<string,string> $meta */
	private function new_order( CheckoutRequest $request, Quote $quote, AddressView $billing, ?AddressView $shipping, array $meta, ?PaymentGateway $gateway ): NewOrder {
		$items = [];
		foreach ( $quote->state->items as $item ) {
			if ( $item->unit === null ) {
				continue;
			}
			$attributes = [];
			foreach ( $item->line->labels as $label ) {
				$attributes[] = [
					'label' => $label['name'],
					'value' => $label['value'],
				];
			}
			$taxed   = $quote->taxes?->line( $item->line->key );
			$before  = $quote->undiscounted?->line( $item->line->key ) ?? $taxed;
			$off     = $taxed !== null && $before !== null ? $before->net->amount - $taxed->net->amount : $quote->state->line_discount( $item->line->key );
			$items[] = NewOrderItem::product(
				$item->line->product_id,
				$item->line->variant_id,
				$item->stock_id(),
				$item->name(),
				$item->sku(),
				$attributes,
				$item->line->quantity,
				$item->unit->active->amount,
				// The line total, not unit times quantity: a graduated rule prices units apart (ADR-042).
				$before?->net->amount ?? $item->total?->amount,
				$taxed?->by_rate() ?? [],
				$off,
				$item->fee,
				$item->option_stock,
				array_filter(
					[
						'virtual'      => $item->variant?->virtual === true,
						'downloadable' => $item->variant?->sells_files() === true,
					]
				)
			);
		}
		$discount = 0;
		foreach ( $this->discount_items( $quote ) as $line ) {
			$items[]   = $line;
			$discount += $line->total;
		}
		$fees = 0;
		foreach ( $quote->state->fees as $fee ) {
			$items[] = NewOrderItem::fee( $fee->name, $fee->amount->amount, [ 'rule_id' => $fee->rule_id ] );
			$fees   += $fee->amount->amount;
		}
		if ( $quote->rate !== null ) {
			$items[] = NewOrderItem::shipping( $quote->rate->id, $quote->rate->label, $quote->rate->cost->amount, $quote->taxes?->line( TaxService::SHIPPING_KEY )?->by_rate() ?? [] );
		}
		foreach ( $quote->taxes->rates ?? [] as $total ) {
			$items[] = NewOrderItem::tax(
				$total->rate->code,
				$total->rate->label,
				$total->total()->amount,
				[
					'rate_id'      => $total->rate->key(),
					'rate'         => $total->rate->percent(),
					'compound'     => $total->rate->compound,
					'items_tax'    => $total->items->amount,
					'shipping_tax' => $total->shipping->amount,
				]
			);
		}
		$currency = $quote->total->currency;
		$title    = $gateway !== null ? $this->payments->gateways()->title( $gateway ) : '';
		$wallet   = $request->gateway_input['express'] ?? '';
		if ( $gateway !== null && is_string( $wallet ) && isset( self::WALLETS[ $wallet ] ) ) {
			$title                = sprintf( '%1$s (%2$s)', $title, self::WALLETS[ $wallet ] );
			$meta['_fxc_express'] = $wallet;
		}

		return new NewOrder(
			status: $this->payments->initial_status( $gateway, $quote->total->amount ),
			currency: $currency,
			currency_exponent: $this->money->exponent( $currency ),
			items: $items,
			subtotal: $quote->items_net->amount,
			shipping_total: $quote->shipping->amount,
			total: $quote->total->amount,
			customer_email: $billing->email,
			customer_id: $request->user_id > 0 ? $request->user_id : null,
			billing: $billing,
			shipping: $shipping,
			customer_note: $this->validator->note( $request ),
			customer_ip: Settings::bool( 'store_customer_ip' ) ? $request->customer_ip : '',
			payment_method: $gateway?->id() ?? '',
			payment_method_title: $title,
			checkout_token: $request->checkout_token,
			locale: determine_locale(),
			discount_total: $discount,
			fee_total: $fees,
			tax_total: $quote->tax->amount,
			meta: $quote->taxes?->exempt === true ? $meta + [ '_fxc_tax_exempt' => '1' ] : $meta,
			prices_include_tax: $quote->prices_include_tax,
			attention: $quote->tax_failure !== null
				/* translators: %s: class name of the tax add-on */
				? sprintf( __( 'Tax add-on %s failed at checkout; tax was calculated from the store rate table. Check the tax on this order.', 'flexacommerce' ), $quote->tax_failure )
				: null,
		);
	}

	/**
	 * One discount line per coupon. Each product line's discount without tax
	 * (and the tax it saves) is split over the coupons by what each took
	 * off that line, so the coupon lines add up to the order discount.
	 *
	 * @return list<NewOrderItem>
	 */
	private function discount_items( Quote $quote ): array {
		$applied = $quote->state->discounts->applied ?? [];
		if ( $applied === [] ) {
			return [];
		}
		$net = array_fill( 0, count( $applied ), 0 );
		$tax = $net;
		foreach ( $quote->state->items as $item ) {
			$key    = $item->line->key;
			$ratios = array_map( static fn ( AppliedCoupon $a ): int => $a->lines[ $key ] ?? 0, $applied );
			if ( array_sum( $ratios ) === 0 || $item->total === null ) {
				continue;
			}
			$after  = $quote->taxes?->line( $key );
			$before = $quote->undiscounted?->line( $key ) ?? $after;
			$off    = $after !== null && $before !== null ? $before->net->amount - $after->net->amount : array_sum( $ratios );
			$saved  = $after !== null && $before !== null ? $before->total()->amount - $after->total()->amount : 0;
			foreach ( ( new Money( $off, $item->total->currency ) )->allocate( $ratios ) as $index => $part ) {
				$net[ $index ] += $part->amount;
			}
			if ( $saved !== 0 ) {
				foreach ( ( new Money( $saved, $item->total->currency ) )->allocate( $ratios ) as $index => $part ) {
					$tax[ $index ] += $part->amount;
				}
			}
		}
		$lines = [];
		foreach ( $applied as $index => $a ) {
			$lines[] = NewOrderItem::discount(
				$a->coupon->code,
				$net[ $index ],
				[
					'coupon_id'    => $a->coupon->id,
					'type'         => $a->coupon->type,
					'amount'       => $a->coupon->amount,
					'discount_tax' => $tax[ $index ],
				] + ( $a->coupon->free_shipping ? [ 'free_shipping' => true ] : [] ) + ( $a->coupon->automatic ? [ 'automatic' => true ] : [] )
			);
		}
		return $lines;
	}

	private function return_url( Order $order, Transaction $txn ): string {
		return add_query_arg( self::RETURN_ARG, $txn->id, $this->pages->confirmation_url( $order ) );
	}

	private function redirect_for( Order $order, PaymentResult $result, Transaction $txn ): string {
		if ( $result->type === PaymentResult::REDIRECT && $result->redirect_url !== null && $result->redirect_url !== '' ) {
			return $result->redirect_url;
		}
		if ( $result->type === PaymentResult::REQUIRES_ACTION ) {
			// The gateway's script finishes the action, then the return checks the outcome.
			return $this->return_url( $order, $txn );
		}
		if ( $result->type === PaymentResult::FAILED ) {
			return $this->pages->pay_url( $order );
		}
		return $this->pages->confirmation_url( $order );
	}

	private function item_count( Order $order ): int {
		$count = 0;
		foreach ( $order->items as $item ) {
			if ( $item->type === OrderItem::PRODUCT ) {
				$count += $item->quantity;
			}
		}
		return $count;
	}
}
