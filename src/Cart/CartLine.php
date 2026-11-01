<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * One stored cart line. No prices: they are recomputed on every read
 * (doc 02 §7.3). `labels` is a snapshot of the attribute names and values
 * shown to the customer, so reading the cart needs no term queries.
 * `options` holds the product option values (ADR-025), priced on every
 * read; their labels follow the attribute labels. `gift` holds who a gift
 * card goes to (ADR-069): `{to,name,from,message,date}`, empty for other
 * products.
 */
final class CartLine {
	/**
	 * @param array<string,string>                    $attributes selection for wildcard variations, key => value key
	 * @param list<array{name:string,value:string}>  $labels
	 * @param array<string,string|list<string>>      $options    product option key => value
	 * @param array<string,string>                    $gift       gift card recipient, see GIFT_KEYS
	 */
	public function __construct(
		public readonly string $key,
		public readonly int $product_id,
		public readonly int $variant_id,
		public readonly int $quantity,
		public readonly array $attributes = [],
		public readonly array $labels = [],
		public readonly string $added_at = '',
		public readonly array $options = [],
		public readonly array $gift = [],
	) {}

	public const GIFT_KEYS = [ 'to', 'name', 'from', 'message', 'date' ];

	/**
	 * The same row with other option values is another line. Without
	 * options the key is the one stored before options existed.
	 *
	 * @param array<string,string>              $attributes
	 * @param array<string,string|list<string>> $options
	 * @param array<string,string>              $gift
	 */
	public static function key_for( int $variant_id, array $attributes, array $options = [], array $gift = [] ): string {
		ksort( $attributes, SORT_STRING );
		$parts = [];
		foreach ( $attributes as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}
		$seed = $variant_id . '|' . implode( '&', $parts );
		if ( $options !== [] ) {
			ksort( $options, SORT_STRING );
			$seed .= '|' . wp_json_encode( $options );
		}
		if ( $gift !== [] ) {
			$seed .= '|gift:' . wp_json_encode( $gift );
		}
		return substr( sha1( $seed ), 0, 16 );
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
		$options = [];
		foreach ( is_array( $data['options'] ?? null ) ? $data['options'] : [] as $k => $v ) {
			if ( is_string( $k ) && is_string( $v ) ) {
				$options[ $k ] = $v;
			} elseif ( is_string( $k ) && is_array( $v ) ) {
				$options[ $k ] = array_values( array_map( 'strval', array_filter( $v, 'is_scalar' ) ) );
			}
		}
		$gift = [];
		foreach ( is_array( $data['gift'] ?? null ) ? $data['gift'] : [] as $k => $v ) {
			if ( in_array( $k, self::GIFT_KEYS, true ) && is_string( $v ) && $v !== '' ) {
				$gift[ $k ] = $v;
			}
		}
		return new self(
			key: self::key_for( $variant_id, $attributes, $options, $gift ),
			product_id: (int) ( $data['product_id'] ?? 0 ),
			variant_id: $variant_id,
			quantity: $quantity,
			attributes: $attributes,
			labels: $labels,
			added_at: (string) ( $data['added_at'] ?? '' ),
			options: $options,
			gift: $gift,
		);
	}

	/** @return array{key:string,product_id:int,variant_id:int,qty:int,attributes:array<string,string>,labels:list<array{name:string,value:string}>,added_at:string,options?:array<string,string|list<string>>,gift?:array<string,string>} */
	public function to_array(): array {
		$row = [
			'key'        => $this->key,
			'product_id' => $this->product_id,
			'variant_id' => $this->variant_id,
			'qty'        => $this->quantity,
			'attributes' => $this->attributes,
			'labels'     => $this->labels,
			'added_at'   => $this->added_at,
		];
		if ( $this->options !== [] ) {
			$row['options'] = $this->options;
		}
		if ( $this->gift !== [] ) {
			$row['gift'] = $this->gift;
		}
		return $row;
	}

	public function with_quantity( int $quantity ): self {
		return new self( $this->key, $this->product_id, $this->variant_id, $quantity, $this->attributes, $this->labels, $this->added_at, $this->options, $this->gift );
	}
}
