<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Assets;
use Flexa\Commerce\Storefront\ShopFacets;
use Flexa\Commerce\Storefront\ShopQuery;
use Flexa\Commerce\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * The sort menu and the filter form of a shop page. Both are GET forms that
 * work without scripts; each carries the other's values as hidden fields,
 * so sorting keeps the filters and filtering keeps the sort. A new sort or
 * filter starts again at page 1.
 */
final class ShopControls {
	private static int $count = 0;

	public function sorting(): string {
		return Component::wrap(
			'catalog_sorting',
			function (): string {
				$id      = 'fxc-orderby-' . ( ++self::$count );
				$current = ShopQuery::current_sort();
				$labels  = [
					'menu_order' => __( 'Default sorting', 'flexacommerce' ),
					'popularity' => __( 'Sort by popularity', 'flexacommerce' ),
					'date'       => __( 'Sort by latest', 'flexacommerce' ),
					'price'      => __( 'Sort by price: low to high', 'flexacommerce' ),
					'price-desc' => __( 'Sort by price: high to low', 'flexacommerce' ),
				];
				$options = '';
				foreach ( $labels as $value => $label ) {
					$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
				}
				return sprintf(
					'<form class="fxc-sorting" method="get" action="%1$s"><label for="%2$s">%3$s</label> <select name="orderby" id="%2$s">%4$s</select>%5$s <button type="submit" class="wp-element-button">%6$s</button></form>',
					esc_url( self::action() ),
					esc_attr( $id ),
					esc_html__( 'Sort products', 'flexacommerce' ),
					$options,
					self::hidden( [ 'orderby' ], true ),
					esc_html__( 'Sort', 'flexacommerce' )
				);
			},
			[]
		);
	}

	/**
	 * The filter form (ADR-055). `$attribute_slugs` lists the global
	 * attributes to offer; empty means all of them. With `$counts` each
	 * value shows how many products it would list and values with none are
	 * left out; without, values no product uses are left out.
	 *
	 * @param list<string> $attribute_slugs
	 */
	public function filters( bool $categories, array $attribute_slugs, bool $price = true, bool $stock = true, bool $counts = true ): string {
		return Component::wrap(
			'product_filters',
			function () use ( $categories, $attribute_slugs, $price, $stock, $counts ): string {
				$chosen = ShopQuery::request();
				$range  = ShopQuery::price();
				$facets = $counts ? new ShopFacets() : null;
				$groups = '';
				$labels = [];
				if ( $categories ) {
					[ $choices, $parents ] = $this->category_choices();
					$numbers               = $facets?->categories( $parents );
					$groups               .= $this->group( 'cat', __( 'Categories', 'flexacommerce' ), $choices, $chosen['cat'] ?? [], $numbers );
					$labels['cat']         = [ __( 'Category', 'flexacommerce' ), array_map( static fn ( array $c ): string => $c['label'], $choices ) ];
				}
				if ( $price ) {
					$groups .= $this->price_group( $range, $counts ? $facets?->prices() : null );
				}
				foreach ( $this->attributes( $attribute_slugs ) as $attribute ) {
					$swatch  = AttributeRepository::swatch_of( $attribute->term_id );
					$choices = [];
					foreach ( Plugin::instance()->catalog()->attributes()->values( $attribute->term_id ) as $value ) {
						if ( $facets === null && $value->count === 0 ) {
							continue;
						}
						$choices[ $value->slug ] = [
							'id'    => $value->term_id,
							'label' => $value->name,
							'face'  => self::face( $swatch, $value->term_id ),
						];
					}
					$groups                    .= $this->group( $attribute->slug, $attribute->name, $choices, $chosen[ $attribute->slug ] ?? [], $facets?->attribute( $attribute ) );
					$labels[ $attribute->slug ] = [ $attribute->name, array_map( static fn ( array $c ): string => $c['label'], $choices ) ];
				}
				if ( $stock ) {
					$choices = [];
					foreach ( self::stock_labels() as $status => $label ) {
						$choices[ $status ] = [
							'id'    => $status,
							'label' => $label,
							'face'  => '',
						];
					}
					$groups                           .= $this->group( ShopQuery::STOCK_FILTER, __( 'Availability', 'flexacommerce' ), $choices, $chosen[ ShopQuery::STOCK_FILTER ] ?? [], $facets?->stock(), true );
					$labels[ ShopQuery::STOCK_FILTER ] = [ __( 'Availability', 'flexacommerce' ), self::stock_labels() ];
				}
				$active = $this->active( $chosen, $range, $labels );
				if ( $groups === '' && $active === '' ) {
					return '';
				}
				Assets::use_store();
				return sprintf(
					'<form class="fxc-filters" method="get" action="%1$s" aria-label="%2$s" data-wp-interactive="flexacommerce/cart" data-wp-init="callbacks.filters">%3$s%4$s%5$s<p class="fxc-filters__actions"><button type="submit" class="wp-element-button">%6$s</button></p></form>',
					esc_url( self::action() ),
					esc_attr__( 'Filter products', 'flexacommerce' ),
					$active,
					$groups,
					self::hidden( [], false ),
					esc_html__( 'Apply filters', 'flexacommerce' )
				);
			},
			[]
		);
	}

	/**
	 * A checkbox list. `$counts` (value id => products) adds a count and
	 * drops values with none, unless chosen; null shows every choice.
	 * `$by_slug` keys the counts by the choice key instead of its id.
	 *
	 * @param array<string,array{id:int|string,label:string,face:string}> $choices
	 * @param list<string>                                                 $chosen
	 * @param array<int|string,int>|null                                   $counts
	 */
	private function group( string $name, string $legend, array $choices, array $chosen, ?array $counts, bool $by_slug = false ): string {
		$items = '';
		foreach ( $choices as $slug => $choice ) {
			$slug    = (string) $slug;
			$checked = in_array( $slug, $chosen, true );
			$number  = $counts === null ? null : ( $counts[ $by_slug ? $slug : $choice['id'] ] ?? 0 );
			if ( $number === 0 && ! $checked ) {
				continue;
			}
			$items .= sprintf(
				'<li><label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s%5$s%6$s</label></li>',
				esc_attr( ShopQuery::FILTER_PREFIX . $name ),
				esc_attr( $slug ),
				checked( $checked, true, false ),
				$choice['face'],
				esc_html( $choice['label'] ),
				$number === null ? '' : ' <span class="fxc-filters__count">(' . esc_html( number_format_i18n( $number ) ) . ')</span>'
			);
		}
		if ( $items === '' ) {
			return '';
		}
		return '<fieldset class="fxc-filters__group"><legend>' . esc_html( $legend ) . '</legend><ul>' . $items . '</ul></fieldset>';
	}

	/**
	 * Two price boxes in shown prices, major units. The placeholders are
	 * the lowest and highest price among the listed products.
	 *
	 * @param array{0:?float,1:?float}|null $range
	 * @param array{0:int,1:int}|null       $bounds stored minor units
	 */
	private function price_group( ?array $range, ?array $bounds ): string {
		$pricing = Plugin::instance()->cart()->pricing();
		$scale   = 10 ** $pricing->exponent();
		$ratio   = $pricing->catalog_ratio();
		$low     = '';
		$high    = '';
		if ( $bounds !== null ) {
			$low  = (string) floor( $bounds[0] * $ratio / $scale );
			$high = (string) ceil( $bounds[1] * $ratio / $scale );
		}
		if ( $range === null && ( $bounds === null || $low === $high ) ) {
			return '';
		}
		$symbol = Currency::symbol( Plugin::instance()->services()->money()->currency() );
		$box    = static function ( string $name, string $label, ?float $value, string $placeholder ): string {
			return sprintf(
				'<label class="fxc-filters__price-box"><span>%1$s</span><input type="number" name="%2$s" min="0" step="any" inputmode="decimal" value="%3$s" placeholder="%4$s"></label>',
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $value === null ? '' : self::number( $value ) ),
				esc_attr( $placeholder )
			);
		};
		return sprintf(
			'<fieldset class="fxc-filters__group fxc-filters__price"><legend>%1$s</legend>%2$s%3$s</fieldset>',
			/* translators: %s: currency symbol, such as $ */
			esc_html( sprintf( __( 'Price (%s)', 'flexacommerce' ), $symbol ) ),
			$box( ShopQuery::MIN_PRICE, __( 'Min', 'flexacommerce' ), $range[0] ?? null, $low ),
			$box( ShopQuery::MAX_PRICE, __( 'Max', 'flexacommerce' ), $range[1] ?? null, $high )
		);
	}

	/**
	 * The chosen filters as links that each take one away, and a link that
	 * takes them all away.
	 *
	 * @param array<string,list<string>>                         $chosen
	 * @param array{0:?float,1:?float}|null                      $range
	 * @param array<string,array{0:string,1:array<string,string>}> $labels filter => [name, slug => label]
	 */
	private function active( array $chosen, ?array $range, array $labels ): string {
		$items = '';
		foreach ( $chosen as $name => $slugs ) {
			foreach ( $slugs as $slug ) {
				$value = $labels[ $name ][1][ $slug ] ?? null;
				if ( $value === null ) {
					continue;
				}
				$rest   = array_values( array_diff( $slugs, [ $slug ] ) );
				$items .= self::chip(
					self::link( [ ShopQuery::FILTER_PREFIX . $name => $rest === [] ? null : implode( ',', $rest ) ] ),
					trim( $value, "\u{00A0}" ),
					/* translators: 1: filter name, such as Color, 2: value, such as Red */
					sprintf( __( 'Remove filter %1$s: %2$s', 'flexacommerce' ), $labels[ $name ][0], trim( $value, "\u{00A0}" ) )
				);
			}
		}
		if ( $range !== null ) {
			$format = static fn ( float $amount ): string => self::number( $amount );
			$text   = match ( true ) {
				/* translators: 1: lowest price, 2: highest price */
				$range[0] !== null && $range[1] !== null => sprintf( __( 'Price %1$s to %2$s', 'flexacommerce' ), $format( $range[0] ), $format( $range[1] ) ),
				/* translators: %s: lowest price */
				$range[0] !== null => sprintf( __( 'Price from %s', 'flexacommerce' ), $format( $range[0] ) ),
				/* translators: %s: highest price */
				default            => sprintf( __( 'Price up to %s', 'flexacommerce' ), $format( (float) $range[1] ) ),
			};
			$items .= self::chip(
				self::link(
					[
						ShopQuery::MIN_PRICE => null,
						ShopQuery::MAX_PRICE => null,
					]
				),
				$text,
				/* translators: %s: the price filter, such as "Price from 10" */
				sprintf( __( 'Remove filter %s', 'flexacommerce' ), $text )
			);
		}
		if ( $items === '' ) {
			return '';
		}
		$all = [
			ShopQuery::MIN_PRICE => null,
			ShopQuery::MAX_PRICE => null,
		];
		foreach ( array_keys( $chosen ) as $name ) {
			$all[ ShopQuery::FILTER_PREFIX . $name ] = null;
		}
		return sprintf(
			'<div class="fxc-filters__active"><p class="fxc-filters__active-title">%1$s</p><ul>%2$s</ul><a class="fxc-filters__clear" href="%3$s">%4$s</a></div>',
			esc_html__( 'Active filters', 'flexacommerce' ),
			$items,
			esc_url( self::link( $all ) ),
			esc_html__( 'Clear filters', 'flexacommerce' )
		);
	}

	private static function chip( string $url, string $text, string $label ): string {
		return sprintf(
			'<li><a class="fxc-filters__chip" href="%1$s" aria-label="%2$s">%3$s <span aria-hidden="true">&times;</span></a></li>',
			esc_url( $url ),
			esc_attr( $label ),
			esc_html( $text )
		);
	}

	/**
	 * The listing with the current sort and filters, `$change` applied: a
	 * null value drops that key.
	 *
	 * @param array<string,string|null> $change
	 */
	private static function link( array $change ): string {
		$kept = self::kept( true );
		foreach ( $change as $key => $value ) {
			if ( $value === null ) {
				unset( $kept[ $key ] );
			} else {
				$kept[ $key ] = $value;
			}
		}
		return self::action() . ( $kept === [] ? '' : '?' . http_build_query( $kept ) );
	}

	/** A colour dot or an image for a value of a swatch attribute; empty for other attributes. */
	private static function face( string $swatch, int $value_id ): string {
		if ( $swatch !== AttributeRepository::COLOR && $swatch !== AttributeRepository::IMAGE ) {
			return '';
		}
		$look = AttributeRepository::look( $value_id );
		$url  = $look['image_id'] > 0 ? wp_get_attachment_image_url( $look['image_id'], 'thumbnail' ) : false;
		if ( is_string( $url ) ) {
			return sprintf( '<img class="fxc-filters__swatch" src="%s" alt="" width="18" height="18" loading="lazy" decoding="async"> ', esc_url( $url ) );
		}
		return $look['color'] !== '' ? sprintf( '<span class="fxc-filters__swatch" style="background:%s" aria-hidden="true"></span> ', esc_attr( $look['color'] ) ) : '';
	}

	/** @return array<string,string> */
	private static function stock_labels(): array {
		return [
			Variant::INSTOCK     => __( 'In stock', 'flexacommerce' ),
			Variant::ONBACKORDER => __( 'On backorder', 'flexacommerce' ),
			Variant::OUTOFSTOCK  => __( 'Out of stock', 'flexacommerce' ),
		];
	}

	/** A typed price as it was typed: no trailing zeros. */
	private static function number( float $amount ): string {
		$text = rtrim( rtrim( number_format( $amount, 4, '.', '' ), '0' ), '.' );
		return $text === '' ? '0' : $text;
	}

	/**
	 * Categories as choices, children indented under their parent, and the
	 * parent of each category.
	 *
	 * @return array{0:array<string,array{id:int,label:string,face:string}>,1:array<int,int>}
	 */
	private function category_choices(): array {
		$terms = get_terms(
			[
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => true,
				'number'     => 200,
			]
		);
		if ( ! is_array( $terms ) ) {
			return [ [], [] ];
		}
		$children = [];
		$parents  = [];
		foreach ( $terms as $term ) {
			$children[ $term->parent ][] = $term;
			$parents[ $term->term_id ]   = $term->parent;
		}
		$out  = [];
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $children ): void {
			foreach ( $children[ $parent ] ?? [] as $term ) {
				$out[ $term->slug ] = [
					'id'    => $term->term_id,
					'label' => str_repeat( "\u{00A0}\u{00A0}", $depth ) . $term->name,
					'face'  => '',
				];
				$walk( $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return [ $out, $parents ];
	}

	/**
	 * @param list<string> $slugs
	 * @return list<\WP_Term>
	 */
	private function attributes( array $slugs ): array {
		$args = [
			'taxonomy'   => PostTypes::ATTRIBUTE,
			'parent'     => 0,
			'hide_empty' => false,
		];
		if ( $slugs !== [] ) {
			$args['slug']    = array_map( 'sanitize_title', $slugs );
			$args['orderby'] = 'slug__in';
		}
		$terms = get_terms( $args );
		return is_array( $terms ) ? array_values( $terms ) : [];
	}

	/** The current listing without its query string and page number. */
	private static function action(): string {
		if ( is_post_type_archive( PostTypes::PRODUCT ) || is_tax( [ PostTypes::CATEGORY, PostTypes::TAG ] ) || is_search() ) {
			$url = get_pagenum_link( 1, false );
		} else {
			$url = (string) get_post_type_archive_link( PostTypes::PRODUCT );
		}
		return (string) strtok( $url, '?' );
	}

	/**
	 * The listing's own GET values as hidden fields, without `$skip`.
	 * With `$filters` the filter values are kept as well.
	 *
	 * @param list<string> $skip
	 */
	private static function hidden( array $skip, bool $filters ): string {
		$out = '';
		foreach ( self::kept( $filters ) as $key => $value ) {
			if ( ! in_array( $key, $skip, true ) ) {
				$out .= sprintf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $key ), esc_attr( $value ) );
			}
		}
		return $out;
	}

	/** @return array<string,string> */
	private static function kept( bool $filters ): array {
		$out = [];
		foreach ( [ 's', 'post_type' ] as $key ) {
			$value = get_query_var( $key );
			if ( is_string( $value ) && $value !== '' && is_search() ) {
				$out[ $key ] = $value;
			}
		}
		$sort = ShopQuery::current_sort();
		if ( $sort !== 'menu_order' ) {
			$out['orderby'] = $sort;
		}
		if ( $filters ) {
			foreach ( ShopQuery::request() as $name => $slugs ) {
				$out[ ShopQuery::FILTER_PREFIX . $name ] = implode( ',', $slugs );
			}
			$range = ShopQuery::price();
			foreach ( [ ShopQuery::MIN_PRICE, ShopQuery::MAX_PRICE ] as $i => $key ) {
				if ( isset( $range[ $i ] ) ) {
					$out[ $key ] = self::number( $range[ $i ] );
				}
			}
		}
		return $out;
	}
}
