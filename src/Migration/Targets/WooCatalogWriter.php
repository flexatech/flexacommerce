<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Migration\Sources\WooCatalogReader;

defined( 'ABSPATH' ) || exit;

/**
 * Attributes, terms and products into WooCommerce through its CRUD, the
 * reverse of WooCatalogReader. Variations are mapped as entity `variant`.
 */
final class WooCatalogWriter {
	public const SOURCE_META = '_fxc_source_id';

	private const POST_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	public function attribute( Record $record, Mapping $map ): WriteResult {
		$data   = $record->data;
		$slug   = wc_sanitize_taxonomy_name( (string) ( $data['slug'] ?? '' ) );
		$args   = [
			'name'         => (string) ( $data['name'] ?? '' ),
			'slug'         => $slug,
			'order_by'     => in_array( $data['orderby'] ?? '', [ 'menu_order', 'name', 'name_num', 'id' ], true ) ? (string) $data['orderby'] : 'menu_order',
			'has_archives' => ! empty( $data['archives'] ),
		];
		$target = $map->target_id( Record::ATTRIBUTE, $record->source_id() );
		if ( $target > 0 && wc_get_attribute( $target ) === null ) {
			$target = 0;
		}
		$problems = [];
		if ( $target > 0 ) {
			$result = wc_update_attribute( $target, $args );
			$action = WriteResult::UPDATED;
		} else {
			$result = wc_create_attribute( $args );
			$action = WriteResult::CREATED;
			if ( is_wp_error( $result ) && $slug !== '' && wc_attribute_taxonomy_id_by_name( $slug ) > 0 ) {
				$result     = (int) wc_attribute_taxonomy_id_by_name( $slug );
				$action     = WriteResult::UPDATED;
				$problems[] = Problem::info( 'attribute_adopted', sprintf( 'An attribute with the slug "%s" already existed and was used.', $slug ) );
				wc_update_attribute( $result, $args );
			}
		}
		if ( is_wp_error( $result ) ) {
			throw new Rejected( 'attribute_failed', $result->get_error_message() );
		}
		$target   = (int) $result;
		$taxonomy = wc_attribute_taxonomy_name_by_id( $target );
		if ( $taxonomy !== '' && ! taxonomy_exists( $taxonomy ) ) {
			// WooCommerce registers attribute taxonomies on init; one created in this request needs it now.
			register_taxonomy( $taxonomy, [ 'product' ], [ 'hierarchical' => false ] );
		}
		$map->remember( Record::ATTRIBUTE, $record->source_id(), $target, $record->checksum );
		return new WriteResult( $action, $target, $problems );
	}

	public function term( Record $record, Mapping $map ): WriteResult {
		$data     = $record->data;
		$problems = [];
		$kind     = (string) ( $data['kind'] ?? '' );
		$taxonomy = match ( $kind ) {
			'category' => 'product_cat',
			'tag'      => 'product_tag',
			default    => '',
		};
		if ( $kind === 'attribute_value' ) {
			$attribute = $map->target_id( Record::ATTRIBUTE, Ref::parse( is_string( $data['attribute'] ?? null ) ? $data['attribute'] : null )->id ?? '' );
			$taxonomy  = $attribute > 0 ? wc_attribute_taxonomy_name_by_id( $attribute ) : '';
			if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
				throw new Rejected( 'missing_attribute', 'The attribute of this value was not imported.' );
			}
		}
		if ( $taxonomy === '' ) {
			throw new Rejected( 'unsupported_term', sprintf( 'Terms of kind "%s" cannot be written.', $kind ) );
		}
		$parent = 0;
		if ( $taxonomy === 'product_cat' && is_string( $data['parent'] ?? null ) ) {
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
			$action = WriteResult::UPDATED;
		} else {
			$result = wp_insert_term( $name, $taxonomy, $args );
			$action = WriteResult::CREATED;
			if ( is_wp_error( $result ) && (int) $result->get_error_data( 'term_exists' ) > 0 ) {
				$existing   = (int) $result->get_error_data( 'term_exists' );
				$problems[] = Problem::info( 'term_adopted', sprintf( 'A term named "%s" already existed and was used.', $name ), [ 'term_id' => $existing ] );
				$result     = [ 'term_id' => $existing ];
				$action     = WriteResult::UPDATED;
			}
		}
		if ( is_wp_error( $result ) ) {
			throw new Rejected( 'term_failed', $result->get_error_message() );
		}
		$target = (int) $result['term_id'];
		if ( is_int( $data['order'] ?? null ) ) {
			update_term_meta( $target, 'order', $data['order'] );
		}
		$image = self::attachment( $data['image'] ?? null, $map, $problems );
		if ( $image > 0 ) {
			update_term_meta( $target, 'thumbnail_id', $image );
		}
		foreach ( self::extra_meta( $data['extra'] ?? null, $problems ) as $key => $value ) {
			update_term_meta( $target, $key, wp_slash( $value ) );
		}
		$map->remember( Record::TERM, $record->source_id(), $target, $record->checksum );
		return new WriteResult( $action, $target, $problems );
	}

	public function product( Record $record, Mapping $map, Job $job ): WriteResult {
		$data     = $record->data;
		$problems = [];
		$kind     = ( $data['kind'] ?? 'simple' ) === 'variable' ? 'variable' : 'simple';
		$target   = $map->target_id( Record::PRODUCT, $record->source_id() );
		$existing = $target > 0 ? wc_get_product( $target ) : null;
		if ( ! $existing instanceof \WC_Product || $existing->is_type( 'variation' ) ) {
			$target = 0;
		}
		$action = $target > 0 ? WriteResult::UPDATED : WriteResult::CREATED;
		if ( $target > 0 && $existing->get_type() !== $kind ) {
			wp_set_object_terms( $target, $kind, 'product_type' );
		}
		$product = wc_get_product_object( $kind, $target );

		$status = (string) ( $data['status'] ?? 'draft' );
		if ( ! in_array( $status, self::POST_STATUSES, true ) ) {
			$problems[] = Problem::warning( 'post_status', sprintf( 'The status "%s" is not supported; the product is a draft.', $status ) );
			$status     = 'draft';
		}
		$product->set_name( (string) ( $data['title'] ?? '' ) );
		$product->set_slug( (string) ( $data['slug'] ?? '' ) );
		$product->set_status( $status );
		$product->set_description( (string) ( $data['content'] ?? '' ) );
		$product->set_short_description( (string) ( $data['excerpt'] ?? '' ) );
		$product->set_menu_order( (int) ( $data['menu_order'] ?? 0 ) );
		$product->set_reviews_allowed( ( $data['comment_status'] ?? 'open' ) === 'open' );
		$product->set_catalog_visibility( in_array( $data['visibility'] ?? '', [ 'visible', 'catalog', 'search', 'hidden' ], true ) ? (string) $data['visibility'] : 'visible' );
		$product->set_featured( (bool) ( $data['featured'] ?? false ) );
		$product->set_virtual( (bool) ( $data['virtual'] ?? false ) );
		$product->set_downloadable( (bool) ( $data['downloadable'] ?? false ) );
		$created = Dates::to_timestamp( is_string( $data['created_at'] ?? null ) ? $data['created_at'] : null );
		if ( $created !== null ) {
			$product->set_date_created( $created );
		}
		$product->set_image_id( self::attachment( $data['image'] ?? null, $map, $problems ) ?? '' );
		$gallery = [];
		foreach ( is_array( $data['gallery'] ?? null ) ? $data['gallery'] : [] as $ref ) {
			$id = self::attachment( $ref, $map, $problems );
			if ( $id !== null ) {
				$gallery[] = $id;
			}
		}
		$product->set_gallery_image_ids( $gallery );
		$product->set_category_ids( $this->term_ids( $data['categories'] ?? null, $map, $problems ) );
		$product->set_tag_ids( $this->term_ids( $data['tags'] ?? null, $map, $problems ) );

		[ $attributes, $axes ] = $this->attributes_of( $data, $map, $problems );
		$product->set_attributes( $attributes );
		$base = is_array( $data['base'] ?? null ) ? $data['base'] : [];
		$this->apply_row( $product, $base, $job, $problems );

		foreach ( self::extra_meta( $data['extra'] ?? null, $problems ) as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->update_meta_data( self::SOURCE_META, $record->id );
		try {
			$target = $product->save();
		} catch ( \Exception $e ) {
			throw new Rejected( 'product_failed', $e->getMessage() );
		}
		if ( $target <= 0 ) {
			throw new Rejected( 'product_failed', 'WooCommerce did not save the product.' );
		}
		wp_set_object_terms( $target, $kind, 'product_type' );
		$author = $map->user_id( (int) ( $data['author'] ?? 0 ) );
		if ( $author > 0 && (int) get_post_field( 'post_author', $target ) !== $author ) {
			wp_update_post(
				[
					'ID'          => $target,
					'post_author' => $author,
				]
			);
		}

		$keep = [];
		if ( $kind === 'variable' ) {
			foreach ( array_values( is_array( $data['variants'] ?? null ) ? $data['variants'] : [] ) as $position => $variant ) {
				if ( ! is_array( $variant ) ) {
					continue;
				}
				$id = $this->variation( $target, $variant, $position, $axes, $map, $job, $problems );
				if ( $id > 0 ) {
					$keep[ $id ] = true;
				}
			}
		}
		foreach ( get_posts(
			[
				'post_type'   => 'product_variation',
				'post_parent' => $target,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		) as $child ) {
			$child = (int) $child;
			if ( ! isset( $keep[ $child ] ) ) {
				$variation = wc_get_product( $child );
				if ( $variation instanceof \WC_Product ) {
					$variation->delete( true );
				}
			}
		}
		if ( $kind === 'variable' ) {
			\WC_Product_Variable::sync( $target );
		}
		wc_delete_product_transients( $target );

		$map->remember( Record::PRODUCT, $record->source_id(), $target, $record->checksum );
		return new WriteResult( $action, $target, $problems );
	}

	public function modified_at( int $product_id ): ?string {
		$post = get_post( $product_id );
		return $post instanceof \WP_Post && $post->post_type === 'product' ? $post->post_modified_gmt : null;
	}

	public function delete( string $entity, int $target_id ): bool {
		if ( $entity === Record::PRODUCT ) {
			$product = wc_get_product( $target_id );
			return $product instanceof \WC_Product && ! $product->is_type( 'variation' ) && $product->delete( true );
		}
		if ( $entity === Record::ATTRIBUTE ) {
			return wc_get_attribute( $target_id ) !== null && wc_delete_attribute( $target_id ) === true;
		}
		if ( $entity === Record::TERM ) {
			$term = get_term( $target_id );
			return $term instanceof \WP_Term && wp_delete_term( $target_id, $term->taxonomy ) === true;
		}
		return false;
	}

	/**
	 * @param array<string,mixed>                                                            $variant
	 * @param array<string,array{key:string,global:bool,taxonomy:string,values:array<string,string>}> $axes
	 * @param list<Problem>                                                                  $problems
	 */
	private function variation( int $parent, array $variant, int $position, array $axes, Mapping $map, Job $job, array &$problems ): int {
		$source = (string) ( Ref::parse( is_string( $variant['id'] ?? null ) ? $variant['id'] : null )->id ?? '' );
		$pairs  = [];
		foreach ( is_array( $variant['combination'] ?? null ) ? $variant['combination'] : [] as $key => $value ) {
			$axis = $axes[ (string) $key ] ?? null;
			if ( $axis === null ) {
				$problems[] = Problem::warning( 'variant_skipped', 'A variation uses an attribute that was not imported; it was skipped.', [ 'variant' => $variant['id'] ?? null ] );
				return 0;
			}
			if ( $value === Combination::WILDCARD ) {
				$pairs[ $axis['key'] ] = '';
				continue;
			}
			$mapped = $axis['global'] ? ( $axis['values'][ (string) $value ] ?? null ) : (string) $value;
			if ( $mapped === null ) {
				$problems[] = Problem::warning( 'variant_skipped', 'A variation uses an attribute value that was not imported; it was skipped.', [ 'variant' => $variant['id'] ?? null ] );
				return 0;
			}
			$pairs[ $axis['key'] ] = $mapped;
		}
		foreach ( $axes as $axis ) {
			$pairs[ $axis['key'] ] ??= '';
		}

		$id        = $source !== '' ? $map->target_id( 'variant', $source ) : 0;
		$existing  = $id > 0 ? wc_get_product( $id ) : null;
		$variation = $existing instanceof \WC_Product_Variation && $existing->get_parent_id() === $parent ? $existing : new \WC_Product_Variation();
		$variation->set_parent_id( $parent );
		$variation->set_attributes( $pairs );
		$variation->set_status( ( $variant['enabled'] ?? true ) ? 'publish' : 'private' );
		$variation->set_menu_order( is_int( $variant['menu_order'] ?? null ) ? $variant['menu_order'] : $position );
		$variation->set_description( (string) ( $variant['description'] ?? '' ) );
		$variation->set_image_id( self::attachment( $variant['image'] ?? null, $map, $problems ) ?? '' );
		$this->apply_row( $variation, $variant, $job, $problems );
		foreach ( self::extra_meta( $variant['extra'] ?? null, $problems ) as $key => $value ) {
			$variation->update_meta_data( $key, $value );
		}
		$variation->update_meta_data( self::SOURCE_META, (string) ( $variant['id'] ?? '' ) );
		try {
			$id = $variation->save();
		} catch ( \Exception $e ) {
			$problems[] = Problem::warning( 'variant_failed', $e->getMessage(), [ 'variant' => $variant['id'] ?? null ] );
			return 0;
		}
		if ( $source !== '' && $id > 0 ) {
			$map->remember( 'variant', $source, $id );
		}
		return $id;
	}

	/**
	 * Canonical row fields onto a product or a variation.
	 *
	 * @param array<array-key,mixed> $row
	 * @param list<Problem>          $problems
	 */
	private function apply_row( \WC_Product $product, array $row, Job $job, array &$problems ): void {
		$product->set_regular_price( self::amount( $row['regular_price'] ?? null ) );
		$product->set_sale_price( self::amount( $row['sale_price'] ?? null ) );
		$from = Dates::to_timestamp( is_string( $row['sale_from'] ?? null ) ? $row['sale_from'] : null );
		$to   = Dates::to_timestamp( is_string( $row['sale_to'] ?? null ) ? $row['sale_to'] : null );
		$product->set_date_on_sale_from( $from ?? '' );
		// The canonical end is the first second the sale no longer applies; WooCommerce keeps the last one that does.
		$product->set_date_on_sale_to( $to !== null ? $to - 1 : '' );

		$own = ! empty( $row['manage_stock'] ) && empty( $row['stock_from_base'] );
		$product->set_manage_stock( $own );
		$quantity = $row['stock_quantity'] ?? null;
		$product->set_stock_quantity( $own && is_numeric( $quantity ) ? $quantity : null );
		if ( in_array( $row['backorders'] ?? null, [ 'no', 'notify', 'yes' ], true ) ) {
			$product->set_backorders( (string) $row['backorders'] );
		}
		if ( in_array( $row['stock_status'] ?? null, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
			$product->set_stock_status( (string) $row['stock_status'] );
		}
		$product->set_low_stock_amount( is_int( $row['low_stock_threshold'] ?? null ) ? $row['low_stock_threshold'] : '' );

		$weight    = WooCatalogReader::weight_factor();
		$dimension = WooCatalogReader::dimension_factor();
		$product->set_weight( (string) Decimal::from_base( is_string( $row['weight_g'] ?? null ) ? $row['weight_g'] : null, $weight ) );
		$product->set_length( (string) Decimal::from_base( is_string( $row['length_mm'] ?? null ) ? $row['length_mm'] : null, $dimension ) );
		$product->set_width( (string) Decimal::from_base( is_string( $row['width_mm'] ?? null ) ? $row['width_mm'] : null, $dimension ) );
		$product->set_height( (string) Decimal::from_base( is_string( $row['height_mm'] ?? null ) ? $row['height_mm'] : null, $dimension ) );
		if ( array_key_exists( 'tax_class', $row ) ) {
			$product->set_tax_class( (string) $row['tax_class'] );
		}
		if ( in_array( $row['tax_status'] ?? null, [ 'taxable', 'shipping', 'none' ], true ) ) {
			$product->set_tax_status( (string) $row['tax_status'] );
		}
		foreach ( [ 'virtual', 'downloadable' ] as $flag ) {
			if ( array_key_exists( $flag, $row ) ) {
				$product->{'set_' . $flag}( (bool) $row[ $flag ] );
			}
		}
		if ( is_array( $row['downloads'] ?? null ) ) {
			self::apply_downloads( $product, $row['downloads'], $problems );
		}
		$this->set_sku( $product, is_string( $row['sku'] ?? null ) ? trim( $row['sku'] ) : '', $job, $problems );
	}

	/**
	 * Downloadable files, limit and expiry (ADR-058). WooCommerce refuses files
	 * outside its approved download directories; those are dropped with a warning.
	 *
	 * @param array<array-key,mixed> $downloads
	 * @param list<Problem>          $problems
	 */
	private static function apply_downloads( \WC_Product $product, array $downloads, array &$problems ): void {
		$files = [];
		foreach ( is_array( $downloads['files'] ?? null ) ? $downloads['files'] : [] as $file ) {
			if ( ! is_array( $file ) || ! is_string( $file['file'] ?? null ) || $file['file'] === '' ) {
				continue;
			}
			$files[] = [
				'download_id' => is_string( $file['id'] ?? null ) ? $file['id'] : '',
				'name'        => is_string( $file['name'] ?? null ) ? $file['name'] : '',
				'file'        => $file['file'],
			];
		}
		try {
			$product->set_downloads( $files );
		} catch ( \WC_Data_Exception $e ) {
			$problems[] = Problem::warning( 'download_dropped', sprintf( 'The downloadable files were dropped: %s', $e->getMessage() ) );
			$product->set_downloads( [] );
		}
		$limit  = $downloads['limit'] ?? null;
		$expiry = $downloads['expiry_days'] ?? null;
		$product->set_download_limit( is_int( $limit ) && $limit > 0 ? $limit : -1 );
		$product->set_download_expiry( is_int( $expiry ) && $expiry > 0 ? $expiry : -1 );
	}

	/**
	 * Duplicate SKU policy of doc 05 §5.3, applied to what WooCommerce refuses.
	 *
	 * @param list<Problem> $problems
	 */
	private function set_sku( \WC_Product $product, string $sku, Job $job, array &$problems ): void {
		try {
			$product->set_sku( $sku );
			return;
		} catch ( \WC_Data_Exception ) {
			$policy = (string) $job->option( 'duplicate_sku' );
		}
		if ( $policy === 'skip' ) {
			throw new Rejected( 'duplicate_sku', sprintf( 'The SKU "%s" is already in use.', $sku ), [ 'sku' => $sku ] );
		}
		if ( $policy === 'suffix' ) {
			for ( $n = 2; $n < 1000; $n++ ) {
				try {
					$product->set_sku( $sku . '-' . $n );
					$problems[] = Problem::warning( 'duplicate_sku', sprintf( 'The SKU "%1$s" is already in use; it was imported as "%2$s".', $sku, $sku . '-' . $n ), [ 'sku' => $sku ] );
					return;
				} catch ( \WC_Data_Exception ) {
					continue;
				}
			}
		}
		$problems[] = Problem::warning( 'duplicate_sku', sprintf( 'The SKU "%s" is already in use; it was left empty.', $sku ), [ 'sku' => $sku ] );
		$product->set_sku( '' );
	}

	/**
	 * WooCommerce attributes, and the variation axes: canonical key =>
	 * [variation attribute key, global, taxonomy, canonical value => term slug].
	 *
	 * @param array<string,mixed> $data
	 * @param list<Problem>       $problems
	 * @return array{0:list<\WC_Product_Attribute>,1:array<string,array{key:string,global:bool,taxonomy:string,values:array<string,string>}>}
	 */
	private function attributes_of( array $data, Mapping $map, array &$problems ): array {
		$out  = [];
		$axes = [];
		foreach ( is_array( $data['attributes'] ?? null ) ? $data['attributes'] : [] as $position => $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$key    = (string) ( $attribute['key'] ?? '' );
			$values = is_array( $attribute['values'] ?? null ) ? $attribute['values'] : [];
			$item   = new \WC_Product_Attribute();
			if ( ! empty( $attribute['global'] ) ) {
				$id       = $map->target_id( Record::ATTRIBUTE, Ref::parse( $key )->id ?? '' );
				$taxonomy = $id > 0 ? wc_attribute_taxonomy_name_by_id( $id ) : '';
				if ( $taxonomy === '' ) {
					$problems[] = Problem::warning( 'missing_attribute', sprintf( 'The attribute "%s" was not imported and was left out.', (string) ( $attribute['name'] ?? $key ) ), [ 'attribute' => $key ] );
					continue;
				}
				$ids    = [];
				$lookup = [];
				foreach ( $values as $value ) {
					$term_id = $map->target_id( Record::TERM, Ref::parse( is_string( $value ) ? $value : null )->id ?? '' );
					$term    = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;
					if ( $term instanceof \WP_Term ) {
						$ids[]                     = $term_id;
						$lookup[ (string) $value ] = $term->slug;
					} else {
						$problems[] = Problem::warning( 'missing_attribute_value', 'An attribute value was not imported and was left out.', [ 'value' => $value ] );
					}
				}
				$item->set_id( $id );
				$item->set_name( $taxonomy );
				$item->set_options( $ids );
				$axis = [
					'key'      => sanitize_title( $taxonomy ),
					'global'   => true,
					'taxonomy' => $taxonomy,
					'values'   => $lookup,
				];
			} else {
				$name = trim( (string) ( $attribute['name'] ?? '' ) );
				if ( $name === '' ) {
					continue;
				}
				$item->set_id( 0 );
				$item->set_name( $name );
				$item->set_options( array_values( array_map( 'strval', $values ) ) );
				$axis = [
					'key'      => sanitize_title( $name ),
					'global'   => false,
					'taxonomy' => '',
					'values'   => [],
				];
			}
			$item->set_position( is_int( $attribute['position'] ?? null ) ? $attribute['position'] : (int) $position );
			$item->set_visible( (bool) ( $attribute['visible'] ?? true ) );
			$item->set_variation( (bool) ( $attribute['variation'] ?? false ) );
			$out[] = $item;
			if ( $item->get_variation() ) {
				$axes[ $key ] = $axis;
			}
		}
		return [ $out, $axes ];
	}

	/**
	 * @param list<Problem> $problems
	 * @return list<int>
	 */
	private function term_ids( mixed $refs, Mapping $map, array &$problems ): array {
		$ids = [];
		foreach ( is_array( $refs ) ? $refs : [] as $ref ) {
			$id = $map->target_id( Record::TERM, Ref::parse( is_string( $ref ) ? $ref : null )->id ?? '' );
			if ( $id > 0 ) {
				$ids[] = $id;
			} else {
				$problems[] = Problem::warning( 'missing_term', 'A category or tag of the product was not imported.', [ 'term' => $ref ] );
			}
		}
		return $ids;
	}

	private static function amount( mixed $money ): string {
		return is_array( $money ) && is_string( $money['amount'] ?? null ) ? $money['amount'] : '';
	}

	/** @param list<Problem> $problems */
	private static function attachment( mixed $ref, Mapping $map, array &$problems ): ?int {
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
	 * Source meta to write back as it was, keyed by meta key.
	 *
	 * @param list<Problem> $problems
	 * @return array<string,mixed>
	 */
	public static function extra_meta( mixed $extra, array &$problems ): array {
		if ( ! is_array( $extra ) ) {
			return [];
		}
		$dropped = is_array( $extra['dropped'] ?? null ) ? $extra['dropped'] : [];
		if ( $dropped !== [] ) {
			$problems[] = Problem::warning( 'meta_dropped', 'Some meta values are PHP objects and were not imported.', [ 'keys' => array_values( $dropped ) ] );
		}
		$out = [];
		foreach ( is_array( $extra['meta'] ?? null ) ? $extra['meta'] : [] as $key => $value ) {
			$key = (string) $key;
			if ( $key !== '' && ! str_starts_with( $key, '_fxc_' ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
