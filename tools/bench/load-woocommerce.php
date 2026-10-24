<?php
/**
 * Loads the neutral dataset spec into WooCommerce through its CRUD classes
 * (doc 10 §6.2). Benchmark stack only.
 *
 *   wp eval-file tools/bench/load-woocommerce.php <spec.json>
 */

// No strict_types: wp eval-file runs this through eval(), where a declare is not allowed.

// phpcs:disable -- dev tooling, not part of the plugin.

$spec = json_decode( (string) file_get_contents( (string) ( $args[0] ?? '' ) ), true );
if ( ! is_array( $spec ) || ( $spec['spec'] ?? 0 ) !== 1 ) {
	WP_CLI::error( 'Pass a dataset spec written by generate-dataset.php.' );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

$started = microtime( true );
wp_defer_term_counting( true );

$terms = static function ( array $list, string $taxonomy ): array {
	$ids = [];
	foreach ( $list as $term ) {
		$parent = isset( $term['parent'] ) ? (int) ( $ids[ $term['parent'] ] ?? 0 ) : 0;
		$result = wp_insert_term( $term['name'], $taxonomy, [ 'slug' => $term['slug'], 'parent' => $parent ] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $taxonomy . ' ' . $term['slug'] . ': ' . $result->get_error_message() );
		}
		$ids[ $term['slug'] ] = (int) $result['term_id'];
	}
	return $ids;
};
$categories = $terms( $spec['categories'], 'product_cat' );
$tags       = $terms( $spec['tags'], 'product_tag' );

// attribute slug => [ attribute id, value slug => term id ]
$attributes = [];
foreach ( $spec['attributes'] as $attribute ) {
	$id = wc_create_attribute(
		[
			'name'         => $attribute['name'],
			'slug'         => $attribute['slug'],
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		]
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $attribute['slug'] . ': ' . $id->get_error_message() );
	}
	$taxonomy = wc_attribute_taxonomy_name( $attribute['slug'] );
	// WooCommerce registers attribute taxonomies on init; this request is past it.
	register_taxonomy( $taxonomy, [ 'product' ], [ 'hierarchical' => false, 'public' => false ] );
	$values = [];
	foreach ( $attribute['values'] as $value ) {
		$values[ $value['slug'] ] = (int) wp_insert_term( $value['name'], $taxonomy, [ 'slug' => $value['slug'] ] )['term_id'];
	}
	$attributes[ $attribute['slug'] ] = [ (int) $id, $values ];
}

$money = static fn ( ?int $minor ): string => $minor === null ? '' : wc_format_decimal( $minor / 100, 2 );

foreach ( $spec['products'] as $i => $product ) {
	$item = $product['type'] === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple();
	$item->set_name( $product['name'] );
	$item->set_slug( $product['slug'] );
	$item->set_status( 'publish' );
	$item->set_description( $product['description'] );
	$item->set_short_description( $product['short_description'] );
	$item->set_date_created( $product['date'] . '+00:00' );
	$item->set_sku( $product['sku'] );
	$item->set_featured( $product['featured'] );
	$item->set_category_ids( array_map( static fn ( string $s ): int => $categories[ $s ], $product['categories'] ) );
	$item->set_tag_ids( array_map( static fn ( string $s ): int => $tags[ $s ], $product['tags'] ) );

	$list = [];
	foreach ( $product['attributes'] as $position => $attribute ) {
		[ $id, $values ] = $attributes[ $attribute['slug'] ];
		$entry           = new WC_Product_Attribute();
		$entry->set_id( $id );
		$entry->set_name( wc_attribute_taxonomy_name( $attribute['slug'] ) );
		$entry->set_options( array_map( static fn ( string $s ): int => $values[ $s ], $attribute['values'] ) );
		$entry->set_position( $position );
		$entry->set_visible( true );
		$entry->set_variation( $attribute['variation'] );
		$list[] = $entry;
	}
	$item->set_attributes( $list );

	if ( $product['type'] === 'simple' ) {
		$item->set_regular_price( $money( $product['regular_price'] ) );
		$item->set_sale_price( $money( $product['sale_price'] ) );
		$item->set_manage_stock( true );
		$item->set_stock_quantity( $product['stock'] );
	}
	$parent_id = $item->save();

	foreach ( $product['variants'] ?? [] as $variant ) {
		$child = new WC_Product_Variation();
		$child->set_parent_id( $parent_id );
		$pairs = [];
		foreach ( $variant['attributes'] as $slug => $value ) {
			$pairs[ wc_attribute_taxonomy_name( $slug ) ] = $value;
		}
		$child->set_attributes( $pairs );
		$child->set_status( 'publish' );
		$child->set_sku( $variant['sku'] );
		$child->set_regular_price( $money( $variant['regular_price'] ) );
		$child->set_sale_price( $money( $variant['sale_price'] ) );
		$child->set_manage_stock( true );
		$child->set_stock_quantity( $variant['stock'] );
		$child->save();
	}
	if ( $product['type'] === 'variable' ) {
		WC_Product_Variable::sync( $parent_id );
	}

	if ( $i % 500 === 499 ) {
		wp_cache_flush();
		WP_CLI::log( sprintf( '%d products, %.0f s', $i + 1, microtime( true ) - $started ) );
	}
}

wp_defer_term_counting( false );
WP_CLI::success( sprintf( 'Loaded %d products in %.0f s.', count( $spec['products'] ), microtime( true ) - $started ) );
