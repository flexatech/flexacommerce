<?php
/**
 * Loads the neutral dataset spec into FlexaCommerce through its own
 * services (doc 10 §6.2). Benchmark stack only.
 *
 *   wp eval-file tools/bench/load-flexacommerce.php <spec.json>
 */

// No strict_types: wp eval-file runs this through eval(), where a declare is not allowed.

use Flexa\Commerce\Catalog\AttributeDefinition;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Plugin;

// phpcs:disable -- dev tooling, not part of the plugin.

$spec = json_decode( (string) file_get_contents( (string) ( $args[0] ?? '' ) ), true );
if ( ! is_array( $spec ) || ( $spec['spec'] ?? 0 ) !== 1 ) {
	WP_CLI::error( 'Pass a dataset spec written by generate-dataset.php.' );
}

$catalog = Plugin::instance()->catalog();
$service = $catalog->product_service();
$repo    = $catalog->attributes();
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
$categories = $terms( $spec['categories'], PostTypes::CATEGORY );
$tags       = $terms( $spec['tags'], PostTypes::TAG );

// attribute slug => [ id, value slug => value id ]
$attributes = [];
foreach ( $spec['attributes'] as $attribute ) {
	$id     = $repo->create_attribute( $attribute['name'], $attribute['slug'] );
	$values = [];
	foreach ( $attribute['values'] as $value ) {
		$values[ $value['slug'] ] = $repo->create_value( $id, $value['name'], $value['slug'] );
	}
	$attributes[ $attribute['slug'] ] = [ $id, $values ];
}

$row = static fn ( array $item ): array => [
	'sku'            => $item['sku'],
	'regular_price'  => $item['regular_price'],
	'sale_price'     => $item['sale_price'],
	'manage_stock'   => true,
	'stock_quantity' => $item['stock'],
];

foreach ( $spec['products'] as $i => $product ) {
	$post_id = wp_insert_post(
		[
			'post_type'     => PostTypes::PRODUCT,
			'post_status'   => 'publish',
			'post_title'    => $product['name'],
			'post_name'     => $product['slug'],
			'post_content'  => $product['description'],
			'post_excerpt'  => $product['short_description'],
			'post_date'     => $product['date'],
			'post_date_gmt' => $product['date'],
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $product['sku'] . ': ' . $post_id->get_error_message() );
	}
	wp_set_object_terms( $post_id, array_map( static fn ( string $s ): int => $categories[ $s ], $product['categories'] ), PostTypes::CATEGORY );
	wp_set_object_terms( $post_id, array_map( static fn ( string $s ): int => $tags[ $s ], $product['tags'] ), PostTypes::TAG );

	$definitions = [];
	foreach ( $product['attributes'] as $position => $attribute ) {
		[ $id, $values ] = $attributes[ $attribute['slug'] ];
		$definitions[]   = [
			'type'              => AttributeDefinition::GLOBAL,
			'attribute_term_id' => $id,
			'value_term_ids'    => array_map( static fn ( string $s ): int => $values[ $s ], $attribute['values'] ),
			'visible'           => true,
			'variation'         => $attribute['variation'],
			'position'          => $position,
		];
	}

	$variations = null;
	if ( $product['type'] === 'variable' ) {
		$variations = [];
		foreach ( $product['variants'] as $variant ) {
			$pairs = [];
			foreach ( $variant['attributes'] as $slug => $value ) {
				[ $id, $values ]                         = $attributes[ $slug ];
				$pairs[ Combination::global_key( $id ) ] = (string) $values[ $value ];
			}
			$variations[] = [
				'id'          => 0,
				'combination' => Combination::from_array( $pairs ),
				'fields'      => $row( $variant ),
			];
		}
	}

	$service->save(
		$post_id,
		new ProductInput(
			type: $product['type'],
			attributes: $definitions,
			base: $product['type'] === 'simple' ? $row( $product ) : [ 'sku' => $product['sku'] ],
			variations: $variations,
			featured: $product['featured'],
		)
	);

	if ( $i % 500 === 499 ) {
		wp_cache_flush();
		WP_CLI::log( sprintf( '%d products, %.0f s', $i + 1, microtime( true ) - $started ) );
	}
}

wp_defer_term_counting( false );
WP_CLI::success( sprintf( 'Loaded %d products in %.0f s.', count( $spec['products'] ), microtime( true ) - $started ) );
