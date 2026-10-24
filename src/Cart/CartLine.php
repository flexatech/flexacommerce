<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * One stored cart line. No prices: they are recomputed on every read
 * (doc 02 §7.3). `labels` is a snapshot of the attribute names and values
 * shown to the customer, so reading the cart needs no term queries.
 */
final class CartLine {
	/**
	 * @param array<string,string>                    $attributes selection for wildcard variations, key => value key
	 * @param list<array{name:string,value:string}>  $labels
	 */
	public function __construct(
		public readonly string $key,
		public readonly int $product_id,
		public readonly int $variant_id,
		public readonly int $quantity,
		public readonly array $attributes = [],
		public readonly array $labels = [],
		public readonly string $added_at = '',
	) {}

	/** @param array<string,string> $attributes */
	public static function key_for( int $variant_id, array $attributes ): string {
		ksort( $attributes, SORT_STRING );
		$parts = [];
		foreach ( $attributes as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}
		return substr( sha1( $variant_id . '|' . implode( '&', $parts ) ), 0, 16 );
	}

	/** @param array<array-key,mixed> $data */
	public static function from_array( array $data ): ?self {
		$variant_id = (int) ( $data['variant_id'] ?? 0 );
		$quantity   = (int) ( $data['qty'] ?? 0 );
		if ( $variant_id <= 0 || $quantity <= 0 ) {
			return null;
		}
		$attributes = [];
		foreach ( is_array( $data['attributes'] ?? null ) ? $data['attributes'] : [] as $k => $v ) {
			if ( is_string( $k ) && ( is_string( $v ) || is_int( $v ) ) ) {
				$attributes[ $k ] = (string) $v;
			}
		}
		$labels = [];
		foreach ( is_array( $data['labels'] ?? null ) ? $data['labels'] : [] as $label ) {
			if ( is_array( $label ) && isset( $label['name'], $label['value'] ) ) {
				$labels[] = [
					'name'  => (string) $label['name'],
					'value' => (string) $label['value'],
				];
			}
		}
		return new self(
			key: self::key_for( $variant_id, $attributes ),
			product_id: (int) ( $data['product_id'] ?? 0 ),
			variant_id: $variant_id,
			quantity: $quantity,
			attributes: $attributes,
			labels: $labels,
			added_at: (string) ( $data['added_at'] ?? '' ),
		);
	}

	/** @return array{key:string,product_id:int,variant_id:int,qty:int,attributes:array<string,string>,labels:list<array{name:string,value:string}>,added_at:string} */
	public function to_array(): array {
		return [
			'key'        => $this->key,
			'product_id' => $this->product_id,
			'variant_id' => $this->variant_id,
			'qty'        => $this->quantity,
			'attributes' => $this->attributes,
			'labels'     => $this->labels,
			'added_at'   => $this->added_at,
		];
	}

	public function with_quantity( int $quantity ): self {
		return new self( $this->key, $this->product_id, $this->variant_id, $quantity, $this->attributes, $this->labels, $this->added_at );
	}
}
