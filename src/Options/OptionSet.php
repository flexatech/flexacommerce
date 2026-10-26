<?php

declare(strict_types=1);

namespace Flexa\Commerce\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A named group of option fields shown on the product page of some
 * products (ADR-025), for example "Engraving" or "Gift wrap".
 */
final class OptionSet {
	public const ALL_PRODUCTS = 'all';
	public const PRODUCTS     = 'products';
	public const CATEGORIES   = 'categories';
	public const SCOPES       = [ self::ALL_PRODUCTS, self::PRODUCTS, self::CATEGORIES ];

	/**
	 * @param list<int>         $product_ids         with PRODUCTS
	 * @param list<int>         $category_ids        with CATEGORIES, children included
	 * @param list<int>         $exclude_product_ids never shown
	 * @param list<OptionField> $fields              in page order
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly bool $enabled = true,
		public readonly string $applies_to = self::ALL_PRODUCTS,
		public readonly array $product_ids = [],
		public readonly array $category_ids = [],
		public readonly array $exclude_product_ids = [],
		public readonly array $fields = [],
	) {}

	/** @param array<string,mixed> $row a stored row, already checked */
	public static function from_array( array $row ): self {
		$ids    = static fn ( string $key ): array => array_values( array_map( 'intval', is_array( $row[ $key ] ?? null ) ? $row[ $key ] : [] ) );
		$fields = [];
		foreach ( is_array( $row['fields'] ?? null ) ? $row['fields'] : [] as $field ) {
			if ( is_array( $field ) ) {
				$fields[] = OptionField::from_array( $field );
			}
		}
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			name: (string) ( $row['name'] ?? '' ),
			enabled: (bool) ( $row['enabled'] ?? true ),
			applies_to: in_array( $row['applies_to'] ?? null, self::SCOPES, true ) ? (string) $row['applies_to'] : self::ALL_PRODUCTS,
			product_ids: $ids( 'product_ids' ),
			category_ids: $ids( 'category_ids' ),
			exclude_product_ids: $ids( 'exclude_product_ids' ),
			fields: $fields,
		);
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'                  => $this->id,
			'name'                => $this->name,
			'enabled'             => $this->enabled,
			'applies_to'          => $this->applies_to,
			'product_ids'         => $this->product_ids,
			'category_ids'        => $this->category_ids,
			'exclude_product_ids' => $this->exclude_product_ids,
			'fields'              => array_map( static fn ( OptionField $f ): array => $f->to_array(), $this->fields ),
		];
	}

	public function field( int $id ): ?OptionField {
		foreach ( $this->fields as $field ) {
			if ( $field->id === $id ) {
				return $field;
			}
		}
		return null;
	}
}
