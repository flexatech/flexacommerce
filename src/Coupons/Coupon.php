<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

use Flexa\Commerce\Support\Conditions;

defined( 'ABSPATH' ) || exit;

/**
 * A row of fxc_coupons (doc 03 §4.17). Amounts are in the minor unit; a
 * percent coupon holds hundredths of a percent (1000 = 10 %). Dates are UTC
 * `datetime` values. The product and category rules and the allowed emails
 * live in the `rules` JSON column. A free-shipping coupon turns on the
 * free shipping methods that ask for one; its amount may then be zero.
 * A percent or per-product coupon may be limited to a number of items, the
 * dearest first. A buy X get Y coupon takes a percentage (its amount) off
 * the cheapest units the shopper gets for each X units they buy.
 * An automatic coupon (ADR-026) has no code to type: it applies to every
 * cart it fits, and its code is the name the shopper sees.
 * `conditions` (ADR-041, in `rules` too) add cart, checkout and customer
 * conditions. A bundle coupon (ADR-045) discounts each complete set of its
 * products: one unit of every product or variation it names. Its
 * `bundle_mode` says what the amount is: a percentage off the set, an
 * amount off each set, or the price a set costs.
 */
final class Coupon {
	public const PERCENT       = 'percent';
	public const FIXED_CART    = 'fixed_cart';
	public const FIXED_PRODUCT = 'fixed_product';
	public const BUY_X_GET_Y   = 'buy_x_get_y';
	public const BUNDLE        = 'bundle';

	public const TYPES = [ self::PERCENT, self::FIXED_CART, self::FIXED_PRODUCT, self::BUY_X_GET_Y, self::BUNDLE ];

	/** What the amount of a bundle coupon is. */
	public const BUNDLE_PERCENT = 'percent';
	public const BUNDLE_AMOUNT  = 'amount';
	public const BUNDLE_PRICE   = 'price';

	public const BUNDLE_MODES = [ self::BUNDLE_PERCENT, self::BUNDLE_AMOUNT, self::BUNDLE_PRICE ];

	/** Products and variations a bundle can hold. */
	public const MAX_BUNDLE = 20;

	public const ACTIVE   = 'active';
	public const DISABLED = 'disabled';

	/** 100 % in hundredths of a percent. */
	public const FULL_PERCENT = 10000;

	public const MAX_CODE_LENGTH = 100;

	/**
	 * @param list<int>    $product_ids          products (post ids) the coupon applies to, with every variation; empty with no $variant_ids is every product
	 * @param list<int>    $exclude_product_ids
	 * @param list<int>    $category_ids
	 * @param list<int>    $exclude_category_ids
	 * @param list<string> $emails               billing emails allowed to use it, `*` wildcards allowed; empty is everyone
	 * @param list<int>    $variant_ids          single variations (fxc_variants ids) the coupon applies to
	 * @param list<int>    $exclude_variant_ids
	 * @param int          $item_limit           units a percent or per-product coupon discounts at most, the dearest first; 0 is every unit
	 * @param int          $buy_qty              buy X get Y: units bought per set (X)
	 * @param int          $get_qty              buy X get Y: units discounted per set (Y)
	 * @param int          $repeat_limit         buy X get Y and bundle: sets one cart gets at most; 0 is as many as it holds
	 * @param list<int>    $get_product_ids      buy X get Y: products that can be the discounted units; with no get rules, the bought ones
	 * @param list<int>    $get_variant_ids
	 * @param list<int>    $get_category_ids
	 * @param array<mixed> $conditions           a block from Conditions::clean()
	 * @param string       $bundle_mode          bundle: one of BUNDLE_MODES; empty for other types
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $code,
		public readonly string $type,
		public readonly int $amount,
		public readonly string $status = self::ACTIVE,
		public readonly string $description = '',
		public readonly ?string $starts_at = null,
		public readonly ?string $expires_at = null,
		public readonly ?int $min_subtotal = null,
		public readonly ?int $max_subtotal = null,
		public readonly int $usage_limit = 0,
		public readonly int $usage_count = 0,
		public readonly int $per_customer_limit = 0,
		public readonly bool $individual_use = false,
		public readonly bool $exclude_sale_items = false,
		public readonly array $product_ids = [],
		public readonly array $exclude_product_ids = [],
		public readonly array $category_ids = [],
		public readonly array $exclude_category_ids = [],
		public readonly array $emails = [],
		public readonly string $created_at = '',
		public readonly string $updated_at = '',
		public readonly bool $free_shipping = false,
		public readonly array $variant_ids = [],
		public readonly array $exclude_variant_ids = [],
		public readonly int $item_limit = 0,
		public readonly int $buy_qty = 0,
		public readonly int $get_qty = 0,
		public readonly int $repeat_limit = 0,
		public readonly array $get_product_ids = [],
		public readonly array $get_variant_ids = [],
		public readonly array $get_category_ids = [],
		public readonly bool $automatic = false,
		public readonly array $conditions = [
			'match' => Conditions::ALL,
			'items' => [],
		],
		public readonly string $bundle_mode = '',
	) {}

	/** The form a shopper's code is compared in: trimmed, lower case. */
	public static function normalize( string $code ): string {
		return mb_strtolower( trim( $code ) );
	}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$rules = is_string( $row['rules'] ?? null ) ? json_decode( $row['rules'], true ) : null;
		$rules = is_array( $rules ) ? $rules : [];
		return new self(
			id: (int) $row['id'],
			code: (string) $row['code'],
			type: (string) $row['type'],
			amount: (int) $row['amount'],
			status: (string) ( $row['status'] ?? self::ACTIVE ),
			description: (string) ( $row['description'] ?? '' ),
			starts_at: isset( $row['starts_at'] ) ? (string) $row['starts_at'] : null,
			expires_at: isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null,
			min_subtotal: isset( $row['min_subtotal'] ) ? (int) $row['min_subtotal'] : null,
			max_subtotal: isset( $row['max_subtotal'] ) ? (int) $row['max_subtotal'] : null,
			usage_limit: (int) ( $row['usage_limit'] ?? 0 ),
			usage_count: (int) ( $row['usage_count'] ?? 0 ),
			per_customer_limit: (int) ( $row['per_customer_limit'] ?? 0 ),
			individual_use: (bool) ( $row['individual_use'] ?? false ),
			exclude_sale_items: (bool) ( $row['exclude_sale_items'] ?? false ),
			product_ids: self::ids( $rules['product_ids'] ?? [] ),
			exclude_product_ids: self::ids( $rules['exclude_product_ids'] ?? [] ),
			category_ids: self::ids( $rules['category_ids'] ?? [] ),
			exclude_category_ids: self::ids( $rules['exclude_category_ids'] ?? [] ),
			emails: array_values( array_map( 'strval', is_array( $rules['emails'] ?? null ) ? $rules['emails'] : [] ) ),
			created_at: (string) ( $row['created_at'] ?? '' ),
			updated_at: (string) ( $row['updated_at'] ?? '' ),
			free_shipping: (bool) ( $row['free_shipping'] ?? false ),
			variant_ids: self::ids( $rules['variant_ids'] ?? [] ),
			exclude_variant_ids: self::ids( $rules['exclude_variant_ids'] ?? [] ),
			item_limit: (int) ( $row['item_limit'] ?? 0 ),
			buy_qty: max( 0, (int) ( $rules['buy_qty'] ?? 0 ) ),
			get_qty: max( 0, (int) ( $rules['get_qty'] ?? 0 ) ),
			repeat_limit: max( 0, (int) ( $rules['repeat_limit'] ?? 0 ) ),
			get_product_ids: self::ids( $rules['get_product_ids'] ?? [] ),
			get_variant_ids: self::ids( $rules['get_variant_ids'] ?? [] ),
			get_category_ids: self::ids( $rules['get_category_ids'] ?? [] ),
			automatic: (bool) ( $row['automatic'] ?? false ),
			conditions: is_array( $rules['conditions'] ?? null ) ? $rules['conditions'] : Conditions::none(),
			bundle_mode: (string) $row['type'] !== self::BUNDLE ? '' : ( in_array( $rules['bundle_mode'] ?? null, self::BUNDLE_MODES, true ) ? (string) $rules['bundle_mode'] : self::BUNDLE_PERCENT ),
		);
	}

	/** @return array{product_ids:list<int>,exclude_product_ids:list<int>,variant_ids:list<int>,exclude_variant_ids:list<int>,category_ids:list<int>,exclude_category_ids:list<int>,emails:list<string>,buy_qty:int,get_qty:int,repeat_limit:int,get_product_ids:list<int>,get_variant_ids:list<int>,get_category_ids:list<int>,conditions:array<mixed>,bundle_mode:string} */
	public function rules(): array {
		return [
			'product_ids'          => $this->product_ids,
			'exclude_product_ids'  => $this->exclude_product_ids,
			'variant_ids'          => $this->variant_ids,
			'exclude_variant_ids'  => $this->exclude_variant_ids,
			'category_ids'         => $this->category_ids,
			'exclude_category_ids' => $this->exclude_category_ids,
			'emails'               => $this->emails,
			'buy_qty'              => $this->buy_qty,
			'get_qty'              => $this->get_qty,
			'repeat_limit'         => $this->repeat_limit,
			'get_product_ids'      => $this->get_product_ids,
			'get_variant_ids'      => $this->get_variant_ids,
			'get_category_ids'     => $this->get_category_ids,
			'conditions'           => $this->conditions,
			'bundle_mode'          => $this->bundle_mode,
		];
	}

	public function matches( string $code ): bool {
		return self::normalize( $this->code ) === self::normalize( $code );
	}

	/** Whether the coupon is limited to some products or variations. */
	public function has_product_rules(): bool {
		return $this->product_ids !== [] || $this->variant_ids !== [];
	}

	/** Whether a buy X get Y coupon names its own discounted units. */
	public function has_get_rules(): bool {
		return $this->get_product_ids !== [] || $this->get_variant_ids !== [] || $this->get_category_ids !== [];
	}

	/** Whether the amount is hundredths of a percent rather than money. */
	public function is_percentage(): bool {
		return self::percent_type( $this->type, $this->bundle_mode );
	}

	/** Whether a coupon of this type (and bundle mode) has its amount in hundredths of a percent. */
	public static function percent_type( string $type, string $bundle_mode = '' ): bool {
		return $type === self::PERCENT || $type === self::BUY_X_GET_Y || ( $type === self::BUNDLE && $bundle_mode !== self::BUNDLE_AMOUNT && $bundle_mode !== self::BUNDLE_PRICE );
	}

	/** Units in one set: X + Y for buy X get Y, the products for a bundle; 0 for other types. */
	public function set_size(): int {
		return match ( $this->type ) {
			self::BUY_X_GET_Y => $this->buy_qty + $this->get_qty,
			self::BUNDLE      => count( $this->product_ids ) + count( $this->variant_ids ),
			default           => 0,
		};
	}

	public function has_category_rules(): bool {
		return $this->category_ids !== [] || $this->exclude_category_ids !== [] || $this->get_category_ids !== [];
	}

	/** Whether a billing email may use the coupon; `*` matches any run of characters. */
	public function allows_email( string $email ): bool {
		if ( $this->emails === [] ) {
			return true;
		}
		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return false;
		}
		foreach ( $this->emails as $allowed ) {
			$pattern = '/^' . str_replace( '\*', '.*', preg_quote( strtolower( trim( $allowed ) ), '/' ) ) . '$/';
			if ( preg_match( $pattern, $email ) === 1 ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'                 => $this->id,
			'code'               => $this->code,
			'type'               => $this->type,
			'amount'             => $this->amount,
			'status'             => $this->status,
			'description'        => $this->description,
			'starts_at'          => $this->starts_at,
			'expires_at'         => $this->expires_at,
			'min_subtotal'       => $this->min_subtotal,
			'max_subtotal'       => $this->max_subtotal,
			'usage_limit'        => $this->usage_limit,
			'usage_count'        => $this->usage_count,
			'per_customer_limit' => $this->per_customer_limit,
			'item_limit'         => $this->item_limit,
			'individual_use'     => $this->individual_use,
			'exclude_sale_items' => $this->exclude_sale_items,
			'free_shipping'      => $this->free_shipping,
			'automatic'          => $this->automatic,
			'created_at'         => $this->created_at,
			'updated_at'         => $this->updated_at,
		] + $this->rules();
	}

	/** @return list<int> */
	private static function ids( mixed $value ): array {
		return is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'intval', $value ), static fn ( int $id ): bool => $id > 0 ) ) ) : [];
	}
}
