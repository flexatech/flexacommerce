<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Storefront\ShopQuery;

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
	 * @param list<string> $attribute_slugs Global attributes to offer; empty means all of them.
	 */
	public function filters( bool $categories, array $attribute_slugs ): string {
		return Component::wrap(
			'product_filters',
			function () use ( $categories, $attribute_slugs ): string {
				$chosen = ShopQuery::request();
				$groups = '';
				if ( $categories ) {
					$groups .= $this->group( 'cat', __( 'Categories', 'flexacommerce' ), $this->category_choices(), $chosen['cat'] ?? [] );
				}
				foreach ( $this->attributes( $attribute_slugs ) as $attribute ) {
					$values  = get_terms(
						[
							'taxonomy'   => PostTypes::ATTRIBUTE,
							'parent'     => $attribute->term_id,
							'hide_empty' => true,
						]
					);
					$choices = [];
					foreach ( is_array( $values ) ? $values : [] as $value ) {
						$choices[ $value->slug ] = $value->name;
					}
					$groups .= $this->group( $attribute->slug, $attribute->name, $choices, $chosen[ $attribute->slug ] ?? [] );
				}
				if ( $groups === '' ) {
					return '';
				}
				$clear = $chosen !== []
					? ' <a class="fxc-filters__clear" href="' . esc_url( self::action() . self::query_string( array_map( static fn ( string $k ): string => ShopQuery::FILTER_PREFIX . $k, array_keys( $chosen ) ) ) ) . '">' . esc_html__( 'Clear filters', 'flexacommerce' ) . '</a>'
					: '';
				return sprintf(
					'<form class="fxc-filters" method="get" action="%1$s" aria-label="%2$s">%3$s%4$s<p class="fxc-filters__actions"><button type="submit" class="wp-element-button">%5$s</button>%6$s</p></form>',
					esc_url( self::action() ),
					esc_attr__( 'Filter products', 'flexacommerce' ),
					$groups,
					self::hidden( [], false ),
					esc_html__( 'Apply filters', 'flexacommerce' ),
					$clear
				);
			},
			[]
		);
	}

	/**
	 * @param array<string,string> $choices slug => label
	 * @param list<string>         $chosen
	 */
	private function group( string $name, string $legend, array $choices, array $chosen ): string {
		if ( $choices === [] ) {
			return '';
		}
		$items = '';
		foreach ( $choices as $slug => $label ) {
			$items .= sprintf(
				'<li><label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label></li>',
				esc_attr( ShopQuery::FILTER_PREFIX . $name ),
				esc_attr( (string) $slug ),
				checked( in_array( (string) $slug, $chosen, true ), true, false ),
				esc_html( $label )
			);
		}
		return '<fieldset class="fxc-filters__group"><legend>' . esc_html( $legend ) . '</legend><ul>' . $items . '</ul></fieldset>';
	}

	/** @return array<string,string> slug => label, children indented under their parent. */
	private function category_choices(): array {
		$terms = get_terms(
			[
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => true,
				'number'     => 200,
			]
		);
		if ( ! is_array( $terms ) ) {
			return [];
		}
		$children = [];
		foreach ( $terms as $term ) {
			$children[ $term->parent ][] = $term;
		}
		$out  = [];
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $children ): void {
			foreach ( $children[ $parent ] ?? [] as $term ) {
				$out[ $term->slug ] = str_repeat( "\u{00A0}\u{00A0}", $depth ) . $term->name;
				$walk( $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
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

	/** @param list<string> $skip */
	private static function query_string( array $skip ): string {
		$kept = array_diff_key( self::kept( true ), array_flip( $skip ) );
		return $kept === [] ? '' : '?' . http_build_query( $kept );
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
		}
		return $out;
	}
}
