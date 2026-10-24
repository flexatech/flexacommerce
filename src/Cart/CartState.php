<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Contracts\Cart\CartView;
use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/** The resolved cart returned by CartService: items, subtotal, notices. */
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
	) {}

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
