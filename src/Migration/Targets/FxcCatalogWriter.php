<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Catalog\ProductService;
use Flexa\Commerce\Catalog\ProductVideo;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Catalog\VariantRepository;
use Flexa\Commerce\Contracts\Exceptions\DuplicateSku;
use Flexa\Commerce\Contracts\Exceptions\InvalidAttribute;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Contracts\Exceptions\ProductNotFound;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Attributes, terms and products into FlexaCommerce (doc 05 §5.3). Variants
 * are mapped as entity `variant`; the base row of a product is mapped under
 * the source product id, which never collides with a variation id because
 * both are post ids.
 */
final class FxcCatalogWriter {
	/** Post meta holding the source meta the mapping does not cover. */
	public const IMPORT_META = '_fxc_import';

	public const ORDERBY_META = '_fxc_orderby';

	public const ARCHIVES_META = '_fxc_archives';

	private const POST_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	public function __construct(
		private readonly AttributeRepository $attributes,
		private readonly ProductService $products,
		private readonly VariantRepository $variants,
	) {}

	public function attribute( Record $record, Mapping $map ): WriteResult {
		$data   = $record->data;
		$name   = (string) ( $data['name'] ?? '' );
		$target = $map->target_id( Record::ATTRIBUTE, $record->source_id() );
		$term   = $target > 0 ? $this->attributes->attribute( $target ) : null;
		try {
			if ( $term !== null ) {
				if ( $term->name !== $name ) {
					$this->attributes->rename( $target, $name );
				}
				$action = WriteResult::UPDATED;
			} else {
				$target = $this->attributes->create_attribute( $name, (string) ( $data['slug'] ?? '' ) );
				$action = WriteResult::CREATED;
			}
		} catch ( InvalidAttribute $e ) {
			throw new Rejected( 'invalid_attribute', $e->getMessage() );
		}
		update_term_meta( $target, self::ORDERBY_META, (string) ( $data['orderby'] ?? 'menu_order' ) );
		update_term_meta( $target, self::ARCHIVES_META, ! empty( $data['archives'] ) ? '1' : '0' );
		$map->remember( Record::ATTRIBUTE, $record->source_id(), $target, $record->checksum );
		return new WriteResult( $action, $target );
	}

	public function term( Record $record, Mapping $map ): WriteResult {
		$data     = $record->data;
		$problems = [];
		if ( ( $data['kind'] ?? '' ) === 'attribute_value' ) {
			$target = $this->attribute_value( $record, $map );
			$action = $target[1];
			$target = $target[0];
		} else {
			[ $target, $action, $problems ] = $this->taxonomy_term( $record, $map );
		}
		if ( is_int( $data['order'] ?? null ) ) {
			update_term_meta( $target, AttributeRepository::POSITION_META, $data['order'] );
		}
		$image = $this->attachment( $data['image'] ?? null, $map, $problems );
		if ( $image > 0 ) {
			update_term_meta( $target, 'thumbnail_id', $image );
		}
		$extra = self::extra_of( $data['extra'] ?? null, $problems );
		if ( $extra !== null ) {
			update_term_meta( $target, self::IMPORT_META, wp_slash( (string) wp_json_encode( $extra ) ) );
		}
		$map->remember( Record::TERM, $record->source_id(), $target, $record->checksum );
		return new WriteResult( $action, $target, $problems );
	}

	/** @return array{0:int,1:string} */
	private function attribute_value( Record $record, Mapping $map ): array {
		$data      = $record->data;
		$attribute = $map->target_id( Record::ATTRIBUTE, Ref::parse( is_string( $data['attribute'] ?? null ) ? $data['attribute'] : null )->id ?? '' );
		if ( $attribute === 0 || $this->attributes->attribute( $attribute ) === null ) {
			throw new Rejected( 'missing_attribute', 'The attribute of this value was not imported.' );
		}
		$name   = (string) ( $data['name'] ?? '' );
		$target = $map->target_id( Record::TERM, $record->source_id() );
		$term   = $target > 0 ? $this->attributes->value( $attribute, $target ) : null;
		try {
			if ( $term !== null ) {
				if ( $term->name !== $name ) {
					$this->attributes->rename( $target, $name );
				}
				return [ $target, WriteResult::UPDATED ];
			}
			return [ $this->attributes->create_value( $attribute, $name, (string) ( $data['slug'] ?? '' ) ), WriteResult::CREATED ];
		} catch ( InvalidAttribute $e ) {
			throw new Rejected( 'invalid_attribute_value', $e->getMessage() );
		}
	}

	/** @return array{0:int,1:string,2:list<Problem>} */
	private function taxonomy_term( Record $record, Mapping $map ): array {
		$data     = $record->data;
		$taxonomy = ( $data['kind'] ?? '' ) === 'tag' ? PostTypes::TAG : PostTypes::CATEGORY;
		$parent   = 0;
		$problems = [];
		if ( $taxonomy === PostTypes::CATEGORY && is_string( $data['parent'] ?? null ) ) {
			$parent = $map->target_id( Record::TERM, Ref::parse( $data['parent'] )->id ?? '' );
			if ( $parent === 0 ) {
				$problems[] = Problem::warning( 'missing_parent', 'The parent category was not imported; the category is now top level.', [ 'parent' => $data['parent'] ] );
			}
		}
		$args   = [
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'parent'      => $parent,
		];
		$name   = (string) ( $data['name'] ?? '' );
		$target = $map->target_id( Record::TERM, $record->source_id() );
		if ( $target > 0 && get_term( $target, $taxonomy ) instanceof \WP_Term ) {
			$result = wp_update_term( $target, $taxonomy, [ 'name' => $name ] + $args );
			if ( is_wp_error( $result ) ) {
				throw new Rejected( 'term_failed', $result->get_error_message() );
			}
			return [ $target, WriteResult::UPDATED, $problems ];
		}
		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			$existing = (int) $result->get_error_data( 'term_exists' );
			if ( $existing <= 0 ) {
				throw new Rejected( 'term_failed', $result->get_error_message() );
			}
			$problems[] = Problem::info( 'term_adopted', sprintf( 'A term named "%s" already existed and was used.', $name ), [ 'term_id' => $existing ] );
			return [ $existing, WriteResult::UPDATED, $problems ];
		}
		return [ (int) $result['term_id'], WriteResult::CREATED, $problems ];
	}

	public function product( Record $record, Mapping $map, Job $job ): WriteResult {
		$data     = $record->data;
		$problems = [];
		$target   = $map->target_id( Record::PRODUCT, $record->source_id() );
		$post     = $target > 0 ? get_post( $target ) : null;
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::PRODUCT ) {
			$target = 0;
		}
		$action = $target > 0 ? WriteResult::UPDATED : WriteResult::CREATED;

		foreach ( [ 'virtual', 'downloadable' ] as $flag ) {
			if ( ! empty( $data[ $flag ] ) ) {
				$problems[] = Problem::warning( 'unsupported_' . $flag, sprintf( 'The product is %s; FlexaCommerce imports it as a physical product.', $flag ) );
			}
		}

		$target = $this->save_post( $target, $data, $map, $problems );
		$this->save_terms( $target, $data, $map, $problems );

		$image = $this->attachment( $data['image'] ?? null, $map, $problems );
		if ( $image > 0 ) {
			set_post_thumbnail( $target, $image );
		} else {
			delete_post_thumbnail( $target );
		}
		$gallery = [];
		foreach ( is_array( $data['gallery'] ?? null ) ? $data['gallery'] : [] as $ref ) {
			$id = $this->attachment( $ref, $map, $problems );
			if ( $id > 0 ) {
				$gallery[] = $id;
			}
		}

		$video = false;
		if ( is_array( $data['video'] ?? null ) ) {
			$entry      = $data['video'];
			$attachment = is_string( $entry['attachment'] ?? null ) ? ( $this->attachment( $entry['attachment'], $map, $problems ) ?? 0 ) : 0;
			try {
				$video = ProductVideo::parse( is_string( $entry['url'] ?? null ) ? $entry['url'] : '', $attachment, is_string( $entry['position'] ?? null ) ? $entry['position'] : 'second' );
			} catch ( InvalidProduct ) {
				$problems[] = Problem::warning( 'video_skipped', 'The gallery video could not be read, so it was skipped.' );
			}
		}

		[ $raw, $axes ] = $this->attributes_of( $data, $map, $problems );
		$kind           = ( $data['kind'] ?? 'simple' ) === 'variable' ? 'variable' : 'simple';
		$base           = $this->fields( is_array( $data['base'] ?? null ) ? $data['base'] : [], 'base', $map, $problems );
		unset( $base['stock_from_base'] );

		try {
			$before = $this->products->get( $target );
		} catch ( ProductNotFound $e ) {
			throw new Rejected( 'product_failed', $e->getMessage() );
		}
		$existing = [];
		foreach ( $before->variations as $variation ) {
			$existing[ $variation->id ] = true;
		}

		$variations = [];
		$sources    = [];
		$hashes     = [];
		if ( $kind === 'variable' ) {
			foreach ( is_array( $data['variants'] ?? null ) ? $data['variants'] : [] as $variant ) {
				if ( ! is_array( $variant ) ) {
					continue;
				}
				$source      = (string) ( Ref::parse( is_string( $variant['id'] ?? null ) ? $variant['id'] : null )->id ?? '' );
				$combination = $this->combination( is_array( $variant['combination'] ?? null ) ? $variant['combination'] : [], $axes );
				if ( $combination === null ) {
					$problems[] = Problem::warning( 'variant_skipped', 'A variation uses an attribute value that was not imported; it was skipped.', [ 'variant' => $variant['id'] ?? null ] );
					continue;
				}
				$hash = $combination->hash();
				if ( isset( $hashes[ $hash ] ) ) {
					$problems[] = Problem::warning( 'duplicate_combination', 'Two variations have the same attribute values; the later one was skipped.', [ 'variant' => $variant['id'] ?? null ] );
					continue;
				}
				$hashes[ $hash ]      = true;
				$fields               = $this->fields( $variant, 'variations.' . count( $variations ), $map, $problems );
				$fields['menu_order'] = count( $variations );
				$id                   = $map->target_id( 'variant', $source );
				$variations[]         = [
					'id'          => isset( $existing[ $id ] ) ? $id : 0,
					'combination' => $combination,
					'fields'      => $fields,
				];
				$sources[ $hash ]     = $source;
			}
			if ( $variations !== [] && $axes === [] ) {
				$problems[] = Problem::warning( 'variants_skipped', 'No attribute used for variations was imported, so the variations were skipped.' );
				$variations = [];
				$sources    = [];
			}
		}

		$this->apply_sku_policy( $target, $base, $variations, $job, $problems );

		try {
			$product = $this->products->save(
				$target,
				new ProductInput(
					type: $kind,
					attributes: $raw,
					base: $base,
					variations: $variations,
					gallery_ids: $gallery,
					visibility: in_array( $data['visibility'] ?? '', [ 'visible', 'catalog', 'search', 'hidden' ], true ) ? (string) $data['visibility'] : 'visible',
					featured: (bool) ( $data['featured'] ?? false ),
					video: $video,
				)
			);
		} catch ( InvalidProduct | DuplicateSku | ProductNotFound $e ) {
			throw new Rejected( 'invalid_product', $e->getMessage(), $e instanceof InvalidProduct ? [ 'field' => $e->field ] : [] );
		}

		$map->remember( 'variant', $record->source_id(), $product->base->id );
		foreach ( $product->variations as $variation ) {
			$source = $sources[ $variation->combination->hash() ] ?? null;
			if ( $source !== null && $source !== '' ) {
				$map->remember( 'variant', $source, $variation->id );
			}
		}

		// Variation meta is keyed by the FlexaCommerce variant id, so a FlexaCommerce source can hand it back.
		$extra   = self::extra_of( $data['extra'] ?? null, $problems );
		$own     = [];
		$created = [];
		foreach ( $product->variations as $variation ) {
			$source = $sources[ $variation->combination->hash() ] ?? null;
			if ( $source !== null ) {
				$created[ $source ] = $variation->id;
			}
		}
		foreach ( is_array( $data['variants'] ?? null ) ? $data['variants'] : [] as $variant ) {
			$meta   = is_array( $variant ) ? self::extra_of( $variant['extra'] ?? null, $problems ) : null;
			$source = is_array( $variant ) ? (string) ( Ref::parse( is_string( $variant['id'] ?? null ) ? $variant['id'] : null )->id ?? '' ) : '';
			if ( $meta !== null && isset( $created[ $source ] ) ) {
				$own[ (string) $created[ $source ] ] = $meta['meta'];
			}
		}
		if ( $own !== [] ) {
			$extra = ( $extra ?? [
				'source' => 'woocommerce',
				'meta'   => [],
			] ) + [ 'variants' => $own ];
		}
		if ( $extra !== null ) {
			update_post_meta( $target, self::IMPORT_META, wp_slash( (string) wp_json_encode( $extra ) ) );
		} else {
			delete_post_meta( $target, self::IMPORT_META );
		}

		$map->remember( Record::PRODUCT, $record->source_id(), $target, $record->checksum );
		return new WriteResult( $action, $target, $problems );
	}

	public function modified_at( int $product_id ): ?string {
		$post = get_post( $product_id );
		return $post instanceof \WP_Post && $post->post_type === PostTypes::PRODUCT ? $post->post_modified_gmt : null;
	}

	public function delete( string $entity, int $target_id ): bool {
		if ( $entity === Record::PRODUCT ) {
			$post = get_post( $target_id );
			return $post instanceof \WP_Post && $post->post_type === PostTypes::PRODUCT && wp_delete_post( $target_id, true ) !== false;
		}
		if ( $entity === Record::TERM || $entity === Record::ATTRIBUTE ) {
			$term = get_term( $target_id );
			if ( ! $term instanceof \WP_Term ) {
				return false;
			}
			return wp_delete_term( $target_id, $term->taxonomy ) === true;
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param list<Problem>       $problems
	 */
	private function save_post( int $target, array $data, Mapping $map, array &$problems ): int {
		$status = (string) ( $data['status'] ?? 'draft' );
		if ( ! in_array( $status, self::POST_STATUSES, true ) ) {
			$problems[] = Problem::warning( 'post_status', sprintf( 'The status "%s" is not supported; the product is a draft.', $status ) );
			$status     = 'draft';
		}
		$author  = (int) ( $data['author'] ?? 0 );
		$created = Dates::to_db( is_string( $data['created_at'] ?? null ) ? $data['created_at'] : null );
		$post    = [
			'post_type'      => PostTypes::PRODUCT,
			'post_title'     => (string) ( $data['title'] ?? '' ),
			'post_content'   => (string) ( $data['content'] ?? '' ),
			'post_excerpt'   => (string) ( $data['excerpt'] ?? '' ),
			'post_name'      => (string) ( $data['slug'] ?? '' ),
			'post_status'    => $status,
			'post_author'    => $map->user_id( $author ),
			'menu_order'     => (int) ( $data['menu_order'] ?? 0 ),
			'comment_status' => ( $data['comment_status'] ?? 'open' ) === 'closed' ? 'closed' : 'open',
		];
		if ( $created !== null ) {
			$post['post_date_gmt'] = $created;
			$post['post_date']     = get_date_from_gmt( $created );
		}
		if ( $target > 0 ) {
			$post['ID'] = $target;
		}
		$result = $target > 0 ? wp_update_post( wp_slash( $post ), true ) : wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $result ) ) {
			throw new Rejected( 'post_failed', $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param list<Problem>       $problems
	 */
	private function save_terms( int $post_id, array $data, Mapping $map, array &$problems ): void {
		foreach ( [
			'categories' => PostTypes::CATEGORY,
			'tags'       => PostTypes::TAG,
		] as $key => $taxonomy ) {
			$ids = [];
			foreach ( is_array( $data[ $key ] ?? null ) ? $data[ $key ] : [] as $ref ) {
				$id = $map->target_id( Record::TERM, Ref::parse( is_string( $ref ) ? $ref : null )->id ?? '' );
				if ( $id > 0 ) {
					$ids[] = $id;
				} else {
					$problems[] = Problem::warning( 'missing_term', 'A category or tag of the product was not imported.', [ 'term' => $ref ] );
				}
			}
			$result = wp_set_object_terms( $post_id, $ids, $taxonomy );
			if ( is_wp_error( $result ) ) {
				throw new Rejected( 'term_failed', $result->get_error_message() );
			}
		}
	}

	/**
	 * Raw attribute definitions for ProductInput, and the variation axes:
	 * canonical key => [FlexaCommerce key, canonical value => value key].
	 *
	 * @param array<string,mixed> $data
	 * @param list<Problem>       $problems
	 * @return array{0:list<array<string,mixed>>,1:array<string,array{0:string,1:array<string,string>}>}
	 */
	private function attributes_of( array $data, Mapping $map, array &$problems ): array {
		$raw  = [];
		$axes = [];
		$keys = [];
		foreach ( is_array( $data['attributes'] ?? null ) ? $data['attributes'] : [] as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$key    = (string) ( $attribute['key'] ?? '' );
			$values = is_array( $attribute['values'] ?? null ) ? $attribute['values'] : [];
			if ( ! empty( $attribute['global'] ) ) {
				$term_id = $map->target_id( Record::ATTRIBUTE, Ref::parse( $key )->id ?? '' );
				if ( $term_id === 0 || $this->attributes->attribute( $term_id ) === null ) {
					$problems[] = Problem::warning( 'missing_attribute', sprintf( 'The attribute "%s" was not imported and was left out.', (string) ( $attribute['name'] ?? $key ) ), [ 'attribute' => $key ] );
					continue;
				}
				$fxc_key = Combination::global_key( $term_id );
				$ids     = [];
				$lookup  = [];
				foreach ( $values as $value ) {
					$id = $map->target_id( Record::TERM, Ref::parse( is_string( $value ) ? $value : null )->id ?? '' );
					if ( $id > 0 && $this->attributes->value( $term_id, $id ) !== null ) {
						$ids[]                     = $id;
						$lookup[ (string) $value ] = (string) $id;
					} else {
						$problems[] = Problem::warning( 'missing_attribute_value', 'An attribute value was not imported and was left out.', [ 'value' => $value ] );
					}
				}
				$entry = [
					'type'              => 'global',
					'attribute_term_id' => $term_id,
					'value_term_ids'    => array_values( array_unique( $ids ) ),
				];
			} else {
				$name    = trim( (string) ( $attribute['name'] ?? '' ) );
				$fxc_key = Combination::local_key( $name );
				$lookup  = [];
				$clean   = [];
				foreach ( $values as $value ) {
					$slug = Combination::local_value( (string) $value );
					if ( $slug === '' || isset( $lookup[ (string) $value ] ) || in_array( $slug, $lookup, true ) ) {
						continue;
					}
					$lookup[ (string) $value ] = $slug;
					$clean[]                   = (string) $value;
				}
				$entry = [
					'type'   => 'local',
					'name'   => $name,
					'values' => $clean,
				];
			}
			if ( isset( $keys[ $fxc_key ] ) ) {
				$problems[] = Problem::warning( 'duplicate_attribute', sprintf( 'The attribute "%s" is listed twice; the second one was left out.', (string) ( $attribute['name'] ?? $key ) ) );
				continue;
			}
			$keys[ $fxc_key ] = true;
			$entry           += [
				'visible'   => (bool) ( $attribute['visible'] ?? true ),
				'variation' => (bool) ( $attribute['variation'] ?? false ),
			];
			$raw[]            = $entry;
			if ( $entry['variation'] ) {
				$axes[ $key ] = [ $fxc_key, $lookup ];
			}
		}
		return [ $raw, $axes ];
	}

	/**
	 * @param array<array-key,mixed>                            $source canonical key => value ref, raw value or '*'
	 * @param array<string,array{0:string,1:array<string,string>}> $axes
	 */
	private function combination( array $source, array $axes ): ?Combination {
		$pairs = [];
		foreach ( $source as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $axes[ $key ] ) ) {
				return null;
			}
			[ $fxc_key, $lookup ] = $axes[ $key ];
			if ( $value === Combination::WILDCARD ) {
				$pairs[ $fxc_key ] = Combination::WILDCARD;
				continue;
			}
			$mapped = $lookup[ (string) $value ] ?? null;
			if ( $mapped === null && ! str_starts_with( $fxc_key, 'g:' ) ) {
				$slug   = Combination::local_value( (string) $value );
				$mapped = in_array( $slug, $lookup, true ) ? $slug : null;
			}
			if ( $mapped === null ) {
				return null;
			}
			$pairs[ $fxc_key ] = $mapped;
		}
		foreach ( $axes as [ $fxc_key ] ) {
			$pairs[ $fxc_key ] ??= Combination::WILDCARD;
		}
		try {
			return $pairs === [] ? null : Combination::from_array( $pairs );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * Canonical row fields as ProductInput fields.
	 *
	 * @param array<array-key,mixed> $row
	 * @param list<Problem>          $problems
	 * @return array<string,mixed>
	 */
	private function fields( array $row, string $path, Mapping $map, array &$problems ): array {
		$currency = Settings::string( 'currency' );
		$exponent = Currency::exponent( $currency );
		$fields   = [];
		foreach ( [ 'regular_price', 'sale_price' ] as $key ) {
			$money          = is_array( $row[ $key ] ?? null ) ? $row[ $key ] : null;
			$fields[ $key ] = null;
			if ( $money === null ) {
				continue;
			}
			if ( ( $money['currency'] ?? $currency ) !== $currency ) {
				$problems[] = Problem::warning( 'price_currency', sprintf( 'A price is in %1$s and the store uses %2$s; the amount was kept.', (string) $money['currency'], $currency ) );
			}
			[ $amount, $exact ] = Decimal::to_minor( (string) $money['amount'], $currency, $exponent );
			if ( ! $exact ) {
				$problems[] = Problem::warning( 'price_rounded', sprintf( 'The price %s was rounded to the currency precision.', (string) $money['amount'] ), [ 'field' => $path . '.' . $key ] );
			}
			$fields[ $key ] = max( 0, $amount );
		}
		if ( $fields['sale_price'] !== null && ( $fields['regular_price'] === null || $fields['sale_price'] >= $fields['regular_price'] ) ) {
			$problems[]           = Problem::warning( 'sale_dropped', 'The sale price is not lower than the regular price; the sale was dropped.', [ 'field' => $path ] );
			$fields['sale_price'] = null;
		}
		$from = Dates::to_db( is_string( $row['sale_from'] ?? null ) ? $row['sale_from'] : null );
		$to   = Dates::to_db( is_string( $row['sale_to'] ?? null ) ? $row['sale_to'] : null );
		if ( $from !== null && $to !== null && $from >= $to ) {
			$problems[] = Problem::warning( 'sale_dates_dropped', 'The sale ends before it starts; the sale dates were dropped.', [ 'field' => $path ] );
			$from       = null;
			$to         = null;
		}
		$fields['sale_from'] = $from;
		$fields['sale_to']   = $to;

		foreach ( [ 'enabled', 'manage_stock', 'stock_from_base', 'tax_class', 'description' ] as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$fields[ $key ] = $row[ $key ];
			}
		}
		foreach ( [
			'backorders'   => Variant::BACKORDERS,
			'stock_status' => Variant::STOCK_STATUSES,
			'tax_status'   => Variant::TAX_STATUSES,
		] as $key => $allowed ) {
			if ( ! array_key_exists( $key, $row ) ) {
				continue;
			}
			if ( in_array( $row[ $key ], $allowed, true ) ) {
				$fields[ $key ] = $row[ $key ];
			} else {
				$problems[] = Problem::warning( 'value_dropped', sprintf( 'The %1$s "%2$s" is not supported; the default was used.', $key, is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '' ), [ 'field' => $path . '.' . $key ] );
			}
		}
		if ( array_key_exists( 'sku', $row ) ) {
			$fields['sku'] = is_string( $row['sku'] ) && trim( $row['sku'] ) !== '' ? trim( $row['sku'] ) : null;
		}
		$quantity = $row['stock_quantity'] ?? null;
		if ( is_float( $quantity ) || ( is_string( $quantity ) && is_numeric( $quantity ) ) ) {
			$problems[] = Problem::warning( 'stock_rounded', sprintf( 'The stock quantity %s was rounded down to a whole number.', (string) $quantity ), [ 'field' => $path ] );
			$quantity   = (int) floor( (float) $quantity );
		}
		$fields['stock_quantity']      = is_int( $quantity ) ? $quantity : null;
		$fields['low_stock_threshold'] = is_int( $row['low_stock_threshold'] ?? null ) ? max( 0, $row['low_stock_threshold'] ) : null;

		// FlexaCommerce stores grams and millimetres, the same base as the canonical row.
		foreach ( [
			'weight' => 'weight_g',
			'length' => 'length_mm',
			'width'  => 'width_mm',
			'height' => 'height_mm',
		] as $key => $source ) {
			$value          = is_string( $row[ $source ] ?? null ) && Decimal::is_decimal( $row[ $source ], false ) ? Decimal::trim( Decimal::round( $row[ $source ], 3 ) ) : null;
			$fields[ $key ] = $value;
		}
		if ( array_key_exists( 'image', $row ) ) {
			$fields['image_id'] = $this->attachment( $row['image'], $map, $problems );
		}

		try {
			return ProductInput::fields( $fields, $path );
		} catch ( InvalidProduct $e ) {
			throw new Rejected( 'invalid_product', $e->getMessage(), [ 'field' => $e->field ] );
		}
	}

	/**
	 * Duplicate SKU policy (doc 05 §5.3): `null` clears the SKU, `skip`
	 * rejects the product, `suffix` appends -2, -3 and so on.
	 *
	 * @param array<string,mixed>                                                 $base
	 * @param list<array{id:int,combination:?Combination,fields:array<string,mixed>}> $variations
	 * @param list<Problem>                                                       $problems
	 */
	private function apply_sku_policy( int $product_id, array &$base, array &$variations, Job $job, array &$problems ): void {
		$policy = (string) $job->option( 'duplicate_sku' );
		$seen   = [];
		$fix    = function ( ?string $sku ) use ( $product_id, $policy, &$seen, &$problems ): ?string {
			if ( $sku === null ) {
				return null;
			}
			$taken = function ( string $candidate ) use ( $product_id, &$seen ): bool {
				if ( isset( $seen[ mb_strtolower( $candidate ) ] ) ) {
					return true;
				}
				$holder = $this->variants->find_by_sku( $candidate );
				return $holder !== null && $holder->product_id !== $product_id;
			};
			if ( ! $taken( $sku ) ) {
				$seen[ mb_strtolower( $sku ) ] = true;
				return $sku;
			}
			if ( $policy === 'skip' ) {
				throw new Rejected( 'duplicate_sku', sprintf( 'The SKU "%s" is already in use.', $sku ), [ 'sku' => $sku ] );
			}
			if ( $policy === 'suffix' ) {
				for ( $n = 2; $n < 1000; $n++ ) {
					$candidate = mb_substr( $sku, 0, ProductInput::MAX_SKU_LENGTH - strlen( '-' . $n ) ) . '-' . $n;
					if ( ! $taken( $candidate ) ) {
						$seen[ mb_strtolower( $candidate ) ] = true;
						$problems[]                          = Problem::warning( 'duplicate_sku', sprintf( 'The SKU "%1$s" is already in use; it was imported as "%2$s".', $sku, $candidate ), [ 'sku' => $sku ] );
						return $candidate;
					}
				}
			}
			$problems[] = Problem::warning( 'duplicate_sku', sprintf( 'The SKU "%s" is already in use; it was left empty.', $sku ), [ 'sku' => $sku ] );
			return null;
		};
		if ( array_key_exists( 'sku', $base ) ) {
			$base['sku'] = $fix( is_string( $base['sku'] ) ? $base['sku'] : null );
		}
		foreach ( $variations as $i => $variation ) {
			if ( array_key_exists( 'sku', $variation['fields'] ) ) {
				$variations[ $i ]['fields']['sku'] = $fix( is_string( $variation['fields']['sku'] ) ? $variation['fields']['sku'] : null );
			}
		}
	}

	/**
	 * Attachments are shared with the source site; the reference is kept
	 * when the attachment exists. A file from another site brings its own.
	 *
	 * @param list<Problem> $problems
	 */
	private function attachment( mixed $ref, Mapping $map, array &$problems ): ?int {
		if ( ! is_string( $ref ) ) {
			return null;
		}
		$id = $map->attachment_id( Ref::int_id( $ref ) );
		if ( $id > 0 ) {
			return $id;
		}
		$problems[] = Problem::warning( 'missing_media', 'An image is missing from the media library and was left out.', [ 'image' => $ref ] );
		return null;
	}

	/**
	 * Unmapped source meta worth keeping, or null. Values the source could
	 * not express are reported.
	 *
	 * @param list<Problem> $problems
	 * @return array{source:string,meta:array<string,mixed>}|null
	 */
	private static function extra_of( mixed $extra, array &$problems ): ?array {
		if ( ! is_array( $extra ) ) {
			return null;
		}
		$dropped = is_array( $extra['dropped'] ?? null ) ? $extra['dropped'] : [];
		if ( $dropped !== [] ) {
			$problems[] = Problem::warning( 'meta_dropped', 'Some meta values are PHP objects and were not imported.', [ 'keys' => array_values( $dropped ) ] );
		}
		$meta = is_array( $extra['meta'] ?? null ) ? $extra['meta'] : [];
		return $meta === [] ? null : [
			'source' => (string) ( $extra['source'] ?? 'woocommerce' ),
			'meta'   => $meta,
		];
	}
}
