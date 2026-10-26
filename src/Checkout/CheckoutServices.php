<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\CartServices;
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

	public function __construct(
		private readonly Services $services,
		private readonly CartServices $cart,
		private readonly ShippingServices $shipping,
		private readonly OrderServices $orders,
		private readonly PaymentServices $payments,
		private ?TaxServices $tax = null,
		private readonly ?\Closure $coupons = null,
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
		);
	}
}
