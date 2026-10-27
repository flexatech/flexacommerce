<?php

declare(strict_types=1);

namespace Flexa\Commerce\Pricing\Rules;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Support\IntMath;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the pricing rules to a unit price (ADR-024). Every live rule
 * that matches the product, the customer and the quantity gives a price;
 * the lowest wins, and a rule never raises a price. Rules do not stack.
 */
final class PriceRules {
	/** @var array<int,list<string>> */
	private array $roles = [];

	public function __construct( private readonly PriceRuleRepository $rules ) {}

	/**
	 * The unit price after the rules. `$quantity` is the units of this
	 * variation in the cart, `$product_quantity` those of the whole product.
	 *
	 * @param int    $active the price before rules, in minor units
	 * @param string $now    UTC `Y-m-d H:i:s`
	 */
	public function apply( Product $product, Variant $variant, int $active, int $quantity, int $product_quantity, int $user_id, string $now ): int {
		$best = $active;
		foreach ( $this->rules->all() as $rule ) {
			if ( $rule->sale_items === PriceRule::SALE_SKIP && $variant->is_on_sale( $now ) ) {
				continue;
			}
			if ( ! $this->matches( $rule, $product, $user_id, $now ) ) {
				continue;
			}
			$change = $rule->change_for( $rule->count === PriceRule::COUNT_VARIANT ? $quantity : $product_quantity );
			if ( $change !== null ) {
				$best = min( $best, self::changed( $active, $change['mode'], $change['amount'] ) );
			}
		}
		return $best;
	}

	/**
	 * The first live bulk rule for this product and customer, for the tier
	 * table of the product page.
	 *
	 * @param string $now UTC `Y-m-d H:i:s`
	 */
	public function bulk_for( Product $product, int $user_id, string $now ): ?PriceRule {
		foreach ( $this->rules->all() as $rule ) {
			if ( $rule->type === PriceRule::BULK && $this->matches( $rule, $product, $user_id, $now ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * When the live rules for this product and customer end, soonest first:
	 * the moments its price may go up.
	 *
	 * @param string $now UTC `Y-m-d H:i:s`
	 * @return list<string> UTC `Y-m-d H:i:s`
	 */
	public function ends_for( Product $product, int $user_id, string $now ): array {
		$ends = [];
		foreach ( $this->rules->all() as $rule ) {
			if ( $rule->ends_at !== null && $this->matches( $rule, $product, $user_id, $now ) ) {
				$ends[] = $rule->ends_at;
			}
		}
		sort( $ends );
		return array_values( array_unique( $ends ) );
	}

	public static function changed( int $price, string $mode, int $amount ): int {
		return max(
			0,
			match ( $mode ) {
				PriceRule::PERCENT => $price - IntMath::mul_div_half_up( $price, min( $amount, PriceRule::FULL_PERCENT ), PriceRule::FULL_PERCENT ),
				PriceRule::FIXED   => $price - $amount,
				default            => min( $price, $amount ),
			}
		);
	}

	private function matches( PriceRule $rule, Product $product, int $user_id, string $now ): bool {
		return $rule->is_live( $now ) && $this->for_customer( $rule, $user_id ) && self::targets( $rule, $product->id );
	}

	private function for_customer( PriceRule $rule, int $user_id ): bool {
		return match ( $rule->customers ) {
			PriceRule::LOGGED_IN => $user_id > 0,
			PriceRule::ROLES     => $user_id > 0 && array_intersect( $rule->roles, $this->roles_of( $user_id ) ) !== [],
			default              => true,
		};
	}

	/** @return list<string> */
	private function roles_of( int $user_id ): array {
		if ( ! isset( $this->roles[ $user_id ] ) ) {
			$user                    = get_userdata( $user_id );
			$this->roles[ $user_id ] = $user instanceof \WP_User ? array_values( $user->roles ) : [];
		}
		return $this->roles[ $user_id ];
	}

	private static function targets( PriceRule $rule, int $product_id ): bool {
		if ( in_array( $product_id, $rule->exclude_product_ids, true ) ) {
			return false;
		}
		return match ( $rule->applies_to ) {
			PriceRule::PRODUCTS   => in_array( $product_id, $rule->product_ids, true ),
			PriceRule::CATEGORIES => array_intersect( $rule->category_ids, self::categories( $product_id ) ) !== [],
			default               => true,
		};
	}

	/** @return list<int> the product's categories and their ancestors */
	private static function categories( int $product_id ): array {
		$terms = get_the_terms( $product_id, PostTypes::CATEGORY );
		$ids   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$ids[] = $term->term_id;
			foreach ( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) as $parent ) {
				$ids[] = (int) $parent;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
