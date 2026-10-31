<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog\Csv;

use Flexa\Commerce\Catalog\AttributeDefinition;
use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Downloads;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Catalog\ProductPostInput;
use Flexa\Commerce\Catalog\ProductService;
use Flexa\Commerce\Catalog\ProductTypes;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Catalog\VariantRepository;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\DuplicateSku;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\Units;

defined( 'ABSPATH' ) || exit;

/**
 * Imports rows of a product CSV in the WooCommerce format (ADR-059). Rows
 * come in batches; the ImportContext carries what earlier batches created so
 * a variation row finds a parent from an earlier batch. Consecutive
 * variation rows of one parent are saved together. A check (`$dry_run`)
 * runs the same reading and validation and writes nothing.
 *
 * An empty list cell (categories, tags, images, attributes, downloads)
 * keeps what is stored; an empty scalar cell clears the value.
 *
 * @phpstan-type Row array{line:int,cells:list<string>}
 * @phpstan-type Result array{line:int,action:string,id:?int,sku:?string,name:string,message:string,warnings:list<string>}
 */
final class ProductCsvImporter {
	public const MAX_ROWS = 200;

	private const STATUSES = [
		'1'       => 'publish',
		'0'       => 'private',
		'-1'      => 'draft',
		'2'       => 'pending',
		'publish' => 'publish',
		'private' => 'private',
		'draft'   => 'draft',
		'pending' => 'pending',
	];

	private const STOCK = [
		'1'                  => Variant::INSTOCK,
		'0'                  => Variant::OUTOFSTOCK,
		'backorder'          => Variant::ONBACKORDER,
		Variant::INSTOCK     => Variant::INSTOCK,
		Variant::OUTOFSTOCK  => Variant::OUTOFSTOCK,
		Variant::ONBACKORDER => Variant::ONBACKORDER,
	];

	private const BACKORDERS = [
		'1'      => 'yes',
		'0'      => 'no',
		'yes'    => 'yes',
		'no'     => 'no',
		'notify' => 'notify',
	];

	/** Custom fields an import never writes. */
	private const PROTECTED_META = [ '_fxc', '_wp_', '_edit_', '_thumbnail_id' ];

	private bool $update = false;

	private bool $dry_run = false;

	private ImportContext $context;

	/** @var array<string,float> measure key => base units per file unit */
	private array $factors = [];

	/** @var array<string,int>|null lower-case name or slug => attribute id */
	private ?array $global_names = null;

	/** @var array<int,array<string,int>> attribute id => lower-case name or slug => value id */
	private array $global_values = [];

	public function __construct(
		private readonly ProductService $service,
		private readonly VariantRepository $variants,
		private readonly AttributeRepository $attributes,
		private readonly MediaResolver $media,
	) {
		$this->context = new ImportContext();
	}

	/**
	 * @param list<string> $headers
	 * @param list<string> $mapping the key of each column, '' to skip it
	 * @param list<Row>    $rows
	 * @return list<Result>
	 */
	public function import( array $headers, array $mapping, array $rows, ImportContext $context, bool $update, bool $dry_run ): array {
		$this->context = $context;
		$this->update  = $update;
		$this->dry_run = $dry_run;
		$this->factors = [];
		foreach ( $mapping as $i => $key ) {
			if ( in_array( $key, [ 'weight', 'length', 'width', 'height' ], true ) ) {
				$factors               = $key === 'weight' ? Units::GRAMS : Units::MILLIMETRES;
				$store                 = Settings::string( $key === 'weight' ? 'weight_unit' : 'dimension_unit' );
				$unit                  = CsvColumns::unit( $headers[ $i ] ?? '', $factors ) ?? $store;
				$this->factors[ $key ] = $factors[ $unit ] ?? ( $key === 'weight' ? 1000.0 : 10.0 );
			}
		}

		$records = [];
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			$record = [];
			foreach ( $mapping as $i => $key ) {
				if ( $key !== '' && ! str_starts_with( $key, 'unsupported:' ) ) {
					$record[ $key ] = CsvValues::unescape( (string) ( $row['cells'][ $i ] ?? '' ) );
				}
			}
			$records[] = [ $row['line'], $record ];
		}

		$results = [];
		$count   = count( $records );
		for ( $i = 0; $i < $count; ) {
			[ $line, $record ] = $records[ $i ];
			if ( ! self::is_variation( $record ) ) {
				$results[] = $this->product( $line, $record );
				++$i;
				continue;
			}
			$parent = trim( $record['parent_id'] ?? '' );
			$group  = [ $records[ $i ] ];
			++$i;
			while ( $i < $count && self::is_variation( $records[ $i ][1] ) && trim( $records[ $i ][1]['parent_id'] ?? '' ) === $parent ) {
				$group[] = $records[ $i ];
				++$i;
			}
			array_push( $results, ...$this->variations( $parent, $group ) );
		}
		return $results;
	}

	/**
	 * @param list<Result> $results
	 * @return array{created:int,updated:int,skipped:int,failed:int}
	 */
	public static function summary( array $results ): array {
		$counts = [
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		];
		$names  = [
			'create' => 'created',
			'update' => 'updated',
			'skip'   => 'skipped',
			'error'  => 'failed',
		];
		foreach ( $results as $result ) {
			++$counts[ $names[ $result['action'] ] ?? 'failed' ];
		}
		return $counts;
	}

	/**
	 * A simple or variable product row.
	 *
	 * @param array<string,string> $record
	 * @return Result
	 */
	private function product( int $line, array $record ): array {
		$name     = trim( $record['name'] ?? '' );
		$warnings = [];
		$sku      = null;
		try {
			$types = self::types( $record );
			foreach ( [ 'grouped', 'external' ] as $unsupported ) {
				if ( in_array( $unsupported, $types, true ) ) {
					/* translators: %s: WooCommerce product type */
					throw new InvalidProduct( sprintf( __( 'FlexaCommerce does not sell %s products.', 'flexacommerce' ), $unsupported ), 'type' );
				}
			}
			$sku     = array_key_exists( 'sku', $record ) ? ProductInput::sku( $record['sku'] ) : null;
			$file_id = trim( $record['id'] ?? '' );
			$this->claim_sku( $sku, $line );

			$existing = $this->find( $sku, $file_id );
			if ( $existing !== 0 && ! $this->update ) {
				$this->context->remember( $file_id, $sku, 0 );
				return self::result( $line, 'skip', $existing, $sku, $name, __( 'A product with this SKU already exists.', 'flexacommerce' ) );
			}
			$before = $existing > 0 ? $this->service->get( $existing ) : null;

			$type = null;
			if ( in_array( ProductTypes::VARIABLE, $types, true ) ) {
				$type = ProductTypes::VARIABLE;
			} elseif ( in_array( ProductTypes::SIMPLE, $types, true ) || $existing === 0 ) {
				$type = ProductTypes::SIMPLE;
			}

			$post = [];
			if ( $name !== '' ) {
				$post['title'] = $name;
			}
			$published = strtolower( trim( $record['published'] ?? '' ) );
			if ( $published !== '' ) {
				$post['status'] = self::STATUSES[ $published ] ?? throw new InvalidProduct( __( 'Published must be 1, 0, -1 or 2.', 'flexacommerce' ), 'published' );
			} elseif ( $existing === 0 ) {
				$post['status'] = 'publish';
			}
			if ( array_key_exists( 'description', $record ) ) {
				$post['content'] = self::text( $record['description'] );
			}
			if ( array_key_exists( 'short_description', $record ) ) {
				$post['excerpt'] = self::text( $record['short_description'] );
			}
			if ( trim( $record['menu_order'] ?? '' ) !== '' ) {
				$post['menu_order'] = trim( $record['menu_order'] );
			}

			$data = [];
			if ( $type !== null ) {
				$data['type'] = $type;
			}
			if ( trim( $record['catalog_visibility'] ?? '' ) !== '' ) {
				$data['visibility'] = strtolower( trim( $record['catalog_visibility'] ) );
			}
			if ( trim( $record['featured'] ?? '' ) !== '' ) {
				$data['featured'] = CsvValues::bool( $record['featured'] );
			}
			$data['base'] = $this->row_fields( $record, $before?->base, false, $warnings );
			self::check_prices( $data['base'], 'base' );

			// Formats first, so a check reports them without touching terms or media.
			ProductPostInput::from_array( $post );
			ProductInput::from_array( $data );

			$images = $this->images( CsvValues::split( $record['images'] ?? '' ), $warnings );
			if ( $images !== null ) {
				$featured = array_shift( $images );
				if ( $featured !== null && $featured > 0 ) {
					$post['featured_image'] = $featured;
				}
				$data['gallery_ids'] = array_values( array_filter( $images, static fn ( int $id ): bool => $id > 0 ) );
			}
			if ( trim( $record['category_ids'] ?? '' ) !== '' ) {
				$post['categories'] = $this->categories( $record['category_ids'], $warnings );
			}
			if ( trim( $record['tag_ids'] ?? '' ) !== '' ) {
				$post['tags'] = $this->tags( $record['tag_ids'], $warnings );
			}
			$attributes = $this->attribute_rows( $record, $before, $warnings );
			if ( $attributes !== null ) {
				$data['attributes'] = $attributes;
			}

			if ( $this->dry_run ) {
				$id = $existing !== 0 ? $existing : $this->context->pseudo();
				$this->context->remember( $file_id, $sku, $id );
				return self::result( $line, $existing !== 0 ? 'update' : 'create', $id > 0 ? $id : null, $sku, $name, '', $warnings );
			}

			$post_input = ProductPostInput::from_array( $post );
			$input      = ProductInput::from_array( $data );
			if ( $existing === 0 ) {
				$id = $post_input->apply( 0 );
				try {
					$this->service->save( $id, $input );
				} catch ( CommerceException $e ) {
					wp_delete_post( $id, true );
					throw $e;
				}
			} else {
				$id = $existing;
				$this->service->save( $id, $input );
				if ( ! $post_input->is_empty() ) {
					$post_input->apply( $id );
				}
			}
			if ( trim( $record['reviews_allowed'] ?? '' ) !== '' ) {
				wp_update_post(
					[
						'ID'             => $id,
						'comment_status' => CsvValues::bool( $record['reviews_allowed'] ) ? 'open' : 'closed',
					]
				);
			}
			$this->write_meta( $id, $record );
			$this->context->remember( $file_id, $sku, $id );
			return self::result( $line, $existing !== 0 ? 'update' : 'create', $id, $sku, $name, '', $warnings );
		} catch ( CommerceException | \InvalidArgumentException $e ) {
			return self::result( $line, 'error', null, $sku, $name, self::message( $e ), $warnings );
		}
	}

	/**
	 * Variation rows of one parent, saved together.
	 *
	 * @param list<array{0:int,1:array<string,string>}> $group
	 * @return list<Result>
	 */
	private function variations( string $parent_ref, array $group ): array {
		$parent_id = $parent_ref === '' ? null : $this->parent_of( $parent_ref );
		$message   = null;
		if ( $parent_ref === '' ) {
			$message = __( 'A variation needs a parent product.', 'flexacommerce' );
		} elseif ( $parent_id === null ) {
			/* translators: %s: the Parent cell */
			$message = sprintf( __( 'The parent product %s was not found.', 'flexacommerce' ), $parent_ref );
		}
		if ( $message !== null || $parent_id === 0 ) {
			$results = [];
			foreach ( $group as [ $line, $record ] ) {
				$results[] = $message !== null
					? self::result( $line, 'error', null, self::cell_sku( $record ), trim( $record['name'] ?? '' ), $message )
					: self::result( $line, 'skip', null, self::cell_sku( $record ), trim( $record['name'] ?? '' ), __( 'The parent product was skipped.', 'flexacommerce' ) );
			}
			return $results;
		}

		$product = null;
		if ( $parent_id > 0 ) {
			try {
				$product = $this->service->get( $parent_id );
			} catch ( CommerceException $e ) {
				$message = self::message( $e );
			}
			if ( $product !== null && ! $product->is_variable() ) {
				$message = __( 'The parent product is not a variable product.', 'flexacommerce' );
			}
		}

		/** @var array<string,array<string,mixed>> $definitions */
		$definitions = [];
		foreach ( $product !== null ? $product->attributes : [] as $definition ) {
			$definitions[ $definition->key ] = self::raw( $definition );
		}

		$results = [];
		$entries = [];
		foreach ( $group as [ $line, $record ] ) {
			$name     = trim( $record['name'] ?? '' );
			$sku      = null;
			$warnings = [];
			if ( $message !== null ) {
				$results[ $line ] = self::result( $line, 'error', null, self::cell_sku( $record ), $name, $message );
				continue;
			}
			try {
				$sku = array_key_exists( 'sku', $record ) ? ProductInput::sku( $record['sku'] ) : null;
				$this->claim_sku( $sku, $line );
				$pairs     = $this->combination( $record, $definitions, $warnings );
				$entries[] = [
					'line'     => $line,
					'record'   => $record,
					'sku'      => $sku,
					'name'     => $name,
					'pairs'    => $pairs,
					'warnings' => $warnings,
				];
			} catch ( CommerceException | \InvalidArgumentException $e ) {
				$results[ $line ] = self::result( $line, 'error', null, $sku, $name, self::message( $e ), $warnings );
			}
		}

		// The axes after this group: every attribute a row named is one now.
		$axes = [];
		foreach ( $definitions as $key => $definition ) {
			if ( ! empty( $definition['variation'] ) ) {
				$axes[] = $key;
			}
		}
		$stored = [];
		foreach ( $product !== null ? $product->variations : [] as $variation ) {
			$stored[ self::hash( $axes, static fn ( string $key ): ?string => $variation->combination->get( $key ) ) ] = $variation;
		}

		$planned = [];
		foreach ( $entries as $entry ) {
			$line     = $entry['line'];
			$warnings = $entry['warnings'];
			try {
				$full  = [];
				$pairs = $entry['pairs'];
				foreach ( $axes as $key ) {
					$full[ $key ] = $pairs[ $key ] ?? Combination::WILDCARD;
				}
				$match = $stored[ self::hash( $axes, static fn ( string $key ): ?string => $pairs[ $key ] ?? null ) ] ?? null;
				if ( $match === null && $entry['sku'] !== null && $product !== null ) {
					foreach ( $product->variations as $variation ) {
						if ( $variation->sku !== null && mb_strtolower( $variation->sku ) === mb_strtolower( $entry['sku'] ) ) {
							$match = $variation;
						}
					}
				}
				if ( $match !== null && ! $this->update && ! $this->created_now( $parent_id ) ) {
					$results[ $line ] = self::result( $line, 'skip', $parent_id, $entry['sku'], $entry['name'], __( 'This variation already exists.', 'flexacommerce' ) );
					continue;
				}
				$fields = $this->row_fields( $entry['record'], $match, true, $warnings );
				self::check_prices( $fields, 'variation' );
				ProductInput::fields( $fields, 'variation' );
				$planned[] = [
					'line'     => $line,
					'sku'      => $entry['sku'],
					'name'     => $entry['name'],
					'match'    => $match,
					'full'     => $full,
					'fields'   => $fields,
					'warnings' => $warnings,
				];
			} catch ( CommerceException | \InvalidArgumentException $e ) {
				$results[ $line ] = self::result( $line, 'error', null, $entry['sku'], $entry['name'], self::message( $e ), $warnings );
			}
		}

		if ( $this->dry_run || $product === null ) {
			foreach ( $planned as $plan ) {
				$results[ $plan['line'] ] = self::result( $plan['line'], $plan['match'] !== null ? 'update' : 'create', $parent_id > 0 ? $parent_id : null, $plan['sku'], $plan['name'], '', $plan['warnings'] );
			}
			ksort( $results );
			return array_values( $results );
		}

		while ( $planned !== [] ) {
			$list    = [];
			$index   = [];
			$claimed = [];
			foreach ( $product->variations as $variation ) {
				$list[ 'id' . $variation->id ] = [ 'id' => $variation->id ];
			}
			foreach ( $planned as $k => $plan ) {
				if ( $plan['match'] !== null && isset( $claimed[ $plan['match']->id ] ) ) {
					$results[ $plan['line'] ] = self::result( $plan['line'], 'error', null, $plan['sku'], $plan['name'], __( 'Two variations have the same attribute values.', 'flexacommerce' ), $plan['warnings'] );
					unset( $planned[ $k ] );
					continue;
				}
				if ( $plan['match'] !== null ) {
					$claimed[ $plan['match']->id ] = true;
					$entry                         = [ 'id' => $plan['match']->id ] + $plan['fields'];
					if ( self::hash( $axes, static fn ( string $key ): ?string => $plan['match']->combination->get( $key ) ) !== self::hash( $axes, static fn ( string $key ): string => $plan['full'][ $key ] ) ) {
						$entry['attributes'] = $plan['full'];
					}
					$list[ 'id' . $plan['match']->id ] = $entry;
				} else {
					$list[ 'new' . $k ] = [ 'attributes' => $plan['full'] ] + $plan['fields'];
				}
			}
			$position = 0;
			foreach ( $list as $slot => $unused ) {
				foreach ( $planned as $k => $plan ) {
					if ( $slot === 'new' . $k || ( $plan['match'] !== null && $slot === 'id' . $plan['match']->id ) ) {
						$index[ $position ] = $k;
					}
				}
				++$position;
			}

			try {
				$this->service->save(
					$product->id,
					ProductInput::from_array(
						[
							'attributes' => array_values( $definitions ),
							'variations' => array_values( $list ),
						]
					)
				);
				foreach ( $planned as $plan ) {
					$results[ $plan['line'] ] = self::result( $plan['line'], $plan['match'] !== null ? 'update' : 'create', $product->id, $plan['sku'], $plan['name'], '', $plan['warnings'] );
				}
				break;
			} catch ( CommerceException $e ) {
				$failed = null;
				if ( $e instanceof InvalidProduct && preg_match( '/^variations\.(\d+)/', $e->field, $m ) === 1 ) {
					$failed = $index[ (int) $m[1] ] ?? null;
				} elseif ( $e instanceof DuplicateSku ) {
					foreach ( $planned as $k => $plan ) {
						if ( $plan['sku'] !== null && mb_strtolower( $plan['sku'] ) === mb_strtolower( $e->sku ) ) {
							$failed = $k;
						}
					}
				}
				if ( $failed === null ) {
					foreach ( $planned as $plan ) {
						$results[ $plan['line'] ] = self::result( $plan['line'], 'error', null, $plan['sku'], $plan['name'], self::message( $e ), $plan['warnings'] );
					}
					break;
				}
				$plan                     = $planned[ $failed ];
				$results[ $plan['line'] ] = self::result( $plan['line'], 'error', null, $plan['sku'], $plan['name'], self::message( $e ), $plan['warnings'] );
				unset( $planned[ $failed ] );
			}
		}
		ksort( $results );
		return array_values( $results );
	}

	/** True when this import created the product, so its variations are new as well. */
	private function created_now( int $product_id ): bool {
		return in_array( $product_id, $this->context->ids, true ) || in_array( $product_id, $this->context->skus, true );
	}

	/**
	 * The product a row updates: by SKU first (it travels between stores),
	 * then by ID. 0 when there is none.
	 *
	 * @throws InvalidProduct when the SKU belongs to a variation
	 */
	private function find( ?string $sku, string $file_id ): int {
		if ( $sku !== null ) {
			$known = $this->context->skus[ mb_strtolower( $sku ) ] ?? null;
			if ( $known !== null && $known !== 0 ) {
				return $known;
			}
			$row = $this->variants->find_by_sku( $sku );
			if ( $row !== null && ! $row->is_base ) {
				/* translators: %s: SKU */
				throw new InvalidProduct( sprintf( __( 'The SKU %s belongs to a variation.', 'flexacommerce' ), $sku ), 'sku' );
			}
			if ( $row !== null ) {
				return $row->product_id;
			}
		}
		if ( $this->update && ctype_digit( $file_id ) && get_post_type( (int) $file_id ) === PostTypes::PRODUCT ) {
			return (int) $file_id;
		}
		return 0;
	}

	/**
	 * The product a Parent cell names: `id:N` (an ID of the file), a SKU, or
	 * a product ID. 0 when the parent row was skipped, null when unknown.
	 */
	private function parent_of( string $reference ): ?int {
		$id = str_starts_with( strtolower( $reference ), 'id:' ) ? trim( substr( $reference, 3 ) ) : null;
		if ( $id === null ) {
			if ( isset( $this->context->skus[ mb_strtolower( $reference ) ] ) ) {
				return $this->context->skus[ mb_strtolower( $reference ) ];
			}
			$row = $this->variants->find_by_sku( $reference );
			if ( $row !== null && $row->is_base ) {
				return $row->product_id;
			}
			if ( ! ctype_digit( $reference ) ) {
				return null;
			}
			$id = $reference;
		}
		if ( isset( $this->context->ids[ $id ] ) ) {
			return $this->context->ids[ $id ];
		}
		return $this->update && ctype_digit( $id ) && get_post_type( (int) $id ) === PostTypes::PRODUCT ? (int) $id : null;
	}

	/** @throws InvalidProduct when an earlier line of the file used the SKU */
	private function claim_sku( ?string $sku, int $line ): void {
		if ( $sku === null ) {
			return;
		}
		$key   = mb_strtolower( $sku );
		$first = $this->context->lines[ $key ] ?? null;
		if ( $first !== null && $first !== $line ) {
			/* translators: 1: SKU, 2: line number */
			throw new InvalidProduct( sprintf( __( 'The SKU %1$s is also on line %2$d.', 'flexacommerce' ), $sku, $first ), 'sku' );
		}
		$this->context->lines[ $key ] = $line;
	}

	/**
	 * Fields of a base or variation row, in the shape ProductInput::fields()
	 * reads. Only mapped columns are included.
	 *
	 * @param array<string,string> $record
	 * @param list<string>         $warnings
	 * @return array<string,mixed>
	 */
	private function row_fields( array $record, ?Variant $before, bool $variation, array &$warnings ): array {
		$fields = [];
		if ( array_key_exists( 'sku', $record ) ) {
			$fields['sku'] = trim( $record['sku'] );
		}
		foreach ( [ 'regular_price', 'sale_price' ] as $key ) {
			if ( array_key_exists( $key, $record ) ) {
				$fields[ $key ] = self::minor( $record[ $key ], $key );
			}
		}
		if ( array_key_exists( 'date_on_sale_from', $record ) ) {
			$fields['sale_from'] = self::utc( $record['date_on_sale_from'], false, 'date_on_sale_from' );
		}
		if ( array_key_exists( 'date_on_sale_to', $record ) ) {
			$fields['sale_to'] = self::utc( $record['date_on_sale_to'], true, 'date_on_sale_to' );
		}
		if ( trim( $record['tax_status'] ?? '' ) !== '' ) {
			$fields['tax_status'] = strtolower( trim( $record['tax_status'] ) );
		}
		if ( array_key_exists( 'tax_class', $record ) ) {
			$class               = trim( $record['tax_class'] );
			$fields['tax_class'] = strtolower( $class ) === 'parent' ? Variant::PARENT_CLASS : $class;
		}
		$stock_status = strtolower( trim( $record['stock_status'] ?? '' ) );
		if ( $stock_status !== '' ) {
			$fields['stock_status'] = self::STOCK[ $stock_status ] ?? throw new InvalidProduct( __( 'In stock? must be 1, 0 or backorder.', 'flexacommerce' ), 'stock_status' );
		}
		if ( array_key_exists( 'stock_quantity', $record ) ) {
			$stock                     = trim( $record['stock_quantity'] );
			$from_base                 = $variation && strtolower( $stock ) === 'parent';
			$fields['stock_from_base'] = $from_base;
			$fields['manage_stock']    = $stock !== '' && ! $from_base;
			$fields['stock_quantity']  = $stock === '' || $from_base ? null : $stock;
		}
		if ( array_key_exists( 'low_stock_amount', $record ) ) {
			$fields['low_stock_threshold'] = trim( $record['low_stock_amount'] );
		}
		$backorders = strtolower( trim( $record['backorders'] ?? '' ) );
		if ( $backorders !== '' ) {
			$fields['backorders'] = self::BACKORDERS[ $backorders ] ?? throw new InvalidProduct( __( 'Backorders allowed? must be 1, 0 or notify.', 'flexacommerce' ), 'backorders' );
		}
		foreach ( [ 'weight', 'length', 'width', 'height' ] as $key ) {
			if ( array_key_exists( $key, $record ) ) {
				$fields[ $key ] = self::measure( $record[ $key ], $this->factors[ $key ] ?? 1.0, $key );
			}
		}
		$types = self::types( $record );
		if ( $types !== [] ) {
			$fields['virtual']      = in_array( 'virtual', $types, true );
			$fields['downloadable'] = in_array( 'downloadable', $types, true );
		}
		$downloads = self::downloads( $record, $before?->downloads );
		if ( $downloads !== null ) {
			$fields['downloads'] = $downloads;
		}
		if ( ! $variation ) {
			return $fields;
		}

		if ( trim( $record['published'] ?? '' ) !== '' ) {
			$fields['enabled'] = trim( $record['published'] ) !== '0';
		}
		if ( array_key_exists( 'description', $record ) ) {
			$fields['description'] = self::text( $record['description'] );
		}
		if ( trim( $record['menu_order'] ?? '' ) !== '' ) {
			$fields['menu_order'] = (int) $record['menu_order'];
		}
		$images = $this->images( array_slice( CsvValues::split( $record['images'] ?? '' ), 0, 1 ), $warnings );
		if ( $images !== null && $images[0] > 0 ) {
			$fields['image_id'] = $images[0];
		}
		return $fields;
	}

	/**
	 * The downloads payload, or null when the row has no download cells.
	 * Without file cells an update keeps the stored files.
	 *
	 * @param array<string,string> $record
	 * @return array{files:list<array{id:string,name:string,file:string}>,limit:?string,expiry_days:?string}|null
	 */
	private static function downloads( array $record, ?Downloads $before ): ?array {
		$files = [];
		foreach ( $record as $key => $value ) {
			if ( preg_match( '/^download:(\d+):url$/', $key, $m ) !== 1 || trim( $value ) === '' ) {
				continue;
			}
			$files[] = [
				'id'   => trim( $record[ 'download:' . $m[1] . ':id' ] ?? '' ),
				'name' => trim( $record[ 'download:' . $m[1] . ':name' ] ?? '' ),
				'file' => trim( $value ),
			];
		}
		$limits = array_key_exists( 'download_limit', $record ) || array_key_exists( 'download_expiry', $record );
		if ( $files === [] && ! $limits ) {
			return null;
		}
		$limit = static function ( string $key, ?int $stored ) use ( $record ): ?string {
			if ( ! array_key_exists( $key, $record ) ) {
				return $stored !== null ? (string) $stored : null;
			}
			$value = trim( $record[ $key ] );
			return $value === '' || $value === '-1' ? null : $value;
		};
		return [
			'files'       => $files !== [] ? $files : ( $before !== null ? $before->files : [] ),
			'limit'       => $limit( 'download_limit', $before?->limit ),
			'expiry_days' => $limit( 'download_expiry', $before?->expiry_days ),
		];
	}

	/**
	 * Raw attribute definitions from the numbered columns, or null when the
	 * row names none. A stored attribute keeps its "used for variations"
	 * flag; a Default value marks one.
	 *
	 * @param array<string,string> $record
	 * @param list<string>         $warnings
	 * @return list<array<string,mixed>>|null
	 */
	private function attribute_rows( array $record, ?Product $before, array &$warnings ): ?array {
		$flags = [];
		foreach ( $before !== null ? $before->attributes : [] as $definition ) {
			$flags[ $definition->key ] = $definition->variation;
		}
		$out = [];
		foreach ( self::numbers( $record ) as $n ) {
			$name    = trim( $record[ 'attribute:' . $n . ':name' ] ?? '' );
			$values  = CsvValues::split( $record[ 'attribute:' . $n . ':value' ] ?? '' );
			$visible = trim( $record[ 'attribute:' . $n . ':visible' ] ?? '' );
			$visible = $visible === '' || CsvValues::bool( $visible );
			$default = trim( $record[ 'attribute:' . $n . ':default' ] ?? '' ) !== '';
			if ( CsvValues::bool( $record[ 'attribute:' . $n . ':global' ] ?? '' ) ) {
				$attribute = $this->global_attribute( $name, $warnings );
				$ids       = [];
				foreach ( $values as $value ) {
					$id = $attribute > 0 ? $this->global_value( $attribute, $name, $value, $warnings ) : 0;
					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
				if ( $attribute === 0 ) {
					continue;
				}
				$key   = Combination::global_key( $attribute );
				$out[] = [
					'type'              => AttributeDefinition::GLOBAL,
					'attribute_term_id' => $attribute,
					'value_term_ids'    => $ids,
					'visible'           => $visible,
					'variation'         => ( $flags[ $key ] ?? false ) || $default,
				];
				continue;
			}
			$out[] = [
				'name'      => $name,
				'values'    => $values,
				'visible'   => $visible,
				'variation' => ( $flags[ Combination::local_key( $name ) ] ?? false ) || $default,
			];
		}
		return $out === [] ? null : $out;
	}

	/**
	 * The combination of a variation row, key => value key ('*' for "any").
	 * Adds the attributes and values the row names to `$definitions` and
	 * marks each as used for variations.
	 *
	 * @param array<string,string>               $record
	 * @param array<string,array<string,mixed>> $definitions
	 * @param list<string>                       $warnings
	 * @return array<string,string>
	 */
	private function combination( array $record, array &$definitions, array &$warnings ): array {
		$pairs = [];
		foreach ( self::numbers( $record ) as $n ) {
			$name   = trim( $record[ 'attribute:' . $n . ':name' ] ?? '' );
			$value  = trim( str_replace( '\\,', ',', $record[ 'attribute:' . $n . ':value' ] ?? '' ) );
			$global = CsvValues::bool( $record[ 'attribute:' . $n . ':global' ] ?? '' );
			$key    = $this->definition_key( $definitions, $name );
			if ( $key === null && $global ) {
				$attribute = $this->global_attribute( $name, $warnings );
				if ( $attribute === 0 ) {
					continue;
				}
				$key                 = Combination::global_key( $attribute );
				$definitions[ $key ] = [
					'type'              => AttributeDefinition::GLOBAL,
					'attribute_term_id' => $attribute,
					'value_term_ids'    => [],
					'visible'           => true,
					'variation'         => true,
				];
			} elseif ( $key === null ) {
				$key                 = Combination::local_key( $name );
				$definitions[ $key ] = [
					'name'      => $name,
					'values'    => [],
					'visible'   => true,
					'variation' => true,
				];
			}
			$definitions[ $key ]['variation'] = true;
			if ( $value === '' ) {
				$pairs[ $key ] = Combination::WILDCARD;
				continue;
			}
			if ( ( $definitions[ $key ]['type'] ?? '' ) === AttributeDefinition::GLOBAL ) {
				$id = $this->global_value( (int) $definitions[ $key ]['attribute_term_id'], $name, $value, $warnings );
				if ( $id === 0 ) {
					continue;
				}
				$ids = is_array( $definitions[ $key ]['value_term_ids'] ) ? $definitions[ $key ]['value_term_ids'] : [];
				if ( ! in_array( $id, $ids, true ) ) {
					$definitions[ $key ]['value_term_ids'] = [ ...$ids, $id ];
				}
				$pairs[ $key ] = (string) $id;
				continue;
			}
			$values = is_array( $definitions[ $key ]['values'] ) ? $definitions[ $key ]['values'] : [];
			$slug   = Combination::local_value( $value );
			$known  = array_map( static fn ( mixed $v ): string => Combination::local_value( (string) $v ), $values );
			if ( ! in_array( $slug, $known, true ) ) {
				$definitions[ $key ]['values'] = [ ...$values, $value ];
			}
			$pairs[ $key ] = $slug;
		}
		return $pairs;
	}

	/**
	 * The key of the definition whose label is `$name`, compared without case.
	 *
	 * @param array<string,array<string,mixed>> $definitions
	 */
	private function definition_key( array $definitions, string $name ): ?string {
		$lower = mb_strtolower( $name );
		foreach ( $definitions as $key => $definition ) {
			$label = ( $definition['type'] ?? '' ) === AttributeDefinition::GLOBAL
				? $this->attributes->name( (int) $definition['attribute_term_id'] )
				: (string) ( $definition['name'] ?? '' );
			if ( mb_strtolower( $label ) === $lower || $key === Combination::local_key( $name ) ) {
				return $key;
			}
		}
		return null;
	}

	/** @return array<string,mixed> the shape ProductService::save() reads */
	private static function raw( AttributeDefinition $definition ): array {
		if ( $definition->is_global() ) {
			return [
				'type'              => AttributeDefinition::GLOBAL,
				'attribute_term_id' => $definition->attribute_term_id,
				'value_term_ids'    => $definition->value_term_ids,
				'visible'           => $definition->visible,
				'variation'         => $definition->variation,
			];
		}
		return [
			'name'      => $definition->name,
			'values'    => $definition->values,
			'visible'   => $definition->visible,
			'variation' => $definition->variation,
		];
	}

	/**
	 * A global attribute found by name or slug, created when missing. In a
	 * check a missing one is noted and 0 returned.
	 *
	 * @param list<string> $warnings
	 */
	private function global_attribute( string $name, array &$warnings ): int {
		$names = $this->global_names();
		$found = $names[ mb_strtolower( $name ) ] ?? $names[ sanitize_title( $name ) ] ?? null;
		if ( $found !== null ) {
			return $found;
		}
		if ( $this->dry_run ) {
			if ( $this->context->note( 'attribute:' . mb_strtolower( $name ) ) ) {
				/* translators: %s: attribute name */
				$warnings[] = sprintf( __( 'The attribute %s will be created.', 'flexacommerce' ), $name );
			}
			return 0;
		}
		$id = $this->attributes->create_attribute( $name );
		$this->global_names[ mb_strtolower( $name ) ]  = $id;
		$this->global_names[ sanitize_title( $name ) ] = $id;
		$this->global_values[ $id ]                    = [];
		return $id;
	}

	/**
	 * A value of a global attribute found by name or slug, created when
	 * missing. 0 in a check when it would be created.
	 *
	 * @param list<string> $warnings
	 */
	private function global_value( int $attribute, string $attribute_name, string $value, array &$warnings ): int {
		$this->global_names();
		$values = $this->global_values[ $attribute ] ?? [];
		$found  = $values[ mb_strtolower( $value ) ] ?? $values[ sanitize_title( $value ) ] ?? null;
		if ( $found !== null ) {
			return $found;
		}
		if ( $this->dry_run ) {
			if ( $this->context->note( 'value:' . $attribute . ':' . mb_strtolower( $value ) ) ) {
				/* translators: 1: attribute value, 2: attribute name */
				$warnings[] = sprintf( __( 'The value %1$s of %2$s will be created.', 'flexacommerce' ), $value, $attribute_name );
			}
			return 0;
		}
		$id = $this->attributes->create_value( $attribute, $value );
		$this->global_values[ $attribute ][ mb_strtolower( $value ) ]  = $id;
		$this->global_values[ $attribute ][ sanitize_title( $value ) ] = $id;
		return $id;
	}

	/** @return array<string,int> lower-case name or slug => attribute id */
	private function global_names(): array {
		if ( $this->global_names === null ) {
			$this->global_names = [];
			foreach ( $this->attributes->all() as $attribute ) {
				$this->global_names[ mb_strtolower( $attribute['name'] ) ] ??= $attribute['id'];
				$this->global_names[ $attribute['slug'] ]                  ??= $attribute['id'];
				$values = [];
				foreach ( $attribute['values'] as $value ) {
					$values[ mb_strtolower( $value['name'] ) ] ??= $value['id'];
					$values[ $value['slug'] ]                  ??= $value['id'];
				}
				$this->global_values[ $attribute['id'] ] = $values;
			}
		}
		return $this->global_names;
	}

	/**
	 * Attachment ids of image cells, null when there are none. A cell that
	 * fails adds a warning and is left out; in a check a URL to download is -1.
	 *
	 * @param list<string> $references
	 * @param list<string> $warnings
	 * @return list<int>|null
	 */
	private function images( array $references, array &$warnings ): ?array {
		if ( $references === [] ) {
			return null;
		}
		$ids = [];
		foreach ( $references as $reference ) {
			$id = $this->media->resolve( $reference, $this->dry_run );
			if ( is_string( $id ) ) {
				$warnings[] = $id;
				continue;
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Category ids from "Parent > Child" paths, creating missing ones.
	 *
	 * @param list<string> $warnings
	 * @return list<int>
	 */
	private function categories( string $cell, array &$warnings ): array {
		$ids = [];
		foreach ( CsvValues::split( $cell ) as $path ) {
			$parent = 0;
			foreach ( array_filter( array_map( 'trim', explode( '>', $path ) ), static fn ( string $part ): bool => $part !== '' ) as $name ) {
				$parent = $this->term( $name, PostTypes::CATEGORY, $parent, $warnings, $path );
				if ( $parent === 0 ) {
					break;
				}
			}
			if ( $parent > 0 ) {
				$ids[] = $parent;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param list<string> $warnings
	 * @return list<int>
	 */
	private function tags( string $cell, array &$warnings ): array {
		$ids = [];
		foreach ( CsvValues::split( $cell ) as $name ) {
			$id = $this->term( $name, PostTypes::TAG, 0, $warnings, $name );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** @param list<string> $warnings */
	private function term( string $name, string $taxonomy, int $parent, array &$warnings, string $label ): int {
		$found = term_exists( $name, $taxonomy, $parent );
		if ( is_array( $found ) ) {
			return (int) $found['term_id'];
		}
		if ( $this->dry_run ) {
			if ( $this->context->note( $taxonomy . ':' . mb_strtolower( $label ) ) ) {
				$warnings[] = $taxonomy === PostTypes::CATEGORY
					/* translators: %s: category path */
					? sprintf( __( 'The category %s will be created.', 'flexacommerce' ), $label )
					/* translators: %s: tag name */
					: sprintf( __( 'The tag %s will be created.', 'flexacommerce' ), $label );
			}
			return 0;
		}
		$created = wp_insert_term( $name, $taxonomy, [ 'parent' => $parent ] );
		if ( $created instanceof \WP_Error ) {
			$warnings[] = $created->get_error_message();
			return 0;
		}
		return (int) $created['term_id'];
	}

	/** @param array<string,string> $record */
	private function write_meta( int $product_id, array $record ): void {
		foreach ( $record as $key => $value ) {
			if ( ! str_starts_with( $key, 'meta:' ) ) {
				continue;
			}
			$meta = substr( $key, 5 );
			if ( $meta === '' || strlen( $meta ) > 255 ) {
				continue;
			}
			foreach ( self::PROTECTED_META as $prefix ) {
				if ( str_starts_with( $meta, $prefix ) ) {
					continue 2;
				}
			}
			if ( $value === '' ) {
				delete_post_meta( $product_id, $meta );
			} else {
				update_post_meta( $product_id, $meta, wp_slash( $value ) );
			}
		}
	}

	/**
	 * @param array<string,string> $record
	 * @return list<string>
	 */
	private static function types( array $record ): array {
		return array_map( 'strtolower', CsvValues::split( $record['type'] ?? '' ) );
	}

	/** @param array<string,string> $record */
	private static function is_variation( array $record ): bool {
		$types = self::types( $record );
		return in_array( 'variation', $types, true ) || ( $types === [] && trim( $record['parent_id'] ?? '' ) !== '' );
	}

	/**
	 * Numbers of the attribute columns that name an attribute.
	 *
	 * @param array<string,string> $record
	 * @return list<int>
	 */
	private static function numbers( array $record ): array {
		$numbers = [];
		foreach ( $record as $key => $value ) {
			if ( preg_match( '/^attribute:(\d+):name$/', $key, $m ) === 1 && trim( $value ) !== '' ) {
				$numbers[] = (int) $m[1];
			}
		}
		sort( $numbers );
		return $numbers;
	}

	/**
	 * A key for the values of the axes, '*' standing for a missing one.
	 *
	 * @param list<string>                  $axes
	 * @param callable(string): ?string $value
	 */
	private static function hash( array $axes, callable $value ): string {
		$parts = [];
		foreach ( $axes as $key ) {
			$parts[] = $key . '=' . ( $value( $key ) ?? Combination::WILDCARD );
		}
		return implode( '&', $parts );
	}

	/** @param array<string,string> $record */
	private static function cell_sku( array $record ): ?string {
		$sku = trim( $record['sku'] ?? '' );
		return $sku === '' ? null : $sku;
	}

	/** Minor units from a price cell, null when empty. */
	private static function minor( string $value, string $column ): ?int {
		try {
			$decimal = CsvValues::decimal( $value );
		} catch ( \InvalidArgumentException ) {
			/* translators: %s: column name */
			throw new InvalidProduct( sprintf( __( '%s must be a number.', 'flexacommerce' ), CsvColumns::header( $column ) ), $column );
		}
		if ( $decimal === null ) {
			return null;
		}
		$currency = Settings::string( 'currency' );
		return Money::from_decimal_string( $decimal, $currency, Currency::exponent( $currency ) )->amount;
	}

	/** Grams or millimetres, three decimals at most, from a cell in the file unit. */
	private static function measure( string $value, float $factor, string $column ): ?string {
		try {
			$decimal = CsvValues::decimal( $value );
		} catch ( \InvalidArgumentException ) {
			/* translators: %s: column name */
			throw new InvalidProduct( sprintf( __( '%s must be a number.', 'flexacommerce' ), CsvColumns::header( $column ) ), $column );
		}
		return $decimal === null ? null : CsvValues::number( round( (float) $decimal * $factor, 3 ), 3 );
	}

	/**
	 * UTC `Y-m-d H:i:s` from a date in the site timezone or a Unix
	 * timestamp. A date without a time ends the day when it ends a sale.
	 */
	private static function utc( string $value, bool $end, string $column ): ?string {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		$utc = new \DateTimeZone( 'UTC' );
		if ( ctype_digit( $value ) ) {
			return ( new \DateTimeImmutable( '@' . $value ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}
		$value = str_replace( 'T', ' ', $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) === 1 ) {
			$value .= $end ? ' 23:59:59' : ' 00:00:00';
		} elseif ( preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m ) === 1 ) {
			// WooCommerce writes the hour without a leading zero ("2026-01-01 0:00:00").
			$value = sprintf( '%s %02d:%s:%s', $m[1], (int) $m[2], $m[3], $m[4] ?? '00' );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
		if ( $date === false || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			/* translators: %s: column name */
			throw new InvalidProduct( sprintf( __( '%s must be a date like 2026-12-31 or 2026-12-31 18:00.', 'flexacommerce' ), CsvColumns::header( $column ) ), $column );
		}
		return $date->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	}

	/** Line breaks back from the two characters `\n`. */
	private static function text( string $value ): string {
		return strtr(
			$value,
			[
				'\\\\n' => '\\n',
				'\\n'   => "\n",
			]
		);
	}

	/**
	 * The service checks this on save; a check has to do it itself.
	 *
	 * @param array<string,mixed> $fields
	 */
	private static function check_prices( array $fields, string $path ): void {
		$sale    = $fields['sale_price'] ?? null;
		$regular = $fields['regular_price'] ?? null;
		if ( is_int( $sale ) && is_int( $regular ) && $sale >= $regular ) {
			throw new InvalidProduct( __( 'The sale price must be lower than the regular price.', 'flexacommerce' ), $path . '.sale_price' );
		}
	}

	private static function message( \Throwable $e ): string {
		return $e->getMessage();
	}

	/**
	 * @param list<string> $warnings
	 * @return Result
	 */
	private static function result( int $line, string $action, ?int $id, ?string $sku, string $name, string $message = '', array $warnings = [] ): array {
		return [
			'line'     => $line,
			'action'   => $action,
			'id'       => $id,
			'sku'      => $sku,
			'name'     => $name,
			'message'  => $message,
			'warnings' => array_values( array_unique( $warnings ) ),
		];
	}
}
