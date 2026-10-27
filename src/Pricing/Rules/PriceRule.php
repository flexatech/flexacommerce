<?php

declare(strict_types=1);

namespace Flexa\Commerce\Pricing\Rules;

use Flexa\Commerce\Support\Conditions;

defined( 'ABSPATH' ) || exit;

/**
 * An automatic price change (ADR-024): a lower unit price for some
 * products, some customers, and for a bulk rule from a quantity up. A
 * percent amount is in hundredths (2500 is 25%); a fixed amount or price
 * is in minor units. Dates are UTC `Y-m-d H:i:s`. `conditions` are the
 * cart, checkout and customer conditions of ADR-041.
 *
 * @phpstan-type Tier array{min:int,mode:string,amount:int}
 */
final class PriceRule {
	/** Every unit gets the change. */
	public const ADJUST = 'adjust';
	/** The tier for the quantity bought, the largest `min` reached. */
	public const BULK = 'bulk';
	/** Each unit at the tier its position falls in: the first 9 at full price, the 10th on at the next (ADR-042). */
	public const GRADUATED = 'graduated';
	public const TYPES     = [ self::ADJUST, self::BULK, self::GRADUATED ];

	public const PERCENT = 'percent';
	public const FIXED   = 'fixed';
	public const PRICE   = 'price';
	public const MODES   = [ self::PERCENT, self::FIXED, self::PRICE ];

	public const FULL_PERCENT = 10000;

	/** A bulk rule counts the units of the product, or of each variation alone. */
	public const COUNT_PRODUCT = 'product';
	public const COUNT_VARIANT = 'variant';
	public const COUNTS        = [ self::COUNT_PRODUCT, self::COUNT_VARIANT ];

	public const ALL_PRODUCTS = 'all';
	public const PRODUCTS     = 'products';
	public const CATEGORIES   = 'categories';
	public const SCOPES       = [ self::ALL_PRODUCTS, self::PRODUCTS, self::CATEGORIES ];

	public const EVERYONE  = 'all';
	public const LOGGED_IN = 'logged_in';
	public const ROLES     = 'roles';
	public const CUSTOMERS = [ self::EVERYONE, self::LOGGED_IN, self::ROLES ];

	/** On a sale price: change it too, or leave the row alone. */
	public const SALE_APPLY = 'apply';
	public const SALE_SKIP  = 'skip';
	public const SALE_ITEMS = [ self::SALE_APPLY, self::SALE_SKIP ];

	/**
	 * @param list<Tier>   $tiers               with BULK and GRADUATED, `min` ascending
	 * @param list<int>    $product_ids         with PRODUCTS
	 * @param list<int>    $category_ids        with CATEGORIES, children included
	 * @param list<int>    $exclude_product_ids never changed
	 * @param list<string> $roles               with ROLES
	 * @param array<mixed> $conditions          a block from Conditions::clean()
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly bool $enabled = true,
		public readonly string $type = self::ADJUST,
		public readonly string $mode = self::PERCENT,
		public readonly int $amount = 1000,
		public readonly array $tiers = [],
		public readonly string $count = self::COUNT_PRODUCT,
		public readonly string $applies_to = self::ALL_PRODUCTS,
		public readonly array $product_ids = [],
		public readonly array $category_ids = [],
		public readonly array $exclude_product_ids = [],
		public readonly string $customers = self::EVERYONE,
		public readonly array $roles = [],
		public readonly string $sale_items = self::SALE_APPLY,
		public readonly ?string $starts_at = null,
		public readonly ?string $ends_at = null,
		public readonly array $conditions = [
			'match' => Conditions::ALL,
			'items' => [],
		],
	) {}

	/** @param array<string,mixed> $row a stored row, already checked */
	public static function from_array( array $row ): self {
		$ids   = static fn ( string $key ): array => array_values( array_map( 'intval', is_array( $row[ $key ] ?? null ) ? $row[ $key ] : [] ) );
		$opt   = static fn ( string $key ): ?string => is_string( $row[ $key ] ?? null ) && $row[ $key ] !== '' ? $row[ $key ] : null;
		$one   = static fn ( string $key, array $allowed ): string => in_array( $row[ $key ] ?? null, $allowed, true ) ? (string) $row[ $key ] : $allowed[0];
		$tiers = [];
		foreach ( is_array( $row['tiers'] ?? null ) ? $row['tiers'] : [] as $tier ) {
			if ( is_array( $tier ) ) {
				$tiers[] = [
					'min'    => max( 1, (int) ( $tier['min'] ?? 1 ) ),
					'mode'   => in_array( $tier['mode'] ?? null, self::MODES, true ) ? (string) $tier['mode'] : self::PERCENT,
					'amount' => max( 0, (int) ( $tier['amount'] ?? 0 ) ),
				];
			}
		}
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			name: (string) ( $row['name'] ?? '' ),
			enabled: (bool) ( $row['enabled'] ?? true ),
			type: $one( 'type', self::TYPES ),
			mode: $one( 'mode', self::MODES ),
			amount: max( 0, (int) ( $row['amount'] ?? 0 ) ),
			tiers: $tiers,
			count: $one( 'count', self::COUNTS ),
			applies_to: $one( 'applies_to', self::SCOPES ),
			product_ids: $ids( 'product_ids' ),
			category_ids: $ids( 'category_ids' ),
			exclude_product_ids: $ids( 'exclude_product_ids' ),
			customers: $one( 'customers', self::CUSTOMERS ),
			roles: array_values( array_map( 'strval', is_array( $row['roles'] ?? null ) ? $row['roles'] : [] ) ),
			sale_items: $one( 'sale_items', self::SALE_ITEMS ),
			starts_at: $opt( 'starts_at' ),
			ends_at: $opt( 'ends_at' ),
			conditions: is_array( $row['conditions'] ?? null ) ? $row['conditions'] : Conditions::none(),
		);
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'                  => $this->id,
			'name'                => $this->name,
			'enabled'             => $this->enabled,
			'type'                => $this->type,
			'mode'                => $this->mode,
			'amount'              => $this->amount,
			'tiers'               => $this->tiers,
			'count'               => $this->count,
			'applies_to'          => $this->applies_to,
			'product_ids'         => $this->product_ids,
			'category_ids'        => $this->category_ids,
			'exclude_product_ids' => $this->exclude_product_ids,
			'customers'           => $this->customers,
			'roles'               => $this->roles,
			'sale_items'          => $this->sale_items,
			'starts_at'           => $this->starts_at,
			'ends_at'             => $this->ends_at,
			'conditions'          => $this->conditions,
		];
	}

	/** Enabled and inside its dates at `$now` (UTC `Y-m-d H:i:s`). */
	public function is_live( string $now ): bool {
		return $this->enabled
			&& ( $this->starts_at === null || $this->starts_at <= $now )
			&& ( $this->ends_at === null || $now < $this->ends_at );
	}

	/**
	 * The change for `$quantity` units, or null when a bulk rule has no
	 * tier that low.
	 *
	 * @return array{mode:string,amount:int}|null
	 */
	public function change_for( int $quantity ): ?array {
		if ( $this->type === self::ADJUST ) {
			return [
				'mode'   => $this->mode,
				'amount' => $this->amount,
			];
		}
		$found = null;
		foreach ( $this->tiers as $tier ) {
			if ( $tier['min'] <= $quantity ) {
				$found = [
					'mode'   => $tier['mode'],
					'amount' => $tier['amount'],
				];
			}
		}
		return $found;
	}

	/** Whether the rule has quantity tiers. */
	public function has_tiers(): bool {
		return $this->type === self::BULK || $this->type === self::GRADUATED;
	}
}
