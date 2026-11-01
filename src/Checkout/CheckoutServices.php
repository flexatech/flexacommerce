<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Catalog\VariantRepository;
use Flexa\Commerce\Orders\OrderServices;
use Flexa\Commerce\Payments\PaymentServices;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Support\Services;
use Flexa\Commerce\Tax\TaxServices;

defined( 'ABSPATH' ) || exit;

/** Lazy container for the Checkout module, built by Plugin over the other modules. */
final class CheckoutServices {
	private ?CheckoutService $service = null;
	private ?CheckoutPages $pages     = null;
	private ?CartTaxes $cart_taxes    = null;
	private ?Simulator $simulator     = null;
	private ?AdminCheckout $admin     = null;

	public function __construct(
		private readonly Services $services,
		private readonly CartServices $cart,
		private readonly ShippingServices $shipping,
		private readonly OrderServices $orders,
		private readonly PaymentServices $payments,
		private ?TaxServices $tax = null,
		private readonly ?\Closure $coupons = null,
		private readonly ?\Closure $gift_cards = null,
		private readonly ?\Closure $subscriptions = null,
	) {}

	public function pages(): CheckoutPages {
		return $this->pages ??= new CheckoutPages();
	}

	public function tax(): TaxServices {
		return $this->tax ??= new TaxServices( $this->services );
	}

	public function cart_taxes(): CartTaxes {
		return $this->cart_taxes ??= new CartTaxes( $this->tax()->service(), $this->cart->presenter() );
	}

	public function service(): CheckoutService {
		return $this->service ??= new CheckoutService(
			$this->cart->cart(),
			$this->cart->presenter(),
			$this->shipping->service(),
			$this->tax()->service(),
			$this->orders->service(),
			$this->payments->service(),
			new CheckoutValidator(),
			$this->pages(),
			$this->services->transaction(),
			$this->services->money(),
			$this->services->logger( 'checkout' ),
			$this->services->limiter(),
			$this->cart_taxes(),
			null,
			$this->coupons,
			$this->gift_cards,
			$this->subscriptions,
		);
	}

	public function simulator(): Simulator {
		return $this->simulator ??= new Simulator( $this->cart->cart(), $this->service(), $this->cart->presenter(), $this->coupons );
	}

	/** Orders the admin makes or edits (ADR-061). */
	public function admin(): AdminCheckout {
		return $this->admin ??= new AdminCheckout(
			$this->cart->cart(),
			$this->service(),
			$this->cart->presenter(),
			$this->orders->service(),
			$this->payments->service(),
			$this->services->transaction(),
			$this->services->money(),
			new VariantRepository( $this->services->wpdb(), $this->services->clock() ),
			$this->coupons,
		);
	}
}
