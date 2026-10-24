<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\InvalidAttribute;

defined( 'ABSPATH' ) || exit;

/**
 * Global attributes are top-level `fxc_attribute` terms and their values
 * are child terms (ADR-006). Order is kept in the `_fxc_position` term meta.
 * Everything goes through the terms API, so no SQL lives here.
 */
final class AttributeRepository {
	public const POSITION_META = '_fxc_position';

	/** @var array<int,\WP_Term|null> */
	private array $terms = [];

	/** @return list<array{id:int,name:string,slug:string,position:int,values:list<array{id:int,name:string,slug:string,position:int}>}> */
	public function all(): array {
		$parents = $this->query( 0 );
		$values  = $this->query( null );
		$out     = [];
		foreach ( $parents as $parent ) {
			$children = array_values( array_filter( $values, static fn ( \WP_Term $t ): bool => (int) $t->parent === (int) $parent->term_id ) );
			$out[]    = $this->shape( $parent ) + [ 'values' => array_map( [ $this, 'shape' ], $children ) ];
		}
		return $out;
	}

	/** A top-level attribute term, or null. */
	public function attribute( int $term_id ): ?\WP_Term {
		$term = $this->term( $term_id );
		return $term !== null && (int) $term->parent === 0 ? $term : null;
	}

	/** A value term of the given attribute, or null. */
	public function value( int $attribute_id, int $value_id ): ?\WP_Term {
		$term = $this->term( $value_id );
		return $term !== null && (int) $term->parent === $attribute_id && $attribute_id > 0 ? $term : null;
	}

	/** @return list<\WP_Term> */
	public function values( int $attribute_id ): array {
		return $this->query( $attribute_id );
	}

	/**
	 * `$slug` is for importers that keep the source slug. With a slug, two
	 * siblings may share a name (WooCommerce allows two attributes labelled
	 * "Size"); WordPress suffixes a slug that is already taken.
	 */
	public function create_attribute( string $name, ?string $slug = null ): int {
		return $this->insert( $name, 0, $slug );
	}

	public function create_value( int $attribute_id, string $name, ?string $slug = null ): int {
		if ( $this->attribute( $attribute_id ) === null ) {
			throw new InvalidAttribute( __( 'Attribute not found.', 'flexacommerce' ) );
		}
		return $this->insert( $name, $attribute_id, $slug );
	}

	public function rename( int $term_id, string $name ): void {
		$name = sanitize_text_field( $name );
		if ( $name === '' ) {
			throw new InvalidAttribute( __( 'The name cannot be empty.', 'flexacommerce' ) );
		}
		$result = wp_update_term( $term_id, PostTypes::ATTRIBUTE, [ 'name' => $name ] );
		if ( is_wp_error( $result ) ) {
			throw new InvalidAttribute( $result->get_error_message() );
		}
		unset( $this->terms[ $term_id ] );
	}

	/**
	 * Stores the given order: the first id gets position 0.
	 *
	 * @param list<int> $term_ids
	 */
	public function reorder( array $term_ids ): void {
		foreach ( $term_ids as $position => $term_id ) {
			update_term_meta( $term_id, self::POSITION_META, $position );
		}
	}

	/** Deletes an attribute with its values, or a single value. */
	public function delete( int $term_id ): void {
		foreach ( $this->query( $term_id ) as $child ) {
			wp_delete_term( (int) $child->term_id, PostTypes::ATTRIBUTE );
		}
		wp_delete_term( $term_id, PostTypes::ATTRIBUTE );
		unset( $this->terms[ $term_id ] );
	}

	/** Number of products using the term (values are assigned to products). */
	public function usage( int $term_id ): int {
		$term = $this->term( $term_id );
		if ( $term === null ) {
			return 0;
		}
		if ( (int) $term->parent > 0 ) {
			return (int) $term->count;
		}
		$children = array_map( static fn ( \WP_Term $child ): int => (int) $child->term_id, $this->query( $term_id ) );
		if ( $children === [] ) {
			return 0;
		}
		// A product with several values of the attribute counts once.
		$objects = get_objects_in_term( $children, PostTypes::ATTRIBUTE );
		return is_array( $objects ) ? count( array_unique( $objects ) ) : 0;
	}

	public function name( int $term_id ): string {
		$term = $this->term( $term_id );
		return $term !== null ? $term->name : '';
	}

	private function insert( string $name, int $parent, ?string $slug ): int {
		$name = sanitize_text_field( $name );
		if ( $name === '' ) {
			throw new InvalidAttribute( __( 'The name cannot be empty.', 'flexacommerce' ) );
		}
		$slug = $slug !== null ? sanitize_title( $slug ) : '';
		foreach ( $slug === '' ? $this->query( $parent ) : [] as $sibling ) {
			if ( mb_strtolower( $sibling->name ) === mb_strtolower( $name ) ) {
				throw new InvalidAttribute( __( 'An item with this name already exists.', 'flexacommerce' ) );
			}
		}
		$args = [ 'parent' => $parent ];
		if ( $slug !== '' ) {
			$args['slug'] = $slug;
		}
		$result = wp_insert_term( $name, PostTypes::ATTRIBUTE, $args );
		if ( is_wp_error( $result ) ) {
			throw new InvalidAttribute( $result->get_error_message() );
		}
		$id = (int) $result['term_id'];
		update_term_meta( $id, self::POSITION_META, count( $this->query( $parent ) ) - 1 );
		return $id;
	}

	private function term( int $term_id ): ?\WP_Term {
		if ( $term_id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $term_id, $this->terms ) ) {
			$term                    = get_term( $term_id, PostTypes::ATTRIBUTE );
			$this->terms[ $term_id ] = $term instanceof \WP_Term ? $term : null;
		}
		return $this->terms[ $term_id ];
	}

	/**
	 * Children of a parent (0 = attributes), or every term when null,
	 * sorted by stored position then name.
	 *
	 * @return list<\WP_Term>
	 */
	private function query( ?int $parent ): array {
		$args = [
			'taxonomy'   => PostTypes::ATTRIBUTE,
			'hide_empty' => false,
		];
		if ( $parent !== null ) {
			$args['parent'] = $parent;
		}
		$terms = get_terms( $args );
		if ( ! is_array( $terms ) ) {
			return [];
		}
		usort(
			$terms,
			static fn ( \WP_Term $a, \WP_Term $b ): int => [ self::position( $a ), $a->name ] <=> [ self::position( $b ), $b->name ]
		);
		return $terms;
	}

	private static function position( \WP_Term $term ): int {
		$value = get_term_meta( (int) $term->term_id, self::POSITION_META, true );
		return is_numeric( $value ) ? (int) $value : PHP_INT_MAX;
	}

	/** @return array{id:int,name:string,slug:string,position:int} */
	private function shape( \WP_Term $term ): array {
		$position = self::position( $term );
		return [
			'id'       => (int) $term->term_id,
			'name'     => $term->name,
			'slug'     => $term->slug,
			'position' => $position === PHP_INT_MAX ? 0 : $position,
		];
	}
}
