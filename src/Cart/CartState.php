<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Contracts\Cart\CartView;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Coupons\Discounts;

defined( 'ABSPATH' ) || exit;

/**
 * The resolved cart returned by CartService: items, subtotal, notices.
 * `discounts` is null while the cart holds no coupon code; the subtotal is
 * before discounts.
 */
final class CartState {
	/**
	 * @param list<CartItem>                           $items
	 * @param list<array{code:string,message:string}>  $notices
	 */
	public function __construct(
		public readonly Cart $cart,
		public readonly array $items,
		public readonly Money $subtotal,
		public readonly array $notices,
		public readonly bool $has_session,
		public readonly ?Discounts $discounts = null,
	) {}

	/** All coupons together, in prices as entered. */
	public function discount(): Money {
		return $this->discounts?->total() ?? $this->subtotal->multiply( 0 );
	}

	public function discounted_subtotal(): Money {
		return $this->subtotal->subtract( $this->discount() );
	}

	/** Discount on one line, in prices as entered. */
	public function line_discount( string $key ): int {
		return $this->discounts?->line( $key ) ?? 0;
	}

	/** Whether a code in the cart does not apply; checkout refuses until it is removed. */
	public function has_rejected_coupons(): bool {
		return $this->discounts !== null && $this->discounts->rejected !== [];
	}

	public function item_count(): int {
		return $this->cart->item_count();
	}

	public function has_problems(): bool {
		foreach ( $this->items as $item ) {
			if ( ! $item->is_ok() ) {
				return true;
			}
		}
		return false;
	}

	public function view(): CartView {
		$lines = [];
		foreach ( $this->cart->lines as $line ) {
			$lines[] = [
				'key'        => $line->key,
				'product_id' => $line->product_id,
				'variant_id' => $line->variant_id,
				'quantity'   => $line->quantity,
				'attributes' => $line->attributes,
			];
		}
		return new CartView( $lines, $this->item_count(), $this->subtotal );
	}
}
