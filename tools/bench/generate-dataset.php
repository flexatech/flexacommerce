<?php
/**
 * Writes the neutral benchmark dataset spec (doc 10 §6.2) as JSON. Plain
 * PHP, no WordPress: both store loaders read the same file.
 *
 *   php tools/bench/generate-dataset.php --size=S|M [--seed=42] [--out=file]
 *
 * Prices are integers in minor units (USD, 2 decimals). Value slugs are
 * unique across attributes, so no store has to suffix them. Customers and
 * orders join the spec in phase 4, when FlexaCommerce can store them.
 */

declare(strict_types=1);

const SPEC_VERSION = 1;

const SIZES = [
	// products, share of variable products, variants per variable product, top categories, children per top, tags
	'S' => [ 1000, 0.3, 6, 5, 3, 30 ],
	'M' => [ 10000, 0.3, 12, 10, 9, 200 ],
];

// Variation axes per variant count: colors x sizes.
const AXES = [
	6  => [ 3, 2 ],
	12 => [ 4, 3 ],
];

const COLORS    = [ 'Red', 'Blue', 'Green', 'Black', 'White', 'Grey', 'Navy', 'Olive', 'Sand', 'Rust', 'Teal', 'Plum' ];
const SIZE_LIST = [ 'XS', 'Small', 'Medium', 'Large', 'XL', 'XXL' ];
const MATERIALS = [ 'Cotton', 'Linen', 'Wool', 'Leather', 'Canvas', 'Bamboo' ];

const ADJECTIVES = [ 'Classic', 'Everyday', 'Light', 'Heavy', 'Compact', 'Urban', 'Field', 'Studio', 'Coastal', 'Alpine', 'Quiet', 'Bright' ];
const NOUNS      = [ 'Jacket', 'Shirt', 'Bag', 'Cap', 'Scarf', 'Mug', 'Lamp', 'Notebook', 'Bottle', 'Blanket', 'Wallet', 'Apron' ];
const WORDS      = [ 'made', 'from', 'durable', 'material', 'with', 'a', 'clean', 'finish', 'for', 'daily', 'use', 'and', 'easy', 'care', 'that', 'lasts' ];

$options = getopt( '', [ 'size:', 'seed::', 'out::' ] );
$size    = strtoupper( (string) ( $options['size'] ?? '' ) );
$seed    = (int) ( $options['seed'] ?? 42 );
if ( ! isset( SIZES[ $size ] ) ) {
	fwrite( STDERR, "Usage: php generate-dataset.php --size=S|M [--seed=42] [--out=file]\n" );
	exit( 2 );
}
$out = (string) ( $options['out'] ?? __DIR__ . "/out/dataset-{$size}-{$seed}.json" );

[ $product_count, $variable_share, $variant_count, $top_count, $child_count, $tag_count ] = SIZES[ $size ];
[ $color_count, $size_count ] = AXES[ $variant_count ];

$random = new \Random\Randomizer( new \Random\Engine\Mt19937( $seed ) );
$slug   = static fn ( string $text ): string => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $text ) ), '-' );
$pick   = static fn ( array $list ): mixed => $list[ $random->getInt( 0, count( $list ) - 1 ) ];
$text   = static function ( int $words ) use ( $pick ): string {
	$out = [];
	for ( $i = 0; $i < $words; $i++ ) {
		$out[] = $pick( WORDS );
	}
	return ucfirst( implode( ' ', $out ) ) . '.';
};
// Prices end in 99 cents, between 5 and 200 dollars.
$price = static fn (): int => $random->getInt( 5, 200 ) * 100 - 1;

$categories = [];
for ( $t = 1; $t <= $top_count; $t++ ) {
	$top          = sprintf( 'dept-%02d', $t );
	$categories[] = [ 'slug' => $top, 'name' => sprintf( 'Department %02d', $t ), 'parent' => null ];
	for ( $c = 1; $c <= $child_count; $c++ ) {
		$categories[] = [ 'slug' => sprintf( '%s-%02d', $top, $c ), 'name' => sprintf( 'Section %02d.%02d', $t, $c ), 'parent' => $top ];
	}
}
$leaf_categories = array_values( array_filter( $categories, static fn ( array $c ): bool => $c['parent'] !== null ) );

$tags = [];
for ( $i = 1; $i <= $tag_count; $i++ ) {
	$tags[] = [ 'slug' => sprintf( 'tag-%03d', $i ), 'name' => sprintf( 'Tag %03d', $i ) ];
}

$values = static fn ( string $prefix, array $names ): array => array_map(
	static fn ( string $name ): array => [ 'slug' => $prefix . '-' . $slug( $name ), 'name' => $name ],
	$names
);
$attributes = [
	[ 'slug' => 'color', 'name' => 'Color', 'values' => $values( 'color', COLORS ) ],
	[ 'slug' => 'size', 'name' => 'Size', 'values' => $values( 'size', SIZE_LIST ) ],
	[ 'slug' => 'material', 'name' => 'Material', 'values' => $values( 'material', MATERIALS ) ],
];
$color_slugs    = array_column( $attributes[0]['values'], 'slug' );
$size_slugs     = array_column( $attributes[1]['values'], 'slug' );
$material_slugs = array_column( $attributes[2]['values'], 'slug' );

$stock = static function () use ( $random ): int {
	// About 5 % of rows are out of stock.
	return $random->getInt( 1, 100 ) <= 5 ? 0 : $random->getInt( 1, 100 );
};
$sale = static function ( int $regular ) use ( $random ): ?int {
	return $random->getInt( 1, 100 ) <= 20 ? (int) ( round( $regular * 0.8 / 100 ) * 100 - 1 ) : null;
};

$products = [];
$start    = new DateTimeImmutable( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) );
for ( $i = 1; $i <= $product_count; $i++ ) {
	$name     = sprintf( '%s %s %05d', $pick( ADJECTIVES ), $pick( NOUNS ), $i );
	$variable = $random->getInt( 1, 1000 ) <= (int) ( $variable_share * 1000 );
	$sku      = sprintf( 'FXB-%05d', $i );
	$category = $pick( $leaf_categories );
	$product  = [
		'sku'               => $sku,
		'name'              => $name,
		'slug'              => $slug( $name ),
		'type'              => $variable ? 'variable' : 'simple',
		'date'              => $start->modify( "+{$i} minutes" )->format( 'Y-m-d H:i:s' ),
		'description'       => $text( 40 ) . ' ' . $text( 30 ),
		'short_description' => $text( 12 ),
		'categories'        => [ $category['slug'] ],
		'tags'              => [ $pick( $tags )['slug'], $pick( $tags )['slug'] ],
		'featured'          => $random->getInt( 1, 100 ) <= 5,
		'attributes'        => [
			[ 'slug' => 'material', 'values' => [ $pick( $material_slugs ) ], 'variation' => false ],
		],
	];
	$product['tags'] = array_values( array_unique( $product['tags'] ) );
	sort( $product['tags'] );

	if ( ! $variable ) {
		$regular                  = $price();
		$product['regular_price'] = $regular;
		$product['sale_price']    = $sale( $regular );
		$product['stock']         = $stock();
		$products[]               = $product;
		continue;
	}

	$offset = $random->getInt( 0, count( $color_slugs ) - $color_count );
	$colors = array_slice( $color_slugs, $offset, $color_count );
	$sizes  = array_slice( $size_slugs, $random->getInt( 0, count( $size_slugs ) - $size_count ), $size_count );

	$product['attributes'][] = [ 'slug' => 'color', 'values' => $colors, 'variation' => true ];
	$product['attributes'][] = [ 'slug' => 'size', 'values' => $sizes, 'variation' => true ];
	$product['variants']     = [];
	$regular                 = $price();
	$n                       = 0;
	foreach ( $colors as $color ) {
		foreach ( $sizes as $size_slug ) {
			$n++;
			$product['variants'][] = [
				'sku'           => sprintf( '%s-%02d', $sku, $n ),
				'attributes'    => [ 'color' => $color, 'size' => $size_slug ],
				// Larger sizes cost a little more, as in a real catalog.
				'regular_price' => $regular + array_search( $size_slug, $sizes, true ) * 200,
				'sale_price'    => null,
				'stock'         => $stock(),
			];
		}
	}
	$products[] = $product;
}

$spec = [
	'spec'       => SPEC_VERSION,
	'size'       => $size,
	'seed'       => $seed,
	'currency'   => 'USD',
	'categories' => $categories,
	'tags'       => $tags,
	'attributes' => $attributes,
	'products'   => $products,
	'customers'  => [],
	'orders'     => [],
];

if ( ! is_dir( dirname( $out ) ) ) {
	mkdir( dirname( $out ), 0777, true );
}
file_put_contents( $out, json_encode( $spec, JSON_UNESCAPED_SLASHES ) );
$variants = array_sum( array_map( static fn ( array $p ): int => count( $p['variants'] ?? [] ), $products ) );
fwrite( STDOUT, sprintf( "%s: %d products, %d variants, sha1 %s\n", $out, count( $products ), $variants, sha1_file( $out ) ) );
