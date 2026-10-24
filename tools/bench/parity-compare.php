<?php
/**
 * Compares a store export with the dataset spec (doc 10 §6.2). Any
 * difference exits non-zero, which stops the benchmark.
 *
 *   php tools/bench/parity-compare.php <spec.json> <export.json> [<export.json> ...]
 */

declare(strict_types=1);

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php parity-compare.php <spec.json> <export.json>...\n" );
	exit( 2 );
}

$spec     = json_decode( (string) file_get_contents( $argv[1] ), true );
$expected = [];
foreach ( $spec['products'] as $product ) {
	$attributes = [];
	foreach ( $product['attributes'] as $attribute ) {
		$values = $attribute['values'];
		sort( $values );
		$attributes[ $attribute['slug'] ] = $values;
	}
	$entry = [
		'name'       => $product['name'],
		'slug'       => $product['slug'],
		'type'       => $product['type'],
		'status'     => 'publish',
		'featured'   => $product['featured'],
		'categories' => $product['categories'],
		'tags'       => $product['tags'],
		'attributes' => $attributes,
	];
	if ( $product['type'] === 'variable' ) {
		foreach ( $product['variants'] as $variant ) {
			$pairs = $variant['attributes'];
			ksort( $pairs );
			$entry['variants'][ $variant['sku'] ] = [
				'attributes'    => $pairs,
				'regular_price' => $variant['regular_price'],
				'sale_price'    => $variant['sale_price'],
				'stock'         => $variant['stock'],
			];
		}
		ksort( $entry['variants'] );
	} else {
		$entry += [
			'regular_price' => $product['regular_price'],
			'sale_price'    => $product['sale_price'],
			'stock'         => $product['stock'],
		];
	}
	$expected[ $product['sku'] ] = $entry;
}

/** @return list<string> paths that differ */
function diff( mixed $want, mixed $got, string $path ): array {
	if ( is_array( $want ) && is_array( $got ) ) {
		$out = [];
		foreach ( array_unique( array_merge( array_keys( $want ), array_keys( $got ) ) ) as $key ) {
			if ( ! array_key_exists( $key, $want ) ) {
				$out[] = "{$path}.{$key}: unexpected";
				continue;
			}
			if ( ! array_key_exists( $key, $got ) ) {
				$out[] = "{$path}.{$key}: missing";
				continue;
			}
			$out = array_merge( $out, diff( $want[ $key ], $got[ $key ], "{$path}.{$key}" ) );
		}
		return $out;
	}
	return $want === $got ? [] : [ "{$path}: want " . json_encode( $want ) . ', got ' . json_encode( $got ) ];
}

$failed = false;
for ( $i = 2; $i < $argc; $i++ ) {
	$export = json_decode( (string) file_get_contents( $argv[ $i ] ), true );
	$got    = [];
	foreach ( $export['products'] as $sku => $product ) {
		unset( $product['sku'], $product['url'] );
		// Value order is display order, which each store keeps its own way.
		foreach ( $product['attributes'] as &$values ) {
			sort( $values );
		}
		unset( $values );
		$got[ $sku ] = $product;
	}
	$problems = diff( $expected, $got, 'products' );
	$label    = $export['store'] ?? $argv[ $i ];
	if ( $problems === [] ) {
		printf( "%s: parity OK (%d products, %d variants)\n", $label, $export['counts']['products'], $export['counts']['variants'] );
		continue;
	}
	$failed = true;
	printf( "%s: %d differences\n  %s\n", $label, count( $problems ), implode( "\n  ", array_slice( $problems, 0, 30 ) ) );
}
exit( $failed ? 1 : 0 );
