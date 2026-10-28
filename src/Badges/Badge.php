<?php

declare(strict_types=1);

namespace Flexa\Commerce\Badges;

defined( 'ABSPATH' ) || exit;

/**
 * A label drawn over product images (ADR-023): what it says and how it
 * looks, which products get it, and when. Dates are UTC `Y-m-d H:i:s`.
 * `{percent}` in the label is the largest sale discount of the product.
 */
final class Badge {
	public const POSITIONS = [ 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];
	public const SHAPES    = [ 'rounded', 'pill', 'square', 'circle' ];

	/** What the product must be: `always` asks nothing. */
	public const ALWAYS       = 'always';
	public const ON_SALE      = 'on_sale';
	public const NEW          = 'new';
	public const FEATURED     = 'featured';
	public const LOW_STOCK    = 'low_stock';
	public const OUT_OF_STOCK = 'out_of_stock';
	public const CONDITIONS   = [ self::ALWAYS, self::ON_SALE, self::NEW, self::FEATURED, self::LOW_STOCK, self::OUT_OF_STOCK ];

	public const ALL_PRODUCTS = 'all';
	public const PRODUCTS     = 'products';
	public const CATEGORIES   = 'categories';
	public const SCOPES       = [ self::ALL_PRODUCTS, self::PRODUCTS, self::CATEGORIES ];

	/** Where it shows: everywhere, the product page, or product lists. */
	public const EVERYWHERE = 'all';
	public const SINGLE     = 'single';
	public const LOOP       = 'loop';
	public const PLACES     = [ self::EVERYWHERE, self::SINGLE, self::LOOP ];

	/** Who sees it (ADR-049): everyone, signed-in shoppers, guests, or chosen roles. */
	public const EVERYONE  = 'all';
	public const LOGGED_IN = 'logged_in';
	public const GUESTS    = 'guests';
	public const ROLES     = 'roles';
	public const CUSTOMERS = [ self::EVERYONE, self::LOGGED_IN, self::GUESTS, self::ROLES ];

	public const PERCENT_TOKEN = '{percent}';

	/**
	 * @param list<int>    $product_ids         with PRODUCTS
	 * @param list<int>    $category_ids        with CATEGORIES, children included
	 * @param list<int>    $exclude_product_ids never shown on these
	 * @param list<string> $roles               with ROLES
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $label,
		public readonly string $bg_color = '#d63638',
		public readonly string $text_color = '#ffffff',
		public readonly string $position = 'top-left',
		public readonly string $shape = 'rounded',
		public readonly bool $enabled = true,
		public readonly string $condition = self::ALWAYS,
		public readonly int $new_days = 30,
		public readonly string $applies_to = self::ALL_PRODUCTS,
		public readonly array $product_ids = [],
		public readonly array $category_ids = [],
		public readonly array $exclude_product_ids = [],
		public readonly string $places = self::EVERYWHERE,
		public readonly ?string $starts_at = null,
		public readonly ?string $ends_at = null,
		public readonly string $customers = self::EVERYONE,
		public readonly array $roles = [],
	) {}

	/** @param array<string,mixed> $row a stored row, already checked */
	public static function from_array( array $row ): self {
		$ids = static fn ( string $key ): array => array_values( array_map( 'intval', is_array( $row[ $key ] ?? null ) ? $row[ $key ] : [] ) );
		$opt = static fn ( string $key ): ?string => is_string( $row[ $key ] ?? null ) && $row[ $key ] !== '' ? $row[ $key ] : null;
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			label: (string) ( $row['label'] ?? '' ),
			bg_color: (string) ( $row['bg_color'] ?? '#d63638' ),
			text_color: (string) ( $row['text_color'] ?? '#ffffff' ),
			position: in_array( $row['position'] ?? null, self::POSITIONS, true ) ? (string) $row['position'] : 'top-left',
			shape: in_array( $row['shape'] ?? null, self::SHAPES, true ) ? (string) $row['shape'] : 'rounded',
			enabled: (bool) ( $row['enabled'] ?? true ),
			condition: in_array( $row['condition'] ?? null, self::CONDITIONS, true ) ? (string) $row['condition'] : self::ALWAYS,
			new_days: max( 1, (int) ( $row['new_days'] ?? 30 ) ),
			applies_to: in_array( $row['applies_to'] ?? null, self::SCOPES, true ) ? (string) $row['applies_to'] : self::ALL_PRODUCTS,
			product_ids: $ids( 'product_ids' ),
			category_ids: $ids( 'category_ids' ),
			exclude_product_ids: $ids( 'exclude_product_ids' ),
			places: in_array( $row['places'] ?? null, self::PLACES, true ) ? (string) $row['places'] : self::EVERYWHERE,
			starts_at: $opt( 'starts_at' ),
			ends_at: $opt( 'ends_at' ),
			customers: in_array( $row['customers'] ?? null, self::CUSTOMERS, true ) ? (string) $row['customers'] : self::EVERYONE,
			roles: array_values( array_filter( is_array( $row['roles'] ?? null ) ? $row['roles'] : [], 'is_string' ) ),
		);
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'                  => $this->id,
			'label'               => $this->label,
			'bg_color'            => $this->bg_color,
			'text_color'          => $this->text_color,
			'position'            => $this->position,
			'shape'               => $this->shape,
			'enabled'             => $this->enabled,
			'condition'           => $this->condition,
			'new_days'            => $this->new_days,
			'applies_to'          => $this->applies_to,
			'product_ids'         => $this->product_ids,
			'category_ids'        => $this->category_ids,
			'exclude_product_ids' => $this->exclude_product_ids,
			'places'              => $this->places,
			'starts_at'           => $this->starts_at,
			'ends_at'             => $this->ends_at,
			'customers'           => $this->customers,
			'roles'               => $this->roles,
		];
	}

	/** Enabled and inside its dates at `$now` (UTC `Y-m-d H:i:s`). */
	public function is_live( string $now ): bool {
		return $this->enabled
			&& ( $this->starts_at === null || $this->starts_at <= $now )
			&& ( $this->ends_at === null || $now < $this->ends_at );
	}

	public function shows_in( string $place ): bool {
		return $this->places === self::EVERYWHERE || $this->places === $place;
	}

	/**
	 * Shown to a shopper signed in as `$user_id` (0: a guest) with `$roles`.
	 *
	 * @param list<string> $roles
	 */
	public function shows_to( int $user_id, array $roles ): bool {
		return match ( $this->customers ) {
			self::LOGGED_IN => $user_id > 0,
			self::GUESTS    => $user_id === 0,
			self::ROLES     => $user_id > 0 && array_intersect( $this->roles, $roles ) !== [],
			default         => true,
		};
	}
}
