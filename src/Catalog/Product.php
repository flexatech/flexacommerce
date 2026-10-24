<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * A product post with its catalog data: type, attribute definitions, the
 * base row and (for variable products) the variation rows.
 */
final class Product {
	public const VISIBILITIES = [ 'visible', 'catalog', 'search', 'hidden' ];

	/**
	 * @param list<AttributeDefinition> $attributes sorted by position
	 * @param list<Variant>             $variations
	 * @param list<int>                 $gallery_ids
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $status,
		public readonly string $title,
		public readonly array $attributes,
		public readonly Variant $base,
		public readonly array $variations,
		public readonly array $gallery_ids = [],
		public readonly string $visibility = 'visible',
		public readonly bool $featured = false,
	) {}

	public function is_variable(): bool {
		return ProductTypes::has_variations( $this->type );
	}

	public function attribute( string $key ): ?AttributeDefinition {
		foreach ( $this->attributes as $attribute ) {
			if ( $attribute->key === $key ) {
				return $attribute;
			}
		}
		return null;
	}

	/** @return list<AttributeDefinition> */
	public function variation_attributes(): array {
		return array_values( array_filter( $this->attributes, static fn ( AttributeDefinition $a ): bool => $a->variation ) );
	}

	/**
	 * Rows a customer can buy: the base row of a simple product, or the variations.
	 *
	 * @return list<Variant>
	 */
	public function sellable_rows(): array {
		return $this->is_variable() ? $this->variations : [ $this->base ];
	}

	/** The row that holds stock for a sellable row (doc 03 §3.4). */
	public function stock_owner( Variant $variant ): Variant {
		return $variant->stock_from_base && ! $variant->is_base ? $this->base : $variant;
	}

	/** @return list<Variant> base row first */
	public function rows(): array {
		return [ $this->base, ...$this->variations ];
	}
}
