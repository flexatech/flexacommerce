<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\Cart\CartView;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Coupons\Discounts;
use Flexa\Commerce\Pricing\Rules\Fee;

defined( 'ABSPATH' ) || exit;

/**
 * The resolved cart returned by CartService: items, subtotal, notices.
 * `discounts` is null while the cart holds no coupon code; the subtotal is
 * before discounts. `fees` are the charges of the fee rules (ADR-043).
 */
final class CartState {
	/**
	 * @param list<CartItem>                           $items
	 * @param list<array{code:string,message:string}>  $notices
	 * @param list<Fee>                                $fees
	 */
	public function __construct(
		public readonly Cart $cart,
		public readonly array $items,
		public readonly Money $subtotal,
		public readonly array $notices,
		public readonly bool $has_session,
		public readonly ?Discounts $discounts = null,
		public readonly array $fees = [],
	) {}

	/** All fees together; they carry no tax. */
	public function fee_total(): Money {
		$sum = $this->subtotal->multiply( 0 );
		foreach ( $this->fees as $fee ) {
			$sum = $sum->add( $fee->amount );
		}
		return $sum;
	}

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

	/** @return list<int> the products in the cart, each once */
	public function product_ids(): array {
		$ids = [];
		foreach ( $this->items as $item ) {
			$ids[ $item->line->product_id ] = $item->line->product_id;
		}
		return array_values( $ids );
	}

	/**
	 * The categories of the products in the cart, with the parents of each.
	 * It reads the terms, so callers ask only when they need them.
	 *
	 * @return list<int>
	 */
	public function category_ids(): array {
		$ids = [];
		foreach ( $this->product_ids() as $product ) {
			$terms = get_the_terms( $product, PostTypes::CATEGORY );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$ids[ $term->term_id ] = $term->term_id;
				foreach ( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) as $parent ) {
					$ids[ (int) $parent ] = (int) $parent;
				}
			}
		}
		return array_values( $ids );
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
