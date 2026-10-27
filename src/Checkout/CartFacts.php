<?php

declare(strict_types=1);

namespace Flexa\Commerce\Checkout;

use Flexa\Commerce\Cart\CartState;

defined( 'ABSPATH' ) || exit;

/**
 * What the conditions of a checkout field look at in the cart (doc 11
 * §11): whether it ships, its products and their categories with the
 * parents of each.
 *
 * @phpstan-import-type When from CheckoutLayout
 * @api
 */
final class CartFacts {
	/**
	 * @param list<int> $product_ids
	 * @param list<int> $category_ids
	 */
	public function __construct(
		public readonly bool $needs_shipping,
		public readonly array $product_ids = [],
		public readonly array $category_ids = [],
	) {}

	/** Categories are read only when a field of the layout names one, so most checkouts make no term query. */
	public static function from_state( CartState $state, bool $needs_shipping ): self {
		$categories = CheckoutLayout::current()->names_categories() ? $state->category_ids() : [];
		return new self( $needs_shipping, $state->product_ids(), $categories );
	}

	/** @param array<mixed> $data to_array() */
	public static function from_array( array $data ): self {
		$ids = static fn ( mixed $list ): array => array_values( array_map( 'intval', is_array( $list ) ? $list : [] ) );
		return new self( ! empty( $data['needs_shipping'] ), $ids( $data['products'] ?? [] ), $ids( $data['categories'] ?? [] ) );
	}

	/** @return array{needs_shipping:bool,products:list<int>,categories:list<int>} */
	public function to_array(): array {
		return [
			'needs_shipping' => $this->needs_shipping,
			'products'       => $this->product_ids,
			'categories'     => $this->category_ids,
		];
	}

	/**
	 * The cart side of a field's conditions; the country is checked by the
	 * field itself, since the customer can change it on the page.
	 *
	 * @param When $when
	 */
	public function allows( array $when ): bool {
		if ( $when['shipping'] === CheckoutLayout::SHIPS && ! $this->needs_shipping ) {
			return false;
		}
		if ( $when['shipping'] === CheckoutLayout::NO_SHIPPING && $this->needs_shipping ) {
			return false;
		}
		if ( $when['products'] === [] && $when['categories'] === [] ) {
			return true;
		}
		return array_intersect( $when['products'], $this->product_ids ) !== [] || array_intersect( $when['categories'], $this->category_ids ) !== [];
	}
}
