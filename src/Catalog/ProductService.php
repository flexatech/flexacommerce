<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\DuplicateSku;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Contracts\Exceptions\ProductNotFound;
use Flexa\Commerce\Database\AfterCommit;
use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Save rules for product data (doc 03 §3, doc 02 §7.2). The post itself
 * (title, content, status) is saved by WordPress; this service owns the
 * `_fxc_*` meta and the product's rows in fxc_variants.
 */
final class ProductService {
	public const MAX_VARIANTS = 1000;

	public function __construct(
		private readonly ProductRepository $products,
		private readonly VariantRepository $variants,
		private readonly AttributeRepository $attributes,
		private readonly Transaction $transaction,
		private readonly AfterCommit $after_commit,
		private readonly Clock $clock,
	) {}

	/** @throws ProductNotFound */
	public function get( int $product_id ): Product {
		$product = $this->products->find( $product_id ) ?? throw new ProductNotFound( $product_id );
		return $this->without_deleted_terms( $product );
	}

	/**
	 * Store attributes and values deleted since the product was saved are
	 * left out, so the stored definition never refers to a missing term.
	 * Variations that used them turn stale on the next save.
	 */
	private function without_deleted_terms( Product $product ): Product {
		$attributes = [];
		$changed    = false;
		foreach ( $product->attributes as $attribute ) {
			if ( ! $attribute->is_global() ) {
				$attributes[] = $attribute;
				continue;
			}
			if ( $this->attributes->attribute( $attribute->attribute_term_id ) === null ) {
				$changed = true;
				continue;
			}
			$ids = array_values(
				array_filter(
					$attribute->value_term_ids,
					fn ( int $id ): bool => $this->attributes->value( $attribute->attribute_term_id, $id ) !== null
				)
			);
			if ( $ids !== $attribute->value_term_ids ) {
				$changed   = true;
				$attribute = AttributeDefinition::global( $attribute->attribute_term_id, $ids, $attribute->visible, $attribute->variation, $attribute->position );
			}
			$attributes[] = $attribute;
		}
		if ( ! $changed ) {
			return $product;
		}
		return new Product(
			id: $product->id,
			type: $product->type,
			status: $product->status,
			title: $product->title,
			attributes: $attributes,
			base: $product->base,
			variations: $product->variations,
			gallery_ids: $product->gallery_ids,
			visibility: $product->visibility,
			featured: $product->featured,
		);
	}

	/**
	 * Applies the input in one transaction. Rows not named in
	 * `$input->variations` are deleted; variations whose attribute values
	 * are no longer offered are deleted, or disabled when they hold stock.
	 *
	 * @throws ProductNotFound|InvalidProduct|DuplicateSku
	 */
	public function save( int $product_id, ProductInput $input ): Product {
		$before = $this->get( $product_id );

		$this->transaction->run(
			function () use ( $before, $input ): void {
				$type       = $input->type ?? $before->type;
				$attributes = $input->attributes !== null ? $this->resolve_attributes( $input->attributes ) : $before->attributes;

				$base = $before->base->with( ( $input->base ?? [] ) + [ 'product_id' => $before->id ] );
				$this->validate_row( $base, 'base' );

				$variations = [];
				if ( ProductTypes::has_variations( $type ) ) {
					$variations = $this->plan_variations( $before, $attributes, $input->variations );
				}

				$this->check_skus( $before->id, [ $base, ...$variations ] );

				$product = new Product(
					id: $before->id,
					type: $type,
					status: $before->status,
					title: $before->title,
					attributes: $attributes,
					base: $base,
					variations: $variations,
					gallery_ids: $input->gallery_ids ?? $before->gallery_ids,
					visibility: $input->visibility ?? $before->visibility,
					featured: $input->featured ?? $before->featured,
				);

				$this->write_rows( $before, $product );
				$this->products->save_meta( $product );
				$this->products->sync_denormalized( $product->id, $this->clock->db_now() );

				$this->after_commit->defer(
					static function () use ( $product, $before ): void {
						/**
						 * Fires after product data was saved and committed.
						 *
						 * @param int     $product_id
						 * @param Product $before data before the save
						 */
						do_action( 'flexacommerce.product.saved', $product->id, $before );
					}
				);
			}
		);

		return $this->get( $product_id );
	}

	/**
	 * Adds the missing combinations of the variation attributes. `$values`
	 * limits an attribute to some of its values (key => value keys); an
	 * attribute left out uses all of its values.
	 *
	 * @param array<string,list<string>> $values
	 * @throws ProductNotFound|InvalidProduct
	 */
	public function generate_variants( int $product_id, array $values = [] ): VariantGenerationResult {
		$product = $this->get( $product_id );
		if ( ! $product->is_variable() ) {
			throw new InvalidProduct( __( 'Only variable products have variations.', 'flexacommerce' ), 'type' );
		}

		$axes = [];
		foreach ( $product->variation_attributes() as $attribute ) {
			$allowed = $attribute->value_keys();
			$chosen  = isset( $values[ $attribute->key ] )
				? array_values( array_intersect( $allowed, array_map( 'strval', $values[ $attribute->key ] ) ) )
				: $allowed;
			if ( $chosen === [] ) {
				return new VariantGenerationResult( 0, 0, false, $product );
			}
			$axes[ $attribute->key ] = $chosen;
		}
		if ( $axes === [] ) {
			throw new InvalidProduct( __( 'Mark at least one attribute as used for variations first.', 'flexacommerce' ), 'attributes' );
		}

		$existing = [];
		foreach ( $product->variations as $variation ) {
			$existing[ $variation->combination->hash() ] = true;
		}

		$capacity   = self::MAX_VARIANTS - count( $product->variations );
		$menu_order = count( $product->variations );
		$created    = 0;
		$skipped    = 0;
		$limited    = false;

		$this->transaction->run(
			function () use ( $product, $axes, $existing, $capacity, &$menu_order, &$created, &$skipped, &$limited ): void {
				foreach ( self::cartesian( $axes ) as $pairs ) {
					$combination = Combination::from_array( $pairs );
					if ( isset( $existing[ $combination->hash() ] ) ) {
						++$skipped;
						continue;
					}
					if ( $created >= $capacity ) {
						$limited = true;
						break;
					}
					$this->variants->insert(
						new Variant(
							id: 0,
							product_id: $product->id,
							is_base: false,
							combination: $combination,
							menu_order: $menu_order++,
						)
					);
					++$created;
				}
				$this->products->sync_denormalized( $product->id, $this->clock->db_now() );
			}
		);

		return new VariantGenerationResult( $created, $skipped, $limited, $this->get( $product_id ) );
	}

	/** Gives a new product its base row and type (hooked on `save_post_fxc_product`). */
	public function ensure_base( int $product_id ): void {
		if ( $this->variants->for_product( $product_id ) !== [] ) {
			return;
		}
		$this->variants->insert( Variant::base_for( $product_id ) );
		if ( get_post_meta( $product_id, ProductRepository::META_TYPE, true ) === '' ) {
			update_post_meta( $product_id, ProductRepository::META_TYPE, ProductTypes::SIMPLE );
		}
		$this->products->sync_denormalized( $product_id, $this->clock->db_now() );
	}

	/** Removes the rows of a product post that is being deleted for good. */
	public function on_post_deleted( int $post_id, ?\WP_Post $post = null ): void {
		$post ??= get_post( $post_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::PRODUCT ) {
			return;
		}
		$this->variants->delete_for_product( $post_id );
		do_action( 'flexacommerce.product.deleted', $post_id );
	}

	/**
	 * Copies a product as a draft. SKUs are cleared because they must stay
	 * unique.
	 *
	 * @throws ProductNotFound
	 */
	public function duplicate( int $product_id ): int {
		$source = $this->get( $product_id );
		$post   = get_post( $product_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ProductNotFound( $product_id );
		}

		return $this->transaction->run(
			function () use ( $source, $post ): int {
				$copy_id = wp_insert_post(
					wp_slash(
						[
							'post_type'    => PostTypes::PRODUCT,
							'post_status'  => 'draft',
							/* translators: %s: product title */
							'post_title'   => sprintf( __( '%s (Copy)', 'flexacommerce' ), $post->post_title ),
							'post_content' => $post->post_content,
							'post_excerpt' => $post->post_excerpt,
							'post_author'  => get_current_user_id() ?: (int) $post->post_author,
							'menu_order'   => $post->menu_order,
						]
					),
					true
				);
				if ( is_wp_error( $copy_id ) ) {
					throw new InvalidProduct( $copy_id->get_error_message() );
				}

				foreach ( [ PostTypes::CATEGORY, PostTypes::TAG ] as $taxonomy ) {
					$terms = wp_get_object_terms( $source->id, $taxonomy, [ 'fields' => 'ids' ] );
					if ( is_array( $terms ) ) {
						wp_set_object_terms( $copy_id, array_map( 'intval', $terms ), $taxonomy );
					}
				}
				$thumbnail = (int) get_post_thumbnail_id( $source->id );
				if ( $thumbnail > 0 ) {
					set_post_thumbnail( $copy_id, $thumbnail );
				}

				// wp_insert_post() fired save_post_fxc_product, which created an empty base row.
				$this->variants->delete_for_product( $copy_id );
				foreach ( $source->rows() as $row ) {
					$this->variants->insert(
						$row->with(
							[
								'id'         => 0,
								'product_id' => $copy_id,
								'sku'        => null,
							]
						)
					);
				}

				$copy = new Product(
					id: $copy_id,
					type: $source->type,
					status: 'draft',
					title: '',
					attributes: $source->attributes,
					base: $source->base,
					variations: [],
					gallery_ids: $source->gallery_ids,
					visibility: $source->visibility,
					featured: false,
				);
				$this->products->save_meta( $copy );
				$this->products->sync_denormalized( $copy_id, $this->clock->db_now() );

				$this->after_commit->defer(
					static function () use ( $copy_id, $source ): void {
						do_action( 'flexacommerce.product.duplicated', $copy_id, $source->id );
					}
				);
				return $copy_id;
			}
		);
	}

	/**
	 * Recomputes denormalized prices of products whose sale started or ended
	 * (hourly cron, doc 03 §3.3).
	 */
	public function refresh_due_prices( int $limit = 200 ): int {
		$now = $this->clock->db_now();
		$ids = $this->products->due_price_changes( $now, $limit );
		foreach ( $ids as $id ) {
			$this->products->sync_denormalized( $id, $now );
			do_action( 'flexacommerce.product.price_changed', $id );
		}
		return count( $ids );
	}

	/**
	 * @param list<array<string,mixed>> $raw
	 * @return list<AttributeDefinition>
	 * @throws InvalidProduct
	 */
	private function resolve_attributes( array $raw ): array {
		$out  = [];
		$keys = [];
		foreach ( $raw as $i => $data ) {
			$path       = 'attributes.' . $i;
			$data       = [ 'position' => $i ] + $data;
			$definition = ( $data['type'] ?? '' ) === AttributeDefinition::GLOBAL
				? $this->resolve_global( $data, $path )
				: $this->resolve_local( $data, $path );

			if ( isset( $keys[ $definition->key ] ) ) {
				throw new InvalidProduct( __( 'The same attribute is listed twice.', 'flexacommerce' ), $path );
			}
			$keys[ $definition->key ] = true;
			$out[]                    = $definition;
		}
		return $out;
	}

	/** @param array<string,mixed> $data */
	private function resolve_global( array $data, string $path ): AttributeDefinition {
		$term_id = (int) ( $data['attribute_term_id'] ?? 0 );
		if ( $this->attributes->attribute( $term_id ) === null ) {
			throw new InvalidProduct( __( 'The attribute does not exist.', 'flexacommerce' ), $path );
		}
		$ids = is_array( $data['value_term_ids'] ?? null ) ? $data['value_term_ids'] : [];
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		foreach ( $ids as $id ) {
			if ( $this->attributes->value( $term_id, $id ) === null ) {
				throw new InvalidProduct( __( 'A value does not belong to this attribute.', 'flexacommerce' ), $path . '.value_term_ids' );
			}
		}
		return AttributeDefinition::global(
			$term_id,
			$ids,
			(bool) ( $data['visible'] ?? true ),
			(bool) ( $data['variation'] ?? false ),
			(int) $data['position']
		);
	}

	/** @param array<string,mixed> $data */
	private function resolve_local( array $data, string $path ): AttributeDefinition {
		$name = is_string( $data['name'] ?? null ) ? trim( sanitize_text_field( $data['name'] ) ) : '';
		if ( $name === '' || Combination::local_key( $name ) === 'l:' ) {
			throw new InvalidProduct( __( 'Give the attribute a name.', 'flexacommerce' ), $path . '.name' );
		}
		$values = [];
		$seen   = [];
		foreach ( is_array( $data['values'] ?? null ) ? $data['values'] : [] as $value ) {
			$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
			$slug  = $value === '' ? '' : Combination::local_value( $value );
			if ( $slug === '' ) {
				continue;
			}
			if ( isset( $seen[ $slug ] ) ) {
				throw new InvalidProduct(
					/* translators: %s: attribute value */
					sprintf( __( 'The value "%s" is listed twice.', 'flexacommerce' ), $value ),
					$path . '.values'
				);
			}
			$seen[ $slug ] = true;
			$values[]      = $value;
		}
		return AttributeDefinition::local(
			$name,
			$values,
			(bool) ( $data['visible'] ?? true ),
			(bool) ( $data['variation'] ?? false ),
			(int) $data['position']
		);
	}

	/**
	 * The variation rows after the save, normalized against the attributes.
	 *
	 * @param list<AttributeDefinition>                                            $attributes
	 * @param list<array{id:int,combination:?Combination,fields:array<string,mixed>}>|null $input
	 * @return list<Variant>
	 */
	private function plan_variations( Product $before, array $attributes, ?array $input ): array {
		$existing = [];
		foreach ( $before->variations as $variation ) {
			$existing[ $variation->id ] = $variation;
		}

		$planned = [];
		if ( $input === null ) {
			foreach ( $before->variations as $variation ) {
				$planned[] = [ $variation, 'variations', false ];
			}
		} else {
			foreach ( $input as $i => $item ) {
				$path = 'variations.' . $i;
				if ( $item['id'] > 0 ) {
					if ( ! isset( $existing[ $item['id'] ] ) ) {
						throw new InvalidProduct( __( 'The variation does not belong to this product.', 'flexacommerce' ), $path );
					}
					$row = $existing[ $item['id'] ];
				} else {
					if ( $item['combination'] === null ) {
						throw new InvalidProduct( __( 'A new variation needs attribute values.', 'flexacommerce' ), $path . '.attributes' );
					}
					$row = new Variant( id: 0, product_id: $before->id, is_base: false, combination: $item['combination'], menu_order: $i );
				}
				$changes = $item['fields'];
				if ( $item['combination'] !== null ) {
					$changes['combination'] = $item['combination'];
				}
				$planned[] = [ $row->with( $changes ), $path, $item['combination'] !== null ];
			}
		}

		if ( count( $planned ) > self::MAX_VARIANTS ) {
			throw new InvalidProduct(
				/* translators: %d: maximum number of variations */
				sprintf( __( 'A product can have at most %d variations.', 'flexacommerce' ), self::MAX_VARIANTS ),
				'variations'
			);
		}

		$axes = [];
		foreach ( $attributes as $attribute ) {
			if ( $attribute->variation ) {
				$axes[ $attribute->key ] = $attribute;
			}
		}
		if ( $axes === [] && $input !== null && $planned !== [] ) {
			throw new InvalidProduct( __( 'Mark at least one attribute as used for variations first.', 'flexacommerce' ), 'attributes' );
		}

		$out    = [];
		$hashes = [];
		foreach ( $planned as [ $row, $path, $explicit ] ) {
			$combination = $this->normalize( $row->combination, $axes );
			if ( $combination === null && $explicit ) {
				throw new InvalidProduct( __( 'The variation uses a value the product does not offer.', 'flexacommerce' ), $path . '.attributes' );
			}
			if ( $combination === null ) {
				// Stale: a value is no longer offered. Keep the row only if it holds stock.
				if ( $row->id > 0 && $row->has_stock_on_hand() ) {
					$row                                 = $row->with( [ 'enabled' => false ] );
					$out[]                               = $row;
					$hashes[ $row->combination->hash() ] = $path;
				}
				continue;
			}
			$hash = $combination->hash();
			if ( isset( $hashes[ $hash ] ) && ! $explicit ) {
				// Dropping an axis made two stored rows identical: the later one is stale.
				if ( $row->id > 0 && $row->has_stock_on_hand() ) {
					$out[] = $row->with( [ 'enabled' => false ] );
				}
				continue;
			}
			if ( isset( $hashes[ $hash ] ) ) {
				throw new InvalidProduct( __( 'Two variations have the same attribute values.', 'flexacommerce' ), $path . '.attributes' );
			}
			$row             = $row->with( [ 'combination' => $combination ] );
			$hashes[ $hash ] = $path;
			$this->validate_row( $row, $path );
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Drops keys of attributes no longer used for variations and fills new
	 * ones with the wildcard. Null when a value is not offered any more or
	 * nothing is left.
	 *
	 * @param array<string,AttributeDefinition> $axes
	 */
	private function normalize( Combination $combination, array $axes ): ?Combination {
		$pairs = [];
		foreach ( $axes as $key => $attribute ) {
			$value = $combination->get( $key ) ?? Combination::WILDCARD;
			if ( $value !== Combination::WILDCARD && ! $attribute->allows( $value ) ) {
				return null;
			}
			$pairs[ $key ] = $value;
		}
		return $pairs === [] ? null : Combination::from_array( $pairs );
	}

	private function validate_row( Variant $row, string $path ): void {
		if ( $row->sale_price !== null && $row->regular_price !== null && $row->sale_price >= $row->regular_price ) {
			throw new InvalidProduct( __( 'The sale price must be lower than the regular price.', 'flexacommerce' ), $path . '.sale_price' );
		}
		if ( $row->sale_price !== null && $row->regular_price === null ) {
			throw new InvalidProduct( __( 'Set a regular price before a sale price.', 'flexacommerce' ), $path . '.regular_price' );
		}
		if ( $row->sale_from !== null && $row->sale_to !== null && $row->sale_from >= $row->sale_to ) {
			throw new InvalidProduct( __( 'The sale must end after it starts.', 'flexacommerce' ), $path . '.sale_to' );
		}
		if ( $row->is_base && $row->stock_from_base ) {
			throw new InvalidProduct( __( 'The base row cannot take stock from itself.', 'flexacommerce' ), $path . '.stock_from_base' );
		}
	}

	/**
	 * SKUs must be unique ignoring case, inside the save and across the store.
	 * The unique index is the final guard; this check gives a clearer error.
	 *
	 * @param list<Variant> $rows
	 */
	private function check_skus( int $product_id, array $rows ): void {
		$seen = [];
		foreach ( $rows as $row ) {
			if ( $row->sku === null ) {
				continue;
			}
			$key = mb_strtolower( $row->sku );
			if ( isset( $seen[ $key ] ) ) {
				throw new DuplicateSku( $row->sku, $product_id );
			}
			$seen[ $key ] = true;

			$holder = $this->variants->find_by_sku( $row->sku );
			if ( $holder !== null && $holder->product_id !== $product_id ) {
				throw new DuplicateSku( $row->sku, $holder->product_id );
			}
		}
	}

	/** Deletes, then frees unique keys of changed rows, then updates and inserts. */
	private function write_rows( Product $before, Product $after ): void {
		$old = [];
		foreach ( $before->variations as $variation ) {
			$old[ $variation->id ] = $variation;
		}
		$kept = [];
		foreach ( $after->variations as $variation ) {
			if ( $variation->id > 0 ) {
				$kept[ $variation->id ] = true;
			}
		}
		$removed = array_values( array_diff( array_keys( $old ), array_keys( $kept ) ) );
		if ( $removed !== [] ) {
			$this->variants->delete_many( $after->id, $removed );
		}

		$updates = [];
		$release = [];
		$rows    = $after->variations;
		if ( $after->base->id > 0 ) {
			$old[ $after->base->id ] = $before->base;
			$rows[]                  = $after->base;
		}
		foreach ( $rows as $row ) {
			if ( $row->id === 0 || ! isset( $old[ $row->id ] ) ) {
				continue;
			}
			$previous = $old[ $row->id ];
			if ( $previous->to_row() === $row->to_row() ) {
				continue;
			}
			$updates[] = $row;
			if ( $previous->sku !== $row->sku || ! $previous->combination->equals( $row->combination ) ) {
				$release[] = $row->id;
			}
		}

		if ( $release !== [] ) {
			$this->variants->release_unique_keys( $after->id, $release );
		}
		foreach ( $updates as $row ) {
			$this->variants->update( $row );
		}
		if ( $after->base->id === 0 ) {
			$this->variants->insert( $after->base );
		}
		foreach ( $after->variations as $row ) {
			if ( $row->id === 0 ) {
				$this->variants->insert( $row );
			}
		}
	}

	/**
	 * Every combination of the axes, one at a time.
	 *
	 * @param array<string,list<string>> $axes
	 * @return \Generator<int,array<string,string>>
	 */
	private static function cartesian( array $axes ): \Generator {
		$keys    = array_keys( $axes );
		$indexes = array_fill( 0, count( $keys ), 0 );
		while ( true ) {
			$pairs = [];
			foreach ( $keys as $i => $key ) {
				$pairs[ $key ] = $axes[ $key ][ $indexes[ $i ] ];
			}
			yield $pairs;

			for ( $i = count( $keys ) - 1; $i >= 0; $i-- ) {
				if ( ++$indexes[ $i ] < count( $axes[ $keys[ $i ] ] ) ) {
					continue 2;
				}
				$indexes[ $i ] = 0;
			}
			return;
		}
	}
}
