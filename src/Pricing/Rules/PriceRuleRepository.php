<?php

declare(strict_types=1);

namespace Flexa\Commerce\Pricing\Rules;

use Flexa\Commerce\Contracts\Exceptions\InvalidPriceRule;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Support\Conditions;

defined( 'ABSPATH' ) || exit;

/**
 * The pricing rules, in list order, kept in one autoloaded option like
 * the badges: a store has a few, and every price reads them. A save takes
 * the fields it is sent over the stored rule and checks the result.
 */
final class PriceRuleRepository {
	public const OPTION = PricingService::RULES_OPTION;

	public const MAX_RULES = 100;
	public const MAX_NAME  = 60;
	public const MAX_TIERS = 10;
	public const MAX_LIST  = 500;

	/** @var list<PriceRule>|null */
	private ?array $all = null;

	/** The option value `$all` was read from; another site or a rollback gives a different one. */
	private mixed $read = null;

	/** @return list<PriceRule> */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( $this->all === null || $stored !== $this->read ) {
			$this->read = $stored;
			$this->all  = [];
			foreach ( is_array( $stored ) ? $stored : [] as $row ) {
				if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
					$this->all[] = PriceRule::from_array( $row );
				}
			}
		}
		return $this->all;
	}

	public function find( int $id ): ?PriceRule {
		foreach ( $this->all() as $rule ) {
			if ( $rule->id === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Adds a rule (`$id` 0, at the end of the list) or changes one.
	 *
	 * @param array<string,mixed> $input
	 * @throws InvalidPriceRule
	 */
	public function save( array $input, int $id = 0 ): PriceRule {
		$list = $this->all();
		if ( $id > 0 ) {
			$old = $this->find( $id );
			if ( $old === null ) {
				throw new InvalidPriceRule( __( 'Pricing rule not found.', 'flexacommerce' ), 'id' );
			}
		} else {
			if ( count( $list ) >= self::MAX_RULES ) {
				/* translators: %d: the largest number of pricing rules */
				throw new InvalidPriceRule( sprintf( __( 'A store can have up to %d pricing rules.', 'flexacommerce' ), self::MAX_RULES ) );
			}
			$old = new PriceRule( 0, '' );
			$id  = array_reduce( $list, static fn ( int $max, PriceRule $r ): int => max( $max, $r->id ), 0 ) + 1;
		}
		$rule = self::checked( array_merge( $old->to_array(), array_intersect_key( $input, $old->to_array() ), [ 'id' => $id ] ) );

		$found = false;
		foreach ( $list as $i => $existing ) {
			if ( $existing->id === $id ) {
				$list[ $i ] = $rule;
				$found      = true;
			}
		}
		if ( ! $found ) {
			$list[] = $rule;
		}
		$this->store( $list );
		return $rule;
	}

	/** @throws InvalidPriceRule */
	public function delete( int $id ): void {
		if ( $this->find( $id ) === null ) {
			throw new InvalidPriceRule( __( 'Pricing rule not found.', 'flexacommerce' ), 'id' );
		}
		$this->store( array_values( array_filter( $this->all(), static fn ( PriceRule $r ): bool => $r->id !== $id ) ) );
	}

	/**
	 * Puts the rules in the given order; ids it leaves out keep their
	 * place after the given ones.
	 *
	 * @param list<int> $ids
	 */
	public function reorder( array $ids ): void {
		$rank = array_flip( array_values( array_unique( $ids ) ) );
		$list = $this->all();
		$at   = array_flip( array_map( static fn ( PriceRule $r ): int => $r->id, $list ) );
		usort(
			$list,
			static fn ( PriceRule $a, PriceRule $b ): int => [ $rank[ $a->id ] ?? PHP_INT_MAX, $at[ $a->id ] ] <=> [ $rank[ $b->id ] ?? PHP_INT_MAX, $at[ $b->id ] ]
		);
		$this->store( $list );
	}

	/** @param list<PriceRule> $list */
	private function store( array $list ): void {
		$rows = array_map( static fn ( PriceRule $r ): array => $r->to_array(), $list );
		update_option( self::OPTION, $rows, true );
		$this->all  = $list;
		$this->read = get_option( self::OPTION, [] );
		/**
		 * Fires after the pricing rules change, so a page cache can be cleared.
		 *
		 * @param list<PriceRule> $list the rules in order
		 */
		do_action( 'flexacommerce.price_rules.saved', $list );
	}

	/**
	 * @param array<string,mixed> $in
	 * @throws InvalidPriceRule
	 */
	private static function checked( array $in ): PriceRule {
		$name = trim( sanitize_text_field( is_scalar( $in['name'] ) ? (string) $in['name'] : '' ) );
		if ( $name === '' ) {
			throw new InvalidPriceRule( __( 'Enter a name for the rule.', 'flexacommerce' ), 'name' );
		}
		if ( mb_strlen( $name ) > self::MAX_NAME ) {
			/* translators: %d: the largest number of characters */
			throw new InvalidPriceRule( sprintf( __( 'Keep the name to %d characters or fewer.', 'flexacommerce' ), self::MAX_NAME ), 'name' );
		}
		foreach (
			[
				'type'       => PriceRule::TYPES,
				'mode'       => PriceRule::MODES,
				'count'      => PriceRule::COUNTS,
				'applies_to' => PriceRule::SCOPES,
				'customers'  => PriceRule::CUSTOMERS,
				'sale_items' => PriceRule::SALE_ITEMS,
			] as $field => $allowed
		) {
			if ( ! in_array( $in[ $field ], $allowed, true ) ) {
				throw new InvalidPriceRule( __( 'Choose one of the listed options.', 'flexacommerce' ), $field );
			}
		}

		$tiers = [];
		if ( in_array( $in['type'], [ PriceRule::BULK, PriceRule::GRADUATED ], true ) ) {
			$tiers = self::tiers( $in['tiers'] );
			$mode  = PriceRule::PERCENT;
			$value = 0;
		} else {
			$mode = $in['mode'];
			if ( $in['type'] === PriceRule::FEE && $mode === PriceRule::PRICE ) {
				throw new InvalidPriceRule( __( 'A fee is a fixed amount or a percent.', 'flexacommerce' ), 'mode' );
			}
			$value = self::amount( $mode, $in['amount'], 'amount' );
		}
		$fee = $in['type'] === PriceRule::FEE;
		$cap = 0;
		if ( $fee && $mode === PriceRule::PERCENT ) {
			$cap = filter_var( $in['cap'] ?? 0, FILTER_VALIDATE_INT );
			if ( $cap === false || $cap < 0 ) {
				throw new InvalidPriceRule( __( 'Enter a largest fee of 0 or more (0 for no limit).', 'flexacommerce' ), 'cap' );
			}
		}

		foreach ( [ 'product_ids', 'category_ids', 'exclude_product_ids' ] as $field ) {
			$in[ $field ] = self::ids( $in[ $field ], $field );
		}
		if ( $in['applies_to'] === PriceRule::PRODUCTS && $in['product_ids'] === [] ) {
			throw new InvalidPriceRule( __( 'Pick at least one product.', 'flexacommerce' ), 'product_ids' );
		}
		if ( $in['applies_to'] === PriceRule::CATEGORIES && $in['category_ids'] === [] ) {
			throw new InvalidPriceRule( __( 'Pick at least one category.', 'flexacommerce' ), 'category_ids' );
		}

		$roles = [];
		if ( $in['customers'] === PriceRule::ROLES ) {
			if ( ! is_array( $in['roles'] ) ) {
				throw new InvalidPriceRule( __( 'Pick at least one role.', 'flexacommerce' ), 'roles' );
			}
			foreach ( $in['roles'] as $role ) {
				if ( ! is_string( $role ) || ! wp_roles()->is_role( $role ) ) {
					throw new InvalidPriceRule( __( 'Choose roles from the list.', 'flexacommerce' ), 'roles' );
				}
				$roles[ $role ] = $role;
			}
			if ( $roles === [] ) {
				throw new InvalidPriceRule( __( 'Pick at least one role.', 'flexacommerce' ), 'roles' );
			}
		}

		$in['starts_at'] = self::date( $in['starts_at'], 'starts_at' );
		$in['ends_at']   = self::date( $in['ends_at'], 'ends_at' );
		if ( $in['starts_at'] !== null && $in['ends_at'] !== null && $in['ends_at'] <= $in['starts_at'] ) {
			throw new InvalidPriceRule( __( 'The end must be after the start.', 'flexacommerce' ), 'ends_at' );
		}

		try {
			$conditions = Conditions::clean( $in['conditions'], $fee );
		} catch ( \InvalidArgumentException $e ) {
			throw new InvalidPriceRule( $e->getMessage(), 'conditions' );
		}

		$bulk = $tiers !== [];
		return new PriceRule(
			id: (int) $in['id'],
			name: $name,
			enabled: filter_var( $in['enabled'], FILTER_VALIDATE_BOOL ),
			type: $in['type'],
			mode: $mode,
			amount: $value,
			tiers: $tiers,
			count: $bulk ? $in['count'] : PriceRule::COUNT_PRODUCT,
			applies_to: $in['applies_to'],
			product_ids: $in['applies_to'] === PriceRule::PRODUCTS ? $in['product_ids'] : [],
			category_ids: $in['applies_to'] === PriceRule::CATEGORIES ? $in['category_ids'] : [],
			exclude_product_ids: $in['exclude_product_ids'],
			customers: $in['customers'],
			roles: array_values( $roles ),
			sale_items: $fee ? PriceRule::SALE_APPLY : $in['sale_items'],
			starts_at: $in['starts_at'],
			ends_at: $in['ends_at'],
			conditions: $conditions,
			cap: $cap,
		);
	}

	/**
	 * @return list<array{min:int,mode:string,amount:int}> sorted by `min`
	 * @throws InvalidPriceRule
	 */
	private static function tiers( mixed $value ): array {
		if ( ! is_array( $value ) || $value === [] ) {
			throw new InvalidPriceRule( __( 'Add at least one quantity tier.', 'flexacommerce' ), 'tiers' );
		}
		if ( count( $value ) > self::MAX_TIERS ) {
			/* translators: %d: the largest number of tiers */
			throw new InvalidPriceRule( sprintf( __( 'A rule can have up to %d tiers.', 'flexacommerce' ), self::MAX_TIERS ), 'tiers' );
		}
		$tiers = [];
		foreach ( $value as $tier ) {
			$min = is_array( $tier ) ? filter_var( $tier['min'] ?? null, FILTER_VALIDATE_INT ) : false;
			if ( $min === false || $min < 1 ) {
				throw new InvalidPriceRule( __( 'Enter a quantity of 1 or more for each tier.', 'flexacommerce' ), 'tiers' );
			}
			if ( isset( $tiers[ $min ] ) ) {
				throw new InvalidPriceRule( __( 'Two tiers start at the same quantity.', 'flexacommerce' ), 'tiers' );
			}
			$mode = $tier['mode'] ?? null;
			if ( ! in_array( $mode, PriceRule::MODES, true ) ) {
				throw new InvalidPriceRule( __( 'Choose one of the listed options.', 'flexacommerce' ), 'tiers' );
			}
			$tiers[ $min ] = [
				'min'    => $min,
				'mode'   => $mode,
				'amount' => self::amount( $mode, $tier['amount'] ?? null, 'tiers' ),
			];
		}
		ksort( $tiers );
		return array_values( $tiers );
	}

	/** @throws InvalidPriceRule */
	private static function amount( string $mode, mixed $value, string $field ): int {
		$amount = filter_var( $value, FILTER_VALIDATE_INT );
		if ( $mode === PriceRule::PERCENT && ( $amount === false || $amount < 1 || $amount > PriceRule::FULL_PERCENT ) ) {
			throw new InvalidPriceRule( __( 'Enter a percent above 0 and up to 100.', 'flexacommerce' ), $field );
		}
		if ( $mode === PriceRule::FIXED && ( $amount === false || $amount < 1 ) ) {
			throw new InvalidPriceRule( __( 'Enter an amount above 0.', 'flexacommerce' ), $field );
		}
		if ( $amount === false || $amount < 0 ) {
			throw new InvalidPriceRule( __( 'Enter a price of 0 or more.', 'flexacommerce' ), $field );
		}
		return $amount;
	}

	/**
	 * @return list<int>
	 * @throws InvalidPriceRule
	 */
	private static function ids( mixed $value, string $field ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidPriceRule( __( 'Expected a list of IDs.', 'flexacommerce' ), $field );
		}
		$ids = [];
		foreach ( $value as $id ) {
			$id = filter_var( $id, FILTER_VALIDATE_INT );
			if ( $id === false || $id <= 0 ) {
				throw new InvalidPriceRule( __( 'Expected a list of IDs.', 'flexacommerce' ), $field );
			}
			$ids[ $id ] = $id;
		}
		if ( count( $ids ) > self::MAX_LIST ) {
			throw new InvalidPriceRule( __( 'The list is too long.', 'flexacommerce' ), $field );
		}
		return array_values( $ids );
	}

	/** @throws InvalidPriceRule */
	private static function date( mixed $value, string $field ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$text = is_string( $value ) ? rtrim( str_replace( 'T', ' ', preg_replace( '/\.\d+Z?$/', 'Z', $value ) ?? '' ), 'Z' ) : '';
		foreach ( [ 'Y-m-d H:i:s', 'Y-m-d H:i' ] as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $text, new \DateTimeZone( 'UTC' ) );
			if ( $date !== false && $date->format( $format ) === $text ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}
		throw new InvalidPriceRule( __( 'Enter a valid date and time.', 'flexacommerce' ), $field );
	}
}
