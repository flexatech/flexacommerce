<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\Cart;
use Flexa\Commerce\Cart\CartItem;
use Flexa\Commerce\Cart\CartPresenter;
use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Cart\CartState;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Coupons\Discounts;
use Flexa\Commerce\Pricing\Rules\PriceRule;

defined( 'ABSPATH' ) || exit;

/**
 * The rule simulator (ADR-046): a cart the merchant makes up, priced and
 * quoted by the code that prices a shopper's cart, never stored. It says
 * what each price rule did to each line, what each fee rule and coupon
 * did to the cart, and gives the checkout totals, so a merchant sees why
 * a discount did or did not apply before a customer does.
 */
final class Simulator {
	public const MAX_LINES = 20;

	private string $currency = '';

	/** @param ?\Closure():CouponService $coupons */
	public function __construct(
		private readonly CartService $cart,
		private readonly CheckoutService $checkout,
		private readonly CartPresenter $presenter,
		private readonly ?\Closure $coupons = null,
	) {}

	/**
	 * @param array{lines?:list<array{product_id:int,variant_id?:int,quantity?:int}>,customer_id?:int,country?:string,state?:string,postcode?:string,city?:string,shipping_rate?:string,payment_method?:string,coupons?:list<string>,final?:bool} $input
	 * @return array<string,mixed>
	 */
	public function run( array $input ): array {
		$user_id  = (int) ( $input['customer_id'] ?? 0 );
		$user_id  = $user_id > 0 && get_userdata( $user_id ) instanceof \WP_User ? $user_id : 0;
		$final    = (bool) ( $input['final'] ?? false );
		$lines    = [];
		$problems = [];
		foreach ( array_slice( $input['lines'] ?? [], 0, self::MAX_LINES ) as $i => $entry ) {
			try {
				$line = $this->cart->preview_line( (int) $entry['product_id'], (int) ( $entry['variant_id'] ?? 0 ), (int) ( $entry['quantity'] ?? 1 ) );
			} catch ( CommerceException $e ) {
				$problems[] = sprintf(
					/* translators: 1: line number, 2: why the product cannot be added. */
					__( 'Line %1$d: %2$s', 'flexacommerce' ),
					$i + 1,
					$e->getMessage()
				);
				continue;
			}
			// The same product twice is one line, as in a cart.
			$lines[ $line->key ] = isset( $lines[ $line->key ] ) ? $lines[ $line->key ]->with_quantity( $lines[ $line->key ]->quantity + $line->quantity ) : $line;
		}

		$codes = [];
		foreach ( $input['coupons'] ?? [] as $code ) {
			$code = Coupon::normalize( (string) $code );
			if ( $code !== '' && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}
		$destination = new Destination(
			strtoupper( (string) ( $input['country'] ?? '' ) ),
			(string) ( $input['state'] ?? '' ),
			(string) ( $input['postcode'] ?? '' ),
			(string) ( $input['city'] ?? '' )
		);
		$cart        = new Cart(
			lines: array_values( $lines ),
			shipping_rate: (string) ( $input['shipping_rate'] ?? '' ),
			coupons: array_slice( $codes, 0, CouponService::MAX_CODES ),
			payment_method: (string) ( $input['payment_method'] ?? '' ),
		);

		[ $state, $quote, $method ] = $this->checkout->preview( $cart, $user_id, $destination, $final );
		$trace                      = $this->cart->explain( $state, $user_id, $final );
		$this->currency             = $state->subtotal->currency;

		$rates = [];
		foreach ( $quote->rates as $rate ) {
			$rates[] = [
				'id'    => $rate->id,
				'label' => $rate->label,
				'cost'  => $this->presenter->money( $rate->cost ),
			];
		}
		return [
			'customer_id'        => $user_id,
			'lines'              => array_map( fn ( CartItem $item ): array => $this->line( $state, $item, $trace['lines'][ $item->line->key ] ?? null ), $state->items ),
			'fees'               => array_map( [ $this, 'rule' ], $trace['fees'] ),
			'coupons'            => $this->coupons( $state, $user_id, $final ),
			'next_discount'      => $state->discounts !== null ? $this->presenter->next_discount( $state ) : null,
			'shipping_rates'     => $rates,
			'shipping_rate'      => $quote->rate->id ?? '',
			'needs_shipping'     => $quote->needs_shipping,
			'payment_method'     => $method,
			'prices_include_tax' => $quote->prices_include_tax,
			'totals'             => [
				'subtotal' => $this->presenter->money( $state->subtotal ),
				'discount' => $this->presenter->money( $state->discount() ),
				'fees'     => $this->presenter->money( $state->fee_total() ),
				'shipping' => $this->presenter->money( $quote->shipping ),
				'tax'      => $this->presenter->money( $quote->tax ),
				'total'    => $this->presenter->money( $quote->total ),
			],
			'problems'           => $problems,
			'notices'            => array_values( array_unique( array_column( $state->notices, 'message' ) ) ),
		];
	}

	/**
	 * @param ?array{active:int,rules:list<array{rule:PriceRule,status:string,total:?int,condition:?array<string,mixed>}>} $trace
	 * @return array<string,mixed>
	 */
	private function line( CartState $state, CartItem $item, ?array $trace ): array {
		$labels = array_map( static fn ( array $label ): string => $label['name'] . ': ' . $label['value'], $item->line->labels );
		return [
			'key'        => $item->line->key,
			'product_id' => $item->line->product_id,
			'variant_id' => $item->line->variant_id,
			'name'       => ( $item->product->title ?? '' ) . ( $labels !== [] ? ' (' . implode( ', ', $labels ) . ')' : '' ),
			'quantity'   => $item->line->quantity,
			'status'     => $item->status,
			'regular'    => $item->unit !== null ? $this->presenter->money( $item->unit->regular ) : null,
			'before'     => $trace !== null ? $this->presenter->money( $this->money( $trace['active'] ) ) : null,
			'unit'       => $item->unit !== null ? $this->presenter->money( $item->unit->active ) : null,
			'total'      => $item->total !== null ? $this->presenter->money( $item->total ) : null,
			'discount'   => $this->presenter->money( $this->money( $state->line_discount( $item->line->key ) ) ),
			'rules'      => array_map( [ $this, 'rule' ], $trace['rules'] ?? [] ),
		];
	}

	/**
	 * @param array{rule:PriceRule,status:string,total:?int,condition:?array<string,mixed>} $entry
	 * @return array<string,mixed>
	 */
	private function rule( array $entry ): array {
		return [
			'id'        => $entry['rule']->id,
			'name'      => $entry['rule']->name,
			'type'      => $entry['rule']->type,
			'status'    => $entry['status'],
			'amount'    => $entry['total'] !== null ? $this->presenter->money( $this->money( $entry['total'] ) ) : null,
			'condition' => $entry['condition'],
		];
	}

	/**
	 * Each code in the order given, then each automatic coupon in the order
	 * tried: `applied` with what it took off, `rejected`, `waiting` for a
	 * checkout choice, or `skipped` (automatic ones), with the reason.
	 *
	 * @return list<array{code:string,automatic:bool,status:string,amount:?array{amount:int,formatted:string},message:string}>
	 */
	private function coupons( CartState $state, int $user_id, bool $final ): array {
		$discounts = $state->discounts;
		$rows      = [];
		foreach ( $state->cart->coupons as $code ) {
			$applied = $discounts?->find( $code );
			if ( $applied !== null && $applied->coupon->automatic ) {
				continue;
			}
			$status = match ( true ) {
				$applied !== null                    => 'applied',
				isset( $discounts->waiting[ $code ] ) => 'waiting',
				default                              => 'rejected',
			};
			$rows[] = [
				'code'      => $applied->coupon->code ?? $code,
				'automatic' => false,
				'status'    => $status,
				'amount'    => $applied !== null ? $this->presenter->money( $this->money( $applied->total() ) ) : null,
				'message'   => (string) ( $discounts->rejected[ $code ] ?? $discounts->waiting[ $code ] ?? '' ),
			];
		}
		foreach ( $discounts->applied ?? [] as $applied ) {
			if ( $applied->coupon->automatic ) {
				$rows[] = [
					'code'      => $applied->coupon->code,
					'automatic' => true,
					'status'    => 'applied',
					'amount'    => $this->presenter->money( $this->money( $applied->total() ) ),
					'message'   => '',
				];
			}
		}
		if ( $this->coupons !== null && $state->items !== [] ) {
			$service = ( $this->coupons )();
			$skipped = $service->skipped( $discounts ?? new Discounts( $state->subtotal->currency ), $state->items, $state->subtotal, $user_id, $this->cart->facts( $state->cart, $user_id, $final ) );
			foreach ( $service->repository()->automatic() as $coupon ) {
				if ( isset( $skipped[ $coupon->id ] ) ) {
					$rows[] = [
						'code'      => $coupon->code,
						'automatic' => true,
						'status'    => 'skipped',
						'amount'    => null,
						'message'   => $skipped[ $coupon->id ],
					];
				}
			}
		}
		return $rows;
	}

	private function money( int $amount ): Money {
		return new Money( $amount, $this->currency );
	}
}
