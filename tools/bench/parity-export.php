<?php
/**
 * Exports the loaded catalog in the normalized parity shape (doc 10 §6.2),
 * plus the URLs the load tests use. Detects the active store.
 *
 *   wp eval-file tools/bench/parity-export.php <spec.json> <out.json>
 */

// No strict_types: wp eval-file runs this through eval(), where a declare is not allowed.

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Plugin;

// phpcs:disable -- dev tooling, not part of the plugin.

[ $spec_file, $out_file ] = $args + [ '', '' ];
$spec = json_decode( (string) file_get_contents( $spec_file ), true );
$woo  = class_exists( 'WooCommerce' );

$slugs = static fn ( int $id, string $taxonomy ): array => (array) wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'slugs', 'orderby' => 'slug' ] );
$money = static fn ( $value ): ?int => $value === '' || $value === null ? null : (int) round( (float) $value * 100 );

$products = [];
$page     = 1;
do {
	$ids = get_posts(
		[
			'post_type'      => $woo ? 'product' : PostTypes::PRODUCT,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'paged'          => $page++,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		]
	);
	foreach ( $ids as $id ) {
		$entry = $woo ? wc_entry( (int) $id, $slugs, $money ) : fxc_entry( (int) $id, $slugs );
		$products[ $entry['sku'] ] = $entry;
	}
	wp_cache_flush();
} while ( $ids !== [] );
ksort( $products );

/** @return array<string,mixed> */
function fxc_entry( int $id, callable $slugs ): array {
	$product = Plugin::instance()->catalog()->product_service()->get( $id );
	$term    = static fn ( int $term_id ): string => (string) get_term( $term_id )->slug;
	$attrs   = [];
	$keys    = [];
	foreach ( $product->attributes as $attribute ) {
		$slug                         = $term( $attribute->attribute_term_id );
		$keys[ $attribute->key ]      = $slug;
		$attrs[ $slug ]               = array_map( $term, $attribute->value_term_ids );
	}
	$row = static fn ( $v ): array => [
		'regular_price' => $v->regular_price,
		'sale_price'    => $v->sale_price,
		'stock'         => $v->stock_quantity,
	];
	$entry = [
		'sku'        => (string) $product->base->sku,
		'name'       => $product->title,
		'slug'       => get_post_field( 'post_name', $id ),
		'type'       => $product->type,
		'status'     => $product->status,
		'featured'   => $product->featured,
		'categories' => $slugs( $id, PostTypes::CATEGORY ),
		'tags'       => $slugs( $id, PostTypes::TAG ),
		'attributes' => $attrs,
		'url'        => (string) get_permalink( $id ),
	];
	if ( $product->is_variable() ) {
		$entry['variants'] = [];
		foreach ( $product->variations as $variation ) {
			$pairs = [];
			foreach ( $variation->combination->to_array() as $key => $value ) {
				$pairs[ $keys[ $key ] ] = $term( (int) $value );
			}
			ksort( $pairs );
			$entry['variants'][ (string) $variation->sku ] = [ 'attributes' => $pairs ] + $row( $variation );
		}
		ksort( $entry['variants'] );
	} else {
		$entry += $row( $product->base );
	}
	return $entry;
}

/** @return array<string,mixed> */
function wc_entry( int $id, callable $slugs, callable $money ): array {
	$product = wc_get_product( $id );
	$attrs   = [];
	foreach ( $product->get_attributes() as $attribute ) {
		$attrs[ substr( $attribute->get_name(), 3 ) ] = array_map(
			static fn ( int $term_id ): string => (string) get_term( $term_id )->slug,
			$attribute->get_options()
		);
	}
	$row   = static fn ( WC_Product $p ): array => [
		'regular_price' => $money( $p->get_regular_price( 'edit' ) ),
		'sale_price'    => $money( $p->get_sale_price( 'edit' ) ),
		'stock'         => $p->get_stock_quantity( 'edit' ),
	];
	$entry = [
		'sku'        => $product->get_sku( 'edit' ),
		'name'       => $product->get_name( 'edit' ),
		'slug'       => $product->get_slug( 'edit' ),
		'type'       => $product->get_type(),
		'status'     => $product->get_status( 'edit' ),
		'featured'   => $product->get_featured( 'edit' ),
		'categories' => $slugs( $id, 'product_cat' ),
		'tags'       => $slugs( $id, 'product_tag' ),
		'attributes' => $attrs,
		'url'        => (string) get_permalink( $id ),
	];
	if ( $product->is_type( 'variable' ) ) {
		$entry['variants'] = [];
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			$pairs = [];
			foreach ( $child->get_attributes( 'edit' ) as $taxonomy => $value ) {
				$pairs[ substr( (string) $taxonomy, 3 ) ] = (string) $value;
			}
			ksort( $pairs );
			$entry['variants'][ $child->get_sku( 'edit' ) ] = [ 'attributes' => $pairs ] + $row( $child );
		}
		ksort( $entry['variants'] );
	} else {
		$entry += $row( $product );
	}
	return $entry;
}

// Load-test URLs: the first simple and the first variable product of the spec.
$first = static function ( string $type ) use ( $spec, $products ): string {
	foreach ( $spec['products'] as $product ) {
		if ( $product['type'] === $type ) {
			return (string) wp_parse_url( $products[ $product['sku'] ]['url'] ?? '', PHP_URL_PATH );
		}
	}
	return '';
};
$post = get_page_by_path( 'benchmark-post', OBJECT, 'post' );

file_put_contents(
	$out_file,
	json_encode(
		[
			'store'    => $woo ? 'woocommerce' : 'flexacommerce',
			'counts'   => [
				'products' => count( $products ),
				'variants' => array_sum( array_map( static fn ( array $p ): int => count( $p['variants'] ?? [] ), $products ) ),
			],
			'products' => $products,
			'urls'     => [
				'blog-post' => $post ? (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) : '',
				'home'      => '/',
				'simple'    => $first( 'simple' ),
				'variable'  => $first( 'variable' ),
			],
		],
		JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
	)
);
WP_CLI::success( sprintf( 'Exported %d products to %s.', count( $products ), $out_file ) );
