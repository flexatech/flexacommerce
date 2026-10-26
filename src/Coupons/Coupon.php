<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

defined( 'ABSPATH' ) || exit;

/**
 * A row of fxc_coupons (doc 03 §4.17). Amounts are in the minor unit; a
 * percent coupon holds hundredths of a percent (1000 = 10 %). Dates are UTC
 * `datetime` values. The product and category rules and the allowed emails
 * live in the `rules` JSON column.
 */
final class Coupon {
	public const PERCENT       = 'percent';
	public const FIXED_CART    = 'fixed_cart';
	public const FIXED_PRODUCT = 'fixed_product';

	public const TYPES = [ self::PERCENT, self::FIXED_CART, self::FIXED_PRODUCT ];

	public const ACTIVE   = 'active';
	public const DISABLED = 'disabled';

	/** 100 % in hundredths of a percent. */
	public const FULL_PERCENT = 10000;

	public const MAX_CODE_LENGTH = 100;

	/**
	 * @param list<int>    $product_ids          products or variations the coupon applies to; empty is every product
	 * @param list<int>    $exclude_product_ids
	 * @param list<int>    $category_ids
	 * @param list<int>    $exclude_category_ids
	 * @param list<string> $emails               billing emails allowed to use it, `*` wildcards allowed; empty is everyone
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
		);
	}

	/** @return array{product_ids:list<int>,exclude_product_ids:list<int>,category_ids:list<int>,exclude_category_ids:list<int>,emails:list<string>} */
	public function rules(): array {
		return [
			'product_ids'          => $this->product_ids,
			'exclude_product_ids'  => $this->exclude_product_ids,
			'category_ids'         => $this->category_ids,
			'exclude_category_ids' => $this->exclude_category_ids,
			'emails'               => $this->emails,
		];
	}

	public function matches( string $code ): bool {
		return self::normalize( $this->code ) === self::normalize( $code );
	}

	public function has_category_rules(): bool {
		return $this->category_ids !== [] || $this->exclude_category_ids !== [];
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
			'individual_use'     => $this->individual_use,
			'exclude_sale_items' => $this->exclude_sale_items,
			'created_at'         => $this->created_at,
			'updated_at'         => $this->updated_at,
		] + $this->rules();
	}

	/** @return list<int> */
	private static function ids( mixed $value ): array {
		return is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'intval', $value ), static fn ( int $id ): bool => $id > 0 ) ) ) : [];
	}
}
