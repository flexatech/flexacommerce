<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\CartPresenter;
use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Contracts\Exceptions\TaxUnavailable;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Shipping\ShippingRate;
use Flexa\Commerce\Contracts\Tax\TaxLine;
use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRequest;
use Flexa\Commerce\Contracts\Tax\TaxResult;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Support\IntMath;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tax\TaxService;

defined( 'ABSPATH' ) || exit;

/**
 * Tax for a cart, shared by the checkout quote and the cart display (doc 02
 * §7.12.5). The cart shows the tax of its items at the address the cart
 * remembers, or at the default location; shipping and its tax come at
 * checkout, where the shopper picks a rate.
 */
final class CartTaxes {
	public function __construct(
		private readonly TaxService $tax,
		private readonly CartPresenter $presenter,
	) {}

	/**
	 * The tax request for a cart: one line per priced cart item (key = cart
	 * line key) and one `shipping` line for the rate, when there is one. An
	 * account flagged tax exempt on the Customers screen starts exempt; the
	 * `flexacommerce.tax.exempt` filter still has the last word.
	 */
	public function request( CartState $state, ?ShippingRate $rate, ?TaxLocation $location, string $context = TaxRequest::CHECKOUT, int $user_id = 0, string $email = '' ): TaxRequest {
		$enabled = $this->tax->enabled();
		$lines   = [];
		foreach ( $state->items as $item ) {
			if ( $item->unit === null || $item->total === null || $item->product === null || $item->variant === null ) {
				continue;
			}
			$lines[] = new TaxLine(
				$item->line->key,
				TaxLine::PRODUCT,
				$item->total,
				$item->line->quantity,
				$enabled ? $this->tax->known_class( $item->product->tax_class_of( $item->variant ) ) : '',
				$item->product->tax_status_of( $item->variant ),
				$item->line->variant_id,
			);
		}
		if ( $rate !== null ) {
			$lines[] = new TaxLine( TaxService::SHIPPING_KEY, TaxLine::SHIPPING, $rate->cost, 1, '', $rate->taxable ? TaxLine::TAXABLE : TaxLine::NONE );
		}
		return new TaxRequest(
			$state->subtotal->currency,
			$enabled && Settings::bool( 'prices_include_tax' ),
			$enabled ? $location : null,
			$lines,
			$user_id,
			$email,
			$context,
			$enabled && ( new CustomerProfile() )->tax_exempt( $user_id ),
		);
	}

	/**
	 * Tax rows for a totals table: one per rate label (rates with the same
	 * label are summed, as WooCommerce does), or one "Tax" row with
	 * `tax_total_display` = single. `included` means the amounts shown
	 * already hold the tax, so the rows read "includes".
	 *
	 * @return array{enabled:bool,included:bool,pending:bool,lines:list<array{label:string,amount:array{amount:int,formatted:string},text:string}>}
	 */
	public function view( ?TaxResult $taxes, string $country ): array {
		if ( $taxes === null ) {
			return [
				'enabled'  => false,
				'included' => false,
				'pending'  => false,
				'lines'    => [],
			];
		}
		$settings = $this->tax->settings( $country );
		$lines    = [];
		if ( $settings['tax_total_display'] === 'single' ) {
			$total = $taxes->total();
			if ( ! $total->is_zero() ) {
				$lines[] = $this->line( __( 'Tax', 'flexacommerce' ), $total, $settings['tax_display_cart'] === 'incl' );
			}
		} else {
			/** @var array<string,Money> $by_label */
			$by_label = [];
			foreach ( $taxes->rates as $rate ) {
				$label              = $rate->rate->label;
				$by_label[ $label ] = isset( $by_label[ $label ] ) ? $by_label[ $label ]->add( $rate->total() ) : $rate->total();
			}
			foreach ( $by_label as $label => $amount ) {
				$lines[] = $this->line( (string) $label, $amount, $settings['tax_display_cart'] === 'incl' );
			}
		}
		return [
			'enabled'  => true,
			'included' => $settings['tax_display_cart'] === 'incl',
			'pending'  => false,
			'lines'    => $lines,
		];
	}

	/**
	 * `text` is the row as one string, for the "Includes" form.
	 *
	 * @return array{label:string,amount:array{amount:int,formatted:string},text:string}
	 */
	private function line( string $label, Money $amount, bool $included ): array {
		$money = $this->presenter->money( $amount );
		return [
			'label'  => $label,
			'amount' => $money,
			/* translators: 1: tax amount, 2: tax label such as "CA State Tax". */
			'text'   => $included ? sprintf( __( 'Includes %1$s %2$s', 'flexacommerce' ), $money['formatted'], $label ) : $label . ' ' . $money['formatted'],
		];
	}

	/**
	 * Adds the tax of the items to the public cart shape: line totals and
	 * the subtotal as `tax_display_cart` says, `totals.tax`, `totals.total`
	 * (items with tax, no shipping) and the `tax` rows. Without a location
	 * (`tax_default_location` = none and no address yet) or when a tax
	 * add-on is down, `tax.pending` says tax is calculated at checkout.
	 *
	 * @param array<string,mixed> $data CartPresenter::to_array() before its filter
	 * @return array<string,mixed>
	 */
	public function extend( array $data, CartState $state, int $user_id ): array {
		if ( ! $this->tax->enabled() || $state->cart->is_empty() ) {
			return $data;
		}
		$saved    = $state->cart->destination;
		$to       = $saved['country'] !== '' ? new TaxLocation( $saved['country'], $saved['state'], $saved['postcode'], $saved['city'] ) : null;
		$location = $this->tax->location( $to, $to );
		$pending  = [
			'enabled'  => true,
			'included' => false,
			'pending'  => true,
			'lines'    => [],
		];
		if ( $location === null ) {
			$data['tax'] = $pending;
			return $data;
		}
		$request = $this->request( $state, null, $location, TaxRequest::CART, $user_id );
		try {
			$taxes = $this->tax->calculate( $request )->result;
		} catch ( TaxUnavailable ) {
			$data['tax'] = $pending;
			return $data;
		}

		$view = $this->view( $taxes, $location->country );
		$incl = $view['included'];
		$zero = $state->subtotal->multiply( 0 );
		$net  = $zero;
		foreach ( $request->lines as $line ) {
			$net = $net->add( $taxes->line( $line->key )->net ?? $line->amount );
		}
		$items = [];
		foreach ( (array) $data['items'] as $item ) {
			$result = is_array( $item ) ? $taxes->line( (string) $item['key'] ) : null;
			if ( is_array( $item ) && $result !== null ) {
				$shown         = $incl ? $result->net->add( $result->total() ) : $result->net;
				$item['total'] = $this->presenter->money( $shown );
				$quantity      = max( 1, (int) $item['quantity'] );
				if ( is_array( $item['prices'] ) && (int) $item['prices']['active']['amount'] > 0 ) {
					// One unit at the line's rate; a line of several units can differ from quantity × unit by a cent.
					$active                    = (int) $item['prices']['active']['amount'];
					$unit                      = intdiv( $shown->amount + intdiv( $quantity, 2 ), $quantity );
					$item['prices']['regular'] = $this->presenter->money( new Money( IntMath::mul_div_half_up( (int) $item['prices']['regular']['amount'], $unit, $active ), $shown->currency ) );
					$item['prices']['active']  = $this->presenter->money( new Money( $unit, $shown->currency ) );
				}
			}
			$items[] = $item;
		}
		$tax                        = $taxes->items_total();
		$data['items']              = $items;
		$data['totals']['subtotal'] = $this->presenter->money( $incl ? $net->add( $tax ) : $net );
		$data['totals']['tax']      = $this->presenter->money( $tax );
		$data['totals']['total']    = $this->presenter->money( $net->add( $tax ) );
		$data['tax']                = $view;
		return $data;
	}
}
