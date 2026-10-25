<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\AttributeDefinition;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Inventory\StockService;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\Units;

defined( 'ABSPATH' ) || exit;

/**
 * The small product facts: SKU, stock and the attribute table. They read
 * what the product already loaded. On a product with options the SKU
 * follows the chosen variation through the `flexacommerce/cart` store;
 * stock is shown by the add-to-cart form there, so this block stays empty.
 */
final class ProductDetails {
	public function __construct( private readonly StockService $stock ) {}

	public function sku( Product $product ): string {
		return Component::wrap(
			'product_sku',
			static function () use ( $product ): string {
				$sku = (string) $product->base->sku;
				if ( $sku === '' && ! $product->is_variable() ) {
					return '';
				}
				if ( ! $product->is_variable() ) {
					return sprintf( '<p class="fxc-sku"><span class="fxc-sku__label">%1$s</span> <span class="fxc-sku__value">%2$s</span></p>', esc_html__( 'SKU:', 'flexacommerce' ), esc_html( $sku ) );
				}
				// The chosen variation's SKU replaces the product's once the form picks one.
				Assets::use_store();
				$context = (string) wp_json_encode(
					[
						'productId' => $product->id,
						'sku'       => $sku,
					]
				);
				return sprintf(
					'<p class="fxc-sku" data-wp-interactive="flexacommerce/cart" data-wp-context="%1$s" data-wp-bind--hidden="!state.sku"%2$s><span class="fxc-sku__label">%3$s</span> <span class="fxc-sku__value" data-wp-text="state.sku">%4$s</span></p>',
					esc_attr( $context ),
					$sku === '' ? ' hidden' : '',
					esc_html__( 'SKU:', 'flexacommerce' ),
					esc_html( $sku )
				);
			},
			[ 'product_id' => $product->id ]
		);
	}

	public function stock( Product $product ): string {
		return Component::wrap(
			'product_stock',
			fn (): string => $product->is_variable() ? '' : self::stock_line( $this->stock->availability( $product, $product->base )->status, $this->quantity( $product ) ),
			[ 'product_id' => $product->id ]
		);
	}

	/** Weight, dimensions and the visible attributes, like the "Additional information" tab. */
	public function attributes( Product $product ): string {
		return Component::wrap(
			'product_attributes',
			function () use ( $product ): string {
				$rows = $this->attribute_rows( $product );
				if ( $rows === [] ) {
					return '';
				}
				$html = '<table class="fxc-attributes"><caption class="screen-reader-text">' . esc_html__( 'Product details', 'flexacommerce' ) . '</caption><tbody>';
				foreach ( $rows as [ $label, $value ] ) {
					$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
				}
				return $html . '</tbody></table>';
			},
			[ 'product_id' => $product->id ]
		);
	}

	public static function stock_line( string $status, ?int $quantity ): string {
		$text = match ( $status ) {
			Variant::OUTOFSTOCK => __( 'Out of stock', 'flexacommerce' ),
			Variant::ONBACKORDER => __( 'Available on backorder', 'flexacommerce' ),
			default => $quantity !== null
				/* translators: %d: quantity in stock */
				? sprintf( _n( '%d in stock', '%d in stock', $quantity, 'flexacommerce' ), $quantity )
				: __( 'In stock', 'flexacommerce' ),
		};
		return sprintf( '<p class="fxc-stock fxc-stock--%1$s">%2$s</p>', esc_attr( $status ), esc_html( $text ) );
	}

	/** The count shown when stock is managed and not on backorder. */
	private function quantity( Product $product ): ?int {
		$availability = $this->stock->availability( $product, $product->base );
		if ( $availability->status !== Variant::INSTOCK || $availability->backorders ) {
			return null;
		}
		return $availability->quantity;
	}

	/** @return list<array{0:string,1:string}> */
	private function attribute_rows( Product $product ): array {
		$rows = [];
		$base = $product->base;
		if ( is_numeric( $base->weight ) && (float) $base->weight > 0 ) {
			$rows[] = [ __( 'Weight', 'flexacommerce' ), self::number( Units::weight( (float) $base->weight ) ) . ' ' . Settings::string( 'weight_unit' ) ];
		}
		$dims = array_filter( [ $base->length, $base->width, $base->height ], static fn ( ?string $v ): bool => is_numeric( $v ) && (float) $v > 0 );
		if ( $dims !== [] ) {
			$rows[] = [ __( 'Dimensions', 'flexacommerce' ), implode( ' × ', array_map( static fn ( string $mm ): string => self::number( Units::dimension( (float) $mm ) ), $dims ) ) . ' ' . Settings::string( 'dimension_unit' ) ];
		}
		$definitions = array_filter( $product->attributes, static fn ( AttributeDefinition $d ): bool => $d->visible );
		usort( $definitions, static fn ( AttributeDefinition $a, AttributeDefinition $b ): int => $a->position <=> $b->position );
		foreach ( $definitions as $definition ) {
			[ $name, $values ] = $this->attribute_values( $definition );
			if ( $name !== '' && $values !== [] ) {
				$rows[] = [ $name, implode( ', ', $values ) ];
			}
		}
		return $rows;
	}

	/** @return array{0:string,1:list<string>} */
	private function attribute_values( AttributeDefinition $definition ): array {
		if ( ! $definition->is_global() ) {
			return [ $definition->name, array_values( array_filter( array_map( 'strval', $definition->values ), static fn ( string $v ): bool => $v !== '' ) ) ];
		}
		$term = get_term( $definition->attribute_term_id );
		if ( ! $term instanceof \WP_Term ) {
			return [ '', [] ];
		}
		$values = [];
		if ( $definition->value_term_ids !== [] ) {
			// One query for every value of the attribute, in the order the store set.
			$terms = get_terms(
				[
					'taxonomy'   => $term->taxonomy,
					'include'    => $definition->value_term_ids,
					'hide_empty' => false,
					'orderby'    => 'include',
				]
			);
			foreach ( is_array( $terms ) ? $terms : [] as $value ) {
				$values[] = $value->name;
			}
		}
		return [ $term->name, $values ];
	}

	/** Up to three decimals, without trailing zeros, in the site's number format. */
	private static function number( float $value ): string {
		$text     = rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
		$decimals = str_contains( $text, '.' ) ? strlen( $text ) - (int) strpos( $text, '.' ) - 1 : 0;
		return number_format_i18n( (float) $text, $decimals );
	}
}
