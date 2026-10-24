<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * One entry of the `_fxc_attributes` product meta (doc 03 §3.1).
 *
 * Global attributes point at a top-level `fxc_attribute` term and store the
 * ids of the value terms they use (ADR-006). Local attributes exist only on
 * this product and store their display values.
 */
final class AttributeDefinition {
	public const GLOBAL = 'global';
	public const LOCAL  = 'local';

	/**
	 * @param list<int>    $value_term_ids global only
	 * @param list<string> $values         local only, display values
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $key,
		public readonly string $name = '',
		public readonly int $attribute_term_id = 0,
		public readonly array $value_term_ids = [],
		public readonly array $values = [],
		public readonly bool $visible = true,
		public readonly bool $variation = false,
		public readonly int $position = 0,
	) {}

	/** @param list<int> $value_term_ids */
	public static function global( int $attribute_term_id, array $value_term_ids, bool $visible = true, bool $variation = false, int $position = 0 ): self {
		return new self(
			type: self::GLOBAL,
			key: Combination::global_key( $attribute_term_id ),
			attribute_term_id: $attribute_term_id,
			value_term_ids: $value_term_ids,
			visible: $visible,
			variation: $variation,
			position: $position,
		);
	}

	/** @param list<string> $values */
	public static function local( string $name, array $values, bool $visible = true, bool $variation = false, int $position = 0 ): self {
		return new self(
			type: self::LOCAL,
			key: Combination::local_key( $name ),
			name: $name,
			values: $values,
			visible: $visible,
			variation: $variation,
			position: $position,
		);
	}

	/**
	 * Reads one stored entry. Returns null for entries that cannot be used.
	 *
	 * @param array<array-key,mixed> $data
	 */
	public static function from_array( array $data ): ?self {
		$visible   = (bool) ( $data['visible'] ?? true );
		$variation = (bool) ( $data['variation'] ?? false );
		$position  = (int) ( $data['position'] ?? 0 );

		if ( isset( $data['attribute_term_id'] ) ) {
			$term_id = (int) $data['attribute_term_id'];
			if ( $term_id <= 0 ) {
				return null;
			}
			$ids = is_array( $data['value_term_ids'] ?? null ) ? $data['value_term_ids'] : [];
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
			return self::global( $term_id, $ids, $visible, $variation, $position );
		}

		$name = isset( $data['name'] ) && is_string( $data['name'] ) ? trim( $data['name'] ) : '';
		if ( $name === '' || sanitize_title( $name ) === '' ) {
			return null;
		}
		$values = is_array( $data['values'] ?? null ) ? $data['values'] : [];
		$values = array_values( array_filter( array_map( 'strval', $values ), static fn ( string $v ): bool => $v !== '' ) );
		return self::local( $name, $values, $visible, $variation, $position );
	}

	public function is_global(): bool {
		return $this->type === self::GLOBAL;
	}

	/**
	 * Values as they appear in combinations: term ids as strings for global
	 * attributes, sanitized values for local ones.
	 *
	 * @return list<string>
	 */
	public function value_keys(): array {
		if ( $this->is_global() ) {
			return array_map( 'strval', $this->value_term_ids );
		}
		return array_values( array_unique( array_map( [ Combination::class, 'local_value' ], $this->values ) ) );
	}

	public function allows( string $value ): bool {
		return in_array( $value, $this->value_keys(), true );
	}

	/** Display value for a combination value of a local attribute. */
	public function local_label( string $value_key ): ?string {
		foreach ( $this->values as $value ) {
			if ( Combination::local_value( $value ) === $value_key ) {
				return $value;
			}
		}
		return null;
	}

	/** @return array<string,mixed> the stored JSON shape */
	public function to_array(): array {
		if ( $this->is_global() ) {
			return [
				'key'               => $this->key,
				'attribute_term_id' => $this->attribute_term_id,
				'value_term_ids'    => $this->value_term_ids,
				'visible'           => $this->visible,
				'variation'         => $this->variation,
				'position'          => $this->position,
			];
		}
		return [
			'key'       => $this->key,
			'name'      => $this->name,
			'values'    => $this->values,
			'visible'   => $this->visible,
			'variation' => $this->variation,
			'position'  => $this->position,
		];
	}
}
