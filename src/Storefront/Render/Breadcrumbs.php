<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Home, the shop, the category path and the current page, as an ordered
 * list in a labelled nav. The last crumb is the current page and is not a
 * link. Pages outside the shop get Home, their parents and themselves.
 */
final class Breadcrumbs {
	public function html(): string {
		return Component::wrap(
			'breadcrumbs',
			function (): string {
				$crumbs = $this->crumbs();
				/**
				 * Filters the breadcrumb trail.
				 *
				 * @param list<array{0:string,1:string}> $crumbs label and URL; the last one has no URL.
				 */
				$crumbs = self::clean( apply_filters( 'flexacommerce.breadcrumbs', $crumbs ) );
				if ( count( $crumbs ) < 2 ) {
					return '';
				}
				$items = '';
				$last  = count( $crumbs ) - 1;
				foreach ( $crumbs as $index => [ $label, $url ] ) {
					$items .= $index === $last || $url === ''
						? '<li><span' . ( $index === $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</span></li>'
						: '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
				}
				return '<nav class="fxc-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'flexacommerce' ) . '"><ol>' . $items . '</ol></nav>';
			},
			[]
		);
	}

	/** @return list<array{0:string,1:string}> */
	private function crumbs(): array {
		$crumbs = [ [ __( 'Home', 'flexacommerce' ), home_url( '/' ) ] ];
		if ( is_front_page() ) {
			return [];
		}
		$shop = get_post_type_archive_link( PostTypes::PRODUCT );
		$shop = is_string( $shop ) ? [ __( 'Shop', 'flexacommerce' ), $shop ] : null;

		if ( is_post_type_archive( PostTypes::PRODUCT ) ) {
			$crumbs[] = [ __( 'Shop', 'flexacommerce' ), '' ];
			return $this->paged( $crumbs );
		}
		if ( is_tax( [ PostTypes::CATEGORY, PostTypes::TAG ] ) ) {
			$term = get_queried_object();
			if ( $shop !== null ) {
				$crumbs[] = $shop;
			}
			if ( $term instanceof \WP_Term ) {
				$crumbs   = array_merge( $crumbs, $this->ancestors( $term ) );
				$crumbs[] = [ $term->name, '' ];
			}
			return $this->paged( $crumbs );
		}
		$post = get_queried_object();
		if ( is_singular( PostTypes::PRODUCT ) && $post instanceof \WP_Post ) {
			if ( $shop !== null ) {
				$crumbs[] = $shop;
			}
			$term = $this->primary_category( $post->ID );
			if ( $term !== null ) {
				$crumbs   = array_merge( $crumbs, $this->ancestors( $term ) );
				$crumbs[] = [ $term->name, (string) get_term_link( $term ) ];
			}
			$crumbs[] = [ get_the_title( $post ), '' ];
			return $crumbs;
		}
		if ( is_singular() && $post instanceof \WP_Post ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $parent ) {
				$crumbs[] = [ get_the_title( $parent ), (string) get_permalink( $parent ) ];
			}
			$crumbs[] = [ get_the_title( $post ), '' ];
			return $crumbs;
		}
		return [];
	}

	/**
	 * A filter may return anything; keep the [label, url] pairs.
	 *
	 * @return list<array{0:string,1:string}>
	 */
	private static function clean( mixed $crumbs ): array {
		$out = [];
		foreach ( is_array( $crumbs ) ? $crumbs : [] as $crumb ) {
			if ( is_array( $crumb ) ) {
				$crumb = array_values( $crumb );
				$out[] = [ (string) ( $crumb[0] ?? '' ), (string) ( $crumb[1] ?? '' ) ];
			}
		}
		return $out;
	}

	/** The deepest category of the product, so the trail is the most specific one. */
	private function primary_category( int $product_id ): ?\WP_Term {
		$terms = get_the_terms( $product_id, PostTypes::CATEGORY );
		if ( ! is_array( $terms ) || $terms === [] ) {
			return null;
		}
		$best  = null;
		$depth = -1;
		foreach ( $terms as $term ) {
			$count = count( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) );
			if ( $count > $depth ) {
				$best  = $term;
				$depth = $count;
			}
		}
		return $best;
	}

	/** @return list<array{0:string,1:string}> */
	private function ancestors( \WP_Term $term ): array {
		$out = [];
		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $id ) {
			$parent = get_term( $id, $term->taxonomy );
			if ( $parent instanceof \WP_Term ) {
				$out[] = [ $parent->name, (string) get_term_link( $parent ) ];
			}
		}
		return $out;
	}

	/**
	 * Page 2 and on link the archive and add "Page N".
	 *
	 * @param list<array{0:string,1:string}> $crumbs
	 * @return list<array{0:string,1:string}>
	 */
	private function paged( array $crumbs ): array {
		$page = (int) get_query_var( 'paged' );
		if ( $page < 2 ) {
			return $crumbs;
		}
		$last               = count( $crumbs ) - 1;
		$crumbs[ $last ][1] = get_pagenum_link( 1 );
		/* translators: %d: page number */
		$crumbs[] = [ sprintf( __( 'Page %d', 'flexacommerce' ), $page ), '' ];
		return $crumbs;
	}
}
