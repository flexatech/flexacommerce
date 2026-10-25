<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Catalog\VariantView;

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

	/** Sellable row by id, or null when the id is not a row a customer can buy. */
	public function sellable( int $variant_id ): ?Variant {
		foreach ( $this->sellable_rows() as $row ) {
			if ( $row->id === $variant_id ) {
				return $row;
			}
		}
		return null;
	}

	/** Public read-only view of a row for extension hooks. */
	public function view_of( Variant $variant ): VariantView {
		$owner = $this->stock_owner( $variant );
		return new VariantView(
			id: $variant->id,
			product_id: $this->id,
			product_type: $this->type,
			is_base: $variant->is_base,
			sku: $variant->sku,
			attributes: $variant->combination->to_array(),
			regular_price: $variant->regular_price,
			sale_price: $variant->sale_price,
			manage_stock: $owner->manage_stock,
			stock_quantity: $owner->manage_stock ? $owner->stock_quantity : null,
			stock_status: $owner->computed_stock_status(),
			tax_class: $this->tax_class_of( $variant ),
			tax_status: $this->tax_status_of( $variant ),
		);
	}

	/** A variation with class `parent` takes the base row's class (doc 02 §7.12.2). */
	public function tax_class_of( Variant $variant ): string {
		$class = $variant->is_base || $variant->tax_class === Variant::PARENT_CLASS ? $this->base->tax_class : $variant->tax_class;
		return $class === Variant::PARENT_CLASS ? '' : $class;
	}

	/** Read from the base row only, as WooCommerce does for variations. */
	public function tax_status_of( Variant $variant ): string {
		unset( $variant );
		return $this->base->tax_status;
	}
}
