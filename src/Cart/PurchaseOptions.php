<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Inventory\StockService;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What a customer can choose on a product page: the variation attributes
 * with their labels and every purchasable variation with its display price
 * and stock, in the order the matcher prefers (menu order, then id). The
 * add-to-cart block inlines this as Interactivity state (up to INLINE_LIMIT
 * variations, doc 02 §7.1, budget P3b) and `store/products/{id}/variants`
 * returns the same shape. Term labels cost one query for the whole product.
 * `max` is null when stock is not limited.
 *
 * `swatch` says how the product page shows an attribute (ADR-022): '' for
 * a dropdown, or color, image or button. A colour or image swatch falls
 * back to a button for a value that has neither.
 *
 * @phpstan-type AttributeChoice array{key:string,name:string,swatch:string,options:list<array{key:string,label:string,color:string,image:string}>}
 * @phpstan-type VariantOption array{id:int,attributes:object,sku:?string,image_id:?int,in_stock:bool,max:?int,price:string,regular:string,suffix:string}
 */
final class PurchaseOptions {
	public const INLINE_LIMIT = 250;

	public function __construct(
		private readonly CartService $cart,
		private readonly PricingService $pricing,
		private readonly StockService $stock,
	) {}

	/**
	 * @return array{
	 *   product_id:int, type:string, purchasable:bool, in_stock:bool, max_quantity:int,
	 *   attributes:list<AttributeChoice>,
	 *   variants:list<VariantOption>
	 * }
	 */
	public function for_product( Product $product, int $user_id = 0 ): array {
		$attributes = $product->is_variable() ? $this->attributes( $product ) : [];
		$variants   = [];
		$any        = false;
		foreach ( $product->sellable_rows() as $row ) {
			if ( ! $this->cart->is_purchasable( $product, $row ) ) {
				continue;
			}
			$entry = $this->variant( $product, $row, $user_id );
			if ( $entry === null ) {
				continue;
			}
			$any        = $any || $entry['in_stock'];
			$variants[] = $entry;
		}

		return [
			'product_id'   => $product->id,
			'type'         => $product->type,
			'purchasable'  => $variants !== [],
			'in_stock'     => $any,
			'max_quantity' => $this->cart->line_max_quantity( $product, $product->base ),
			'attributes'   => $attributes,
			'variants'     => $variants,
		];
	}

	/** @return VariantOption|null */
	private function variant( Product $product, Variant $row, int $user_id ): ?array {
		$unit = $this->pricing->unit_price( $product, $row, 1, $user_id );
		if ( $unit === null ) {
			return null;
		}
		$suffix       = $this->pricing->display_suffix( $product, $row, $unit );
		$unit         = $this->pricing->for_display( $product, $row, $unit );
		$availability = $this->stock->availability( $product, $row );
		$max          = $availability->max_quantity();
		return [
			'id'         => $row->id,
			'attributes' => (object) $row->combination->to_array(),
			'sku'        => $row->sku,
			'image_id'   => $row->image_id,
			'in_stock'   => $availability->is_in_stock(),
			'max'        => $max,
			'price'      => $this->pricing->format( $unit->active ),
			'regular'    => $unit->is_reduced() ? $this->pricing->format( $unit->regular ) : '',
			'suffix'     => $suffix,
		];
	}

	/** @return list<AttributeChoice> */
	private function attributes( Product $product ): array {
		$term_ids = [];
		foreach ( $product->variation_attributes() as $definition ) {
			if ( $definition->is_global() ) {
				$term_ids[] = $definition->attribute_term_id;
				$term_ids   = array_merge( $term_ids, $definition->value_term_ids );
			}
		}
		if ( $term_ids !== [] ) {
			_prime_term_caches( array_values( array_unique( $term_ids ) ) );
		}

		$out = [];
		foreach ( $product->variation_attributes() as $definition ) {
			if ( $definition->is_global() ) {
				$term    = get_term( $definition->attribute_term_id );
				$swatch  = AttributeRepository::swatch_of( $definition->attribute_term_id );
				$options = [];
				foreach ( $definition->value_term_ids as $id ) {
					$value     = get_term( $id );
					$look      = $swatch !== AttributeRepository::DROPDOWN ? AttributeRepository::look( $id ) : null;
					$image     = $swatch === AttributeRepository::IMAGE && $look !== null && $look['image_id'] > 0 ? wp_get_attachment_image_url( $look['image_id'], 'thumbnail' ) : false;
					$options[] = [
						'key'   => (string) $id,
						'label' => $value instanceof \WP_Term ? $value->name : (string) $id,
						'color' => $swatch === AttributeRepository::COLOR && $look !== null ? $look['color'] : '',
						'image' => is_string( $image ) ? $image : '',
					];
				}
				$out[] = [
					'key'     => $definition->key,
					'name'    => $term instanceof \WP_Term ? $term->name : '',
					'swatch'  => $swatch,
					'options' => $options,
				];
			} else {
				$out[] = [
					'key'     => $definition->key,
					'name'    => $definition->name,
					'swatch'  => Settings::string( 'swatch_custom' ) === 'button' ? AttributeRepository::BUTTON : AttributeRepository::DROPDOWN,
					'options' => array_map(
						static fn ( string $key ): array => [
							'key'   => $key,
							'label' => (string) $definition->local_label( $key ),
							'color' => '',
							'image' => '',
						],
						$definition->value_keys()
					),
				];
			}
		}
		return $out;
	}
}
