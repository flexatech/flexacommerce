<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Pricing\PricingService;

defined( 'ABSPATH' ) || exit;

/**
 * The public shape of a cart, shared by `store/cart` and the storefront
 * state the cart block inlines, so the script reads the same fields from
 * either. Amounts are integers in the currency's minor unit, plus a
 * formatted string for display.
 */
final class CartPresenter {
	public function __construct(
		private readonly CartService $cart,
		private readonly PricingService $pricing,
	) {}

	/** @return array<string,mixed> */
	public function to_array( CartState $state ): array {
		$data = [
			'items'       => array_map( [ $this, 'item' ], $state->items ),
			'item_count'  => $state->item_count(),
			'totals'      => [
				'subtotal' => $this->money( $state->subtotal ),
				'currency' => $state->subtotal->currency,
			],
			'notices'     => $state->notices,
			'needs_fixes' => $state->has_problems(),
		];

		/**
		 * Filters the public shape of the cart (REST and storefront state).
		 *
		 * @param array<string,mixed> $data
		 * @param CartState           $state
		 */
		return (array) apply_filters( 'flexacommerce.rest.cart.response', $data, $state );
	}

	/** @return array<string,mixed> */
	private function item( CartItem $item ): array {
		$line    = $item->line;
		$product = $item->product;
		$image   = $item->variant->image_id ?? 0;
		if ( $image <= 0 && $product !== null ) {
			$image = (int) get_post_thumbnail_id( $product->id );
		}
		$max = $product !== null && $item->variant !== null
			? $item->max_quantity( $this->cart->line_max_quantity( $product, $item->variant ) )
			: 0;
		return [
			'key'          => $line->key,
			'product_id'   => $line->product_id,
			'variant_id'   => $line->variant_id,
			'quantity'     => $line->quantity,
			'name'         => $product !== null ? $product->title : '',
			'permalink'    => $product !== null ? (string) get_permalink( $product->id ) : '',
			'image_id'     => $image,
			'attributes'   => $line->labels,
			'status'       => $item->status,
			'max_quantity' => $max,
			'prices'       => $item->unit !== null ? [
				'regular' => $this->money( $item->unit->regular ),
				'active'  => $this->money( $item->unit->active ),
				'on_sale' => $item->unit->is_reduced(),
			] : null,
			'total'        => $item->total !== null ? $this->money( $item->total ) : null,
		];
	}

	/** @return array{amount:int,formatted:string} */
	public function money( Money $money ): array {
		return [
			'amount'    => $money->amount,
			'formatted' => $this->pricing->format( $money ),
		];
	}
}
