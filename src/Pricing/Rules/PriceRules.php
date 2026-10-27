<?php

declare(strict_types=1);

namespace Flexa\Commerce\Pricing\Rules;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Support\ConditionFacts;
use Flexa\Commerce\Support\Conditions;
use Flexa\Commerce\Support\IntMath;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the pricing rules to a unit price (ADR-024). Every live rule
 * that matches the product, the customer and the quantity gives a price;
 * the lowest wins, and a rule never raises a price. Rules do not stack.
 * A rule with conditions (ADR-041) applies only when they are met; one
 * that waits on a checkout choice does not apply until it is made. A
 * graduated rule (ADR-042) prices each unit by its position, so it gives
 * a line total. A fee rule (ADR-043) never changes a price: fees() gives
 * the charges it adds to the cart.
 */
final class PriceRules {
	/** @var array<int,list<string>> */
	private array $roles = [];

	/** @var array<int,ConditionFacts> */
	private array $shoppers = [];

	/** @param ?\Closure():\Flexa\Commerce\Contracts\Orders\CustomerHistory $history past orders, for the customer conditions */
	public function __construct(
		private readonly PriceRuleRepository $rules,
		private readonly ?\Closure $history = null,
	) {}

	/**
	 * The unit price after the rules, and the line total when the units
	 * cost different amounts. `$quantity` is the units of this variation in
	 * the line, `$product_quantity` those of the whole product in the cart,
	 * and `$before` the units of the product in the lines above this one,
	 * which a graduated rule that counts the product starts after (ADR-042).
	 * The lowest line total wins; a graduated total gives the average unit
	 * price, rounded half up, with the exact total next to it.
	 *
	 * @param int    $active the price before rules, in minor units
	 * @param string $now    UTC `Y-m-d H:i:s`
	 * @param ?ConditionFacts $facts the cart's facts; null on a product page
	 * @return array{0:int,1:?int} the unit price, and the exact line total or null for unit times quantity
	 */
	public function apply( Product $product, Variant $variant, int $active, int $quantity, int $product_quantity, int $user_id, string $now, ?ConditionFacts $facts = null, int $before = 0 ): array {
		$quantity = max( 1, $quantity );
		$best     = [ $active, null ];
		$lowest   = $active * $quantity;
		foreach ( $this->rules->all() as $rule ) {
			if ( $rule->is_fee() || ( $rule->sale_items === PriceRule::SALE_SKIP && $variant->is_on_sale( $now ) ) ) {
				continue;
			}
			if ( ! $this->matches( $rule, $product, $user_id, $now, $facts ) ) {
				continue;
			}
			if ( $rule->type === PriceRule::GRADUATED ) {
				$first = $rule->count === PriceRule::COUNT_VARIANT ? 1 : $before + 1;
				$total = self::graduated( $rule->tiers, $active, $first, $quantity );
				if ( $total < $lowest ) {
					$lowest = $total;
					$best   = [ IntMath::mul_div_half_up( $total, 1, $quantity ), $total % $quantity === 0 ? null : $total ];
				}
				continue;
			}
			$change = $rule->change_for( $rule->count === PriceRule::COUNT_VARIANT ? $quantity : $product_quantity );
			if ( $change !== null ) {
				$unit = self::changed( $active, $change['mode'], $change['amount'] );
				if ( $unit * $quantity < $lowest ) {
					$lowest = $unit * $quantity;
					$best   = [ $unit, null ];
				}
			}
		}
		return $best;
	}

	/**
	 * The charges of the live fee rules for this cart (ADR-043), in list
	 * order; they add up. A fee applies when the cart holds a product it
	 * targets and its conditions are met; a choice still unknown means no
	 * fee yet, so a fee for cash on delivery shows once that method is picked. A
	 * percent is of the targeted lines after coupons, as prices are
	 * entered, rounded half up and held to the cap.
	 *
	 * @param list<array{int,int}> $lines each product post id and its line total after coupons, minor units
	 * @param string               $now   UTC `Y-m-d H:i:s`
	 * @return list<Fee>
	 */
	public function fees( array $lines, string $currency, int $user_id, string $now, ConditionFacts $facts ): array {
		if ( $lines === [] ) {
			return [];
		}
		$all   = array_sum( array_column( $lines, 1 ) );
		$facts = $facts->with_subtotal( $all );
		$fees  = [];
		foreach ( $this->rules->all() as $rule ) {
			if ( ! $rule->is_fee() || ! $rule->is_live( $now ) || ! $this->for_customer( $rule, $user_id ) ) {
				continue;
			}
			$base    = 0;
			$matched = false;
			foreach ( $lines as [ $product_id, $amount ] ) {
				if ( self::targets( $rule, $product_id ) ) {
					$matched = true;
					$base   += $amount;
				}
			}
			if ( ! $matched || ( ! Conditions::is_empty( $rule->conditions ) && Conditions::passes( $rule->conditions, $facts ) !== true ) ) {
				continue;
			}
			$fee = $rule->mode === PriceRule::PERCENT ? IntMath::mul_div_half_up( $base, min( $rule->amount, PriceRule::FULL_PERCENT ), PriceRule::FULL_PERCENT ) : $rule->amount;
			if ( $rule->mode === PriceRule::PERCENT && $rule->cap > 0 ) {
				$fee = min( $fee, $rule->cap );
			}
			if ( $fee > 0 ) {
				$fees[] = new Fee( $rule->id, $rule->name, new Money( $fee, $currency ) );
			}
		}
		return $fees;
	}

	/**
	 * The total of `$quantity` units counted from position `$first`, each at
	 * the tier its position reached; positions below the first tier pay
	 * `$active`.
	 *
	 * @param list<array{min:int,mode:string,amount:int}> $tiers `min` ascending
	 */
	public static function graduated( array $tiers, int $active, int $first, int $quantity ): int {
		$last  = $first + $quantity - 1;
		$total = 0;
		$bands = [ [ 1, $active ] ];
		foreach ( $tiers as $tier ) {
			$bands[] = [ $tier['min'], self::changed( $active, $tier['mode'], $tier['amount'] ) ];
		}
		foreach ( $bands as $i => [ $min, $price ] ) {
			$max  = isset( $bands[ $i + 1 ] ) ? $bands[ $i + 1 ][0] - 1 : $last;
			$from = max( $first, $min );
			$to   = min( $last, $max );
			if ( $to >= $from ) {
				$total += ( $to - $from + 1 ) * $price;
			}
		}
		return $total;
	}

	/**
	 * The first live rule with quantity tiers for this product and
	 * customer, for the tier table of the product page.
	 *
	 * @param string $now UTC `Y-m-d H:i:s`
	 */
	public function tiered_for( Product $product, int $user_id, string $now ): ?PriceRule {
		foreach ( $this->rules->all() as $rule ) {
			if ( $rule->has_tiers() && $this->matches( $rule, $product, $user_id, $now ) ) {
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
			if ( ! $rule->is_fee() && $rule->ends_at !== null && $this->matches( $rule, $product, $user_id, $now ) ) {
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

	private function matches( PriceRule $rule, Product $product, int $user_id, string $now, ?ConditionFacts $facts = null ): bool {
		return $rule->is_live( $now ) && $this->for_customer( $rule, $user_id ) && self::targets( $rule, $product->id )
			&& ( Conditions::is_empty( $rule->conditions ) || Conditions::passes( $rule->conditions, $facts ?? $this->shopper( $user_id ) ) === true );
	}

	private function shopper( int $user_id ): ConditionFacts {
		return $this->shoppers[ $user_id ] ??= ConditionFacts::shopper( $user_id, $this->history );
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
