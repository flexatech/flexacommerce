<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog\Csv;

use Flexa\Commerce\Catalog\AttributeDefinition;
use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\ProductService;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Exceptions\ProductNotFound;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\Units;

defined( 'ABSPATH' ) || exit;

/**
 * Writes products as WooCommerce-format CSV lines (ADR-059), one page at a
 * time. A variable product is its row followed by one row per variation.
 * The layout (how many attribute and download columns, which custom
 * fields) is read once, before the first page, so every page has the same
 * columns.
 *
 * @phpstan-type Layout array{attributes:int,downloads:int,meta:list<string>}
 */
final class ProductCsvExporter {
	public const PER_PAGE = 50;

	private const STATUSES = [
		'publish' => '1',
		'private' => '0',
		'draft'   => '-1',
		'pending' => '2',
	];

	private const STOCK = [
		Variant::INSTOCK     => '1',
		Variant::OUTOFSTOCK  => '0',
		Variant::ONBACKORDER => 'backorder',
	];

	private const BACKORDERS = [
		'yes'    => '1',
		'no'     => '0',
		'notify' => 'notify',
	];

	public function __construct(
		private readonly ProductService $service,
		private readonly ProductRepository $products,
		private readonly AttributeRepository $attributes,
		private readonly ProductCsvRepository $repository,
	) {}

	/** @return Layout */
	public function layout( bool $meta ): array {
		return [
			'attributes' => $this->repository->max_attributes(),
			'downloads'  => $this->repository->max_downloads(),
			'meta'       => $meta ? $this->repository->meta_keys() : [],
		];
	}

	/**
	 * Reads a layout sent back by the export screen.
	 *
	 * @param array<array-key,mixed> $data
	 * @return Layout
	 */
	public static function layout_from( array $data ): array {
		$meta = [];
		foreach ( is_array( $data['meta'] ?? null ) ? $data['meta'] : [] as $key ) {
			if ( is_string( $key ) && $key !== '' && ! str_starts_with( $key, '_' ) && count( $meta ) < ProductCsvRepository::MAX_META ) {
				$meta[] = $key;
			}
		}
		return [
			'attributes' => min( 100, max( 0, (int) ( $data['attributes'] ?? 0 ) ) ),
			'downloads'  => min( 50, max( 0, (int) ( $data['downloads'] ?? 0 ) ) ),
			'meta'       => $meta,
		];
	}

	/**
	 * @param Layout $layout
	 * @return list<string>
	 */
	public static function columns( array $layout ): array {
		$keys = array_keys( CsvColumns::fixed() );
		for ( $n = 1; $n <= $layout['attributes']; $n++ ) {
			array_push( $keys, ...CsvColumns::attribute_keys( $n ) );
		}
		for ( $n = 1; $n <= $layout['downloads']; $n++ ) {
			array_push( $keys, ...CsvColumns::download_keys( $n ) );
		}
		foreach ( $layout['meta'] as $key ) {
			$keys[] = 'meta:' . $key;
		}
		return $keys;
	}

	/** @param Layout $layout */
	public static function header( array $layout ): string {
		return CsvValues::line( array_map( static fn ( string $key ): string => CsvValues::escape( CsvColumns::header( $key ) ), self::columns( $layout ) ) );
	}

	/**
	 * @param Layout $layout
	 * @return array{lines:string,rows:int,total:int,pages:int}
	 */
	public function page( int $page, array $layout, int $per_page = self::PER_PAGE ): array {
		$found   = $this->repository->page( $page, $per_page );
		$columns = self::columns( $layout );
		$this->products->prime( $found['ids'] );
		update_meta_cache( 'post', $found['ids'] );
		update_object_term_cache( $found['ids'], PostTypes::PRODUCT );

		$lines = '';
		$rows  = 0;
		foreach ( $found['ids'] as $id ) {
			try {
				$product = $this->service->get( $id );
			} catch ( ProductNotFound ) {
				continue;
			}
			foreach ( $this->records( $product, $layout ) as $record ) {
				$cells = [];
				foreach ( $columns as $key ) {
					$cells[] = CsvValues::escape( $record[ $key ] ?? '' );
				}
				$lines .= CsvValues::line( $cells );
				++$rows;
			}
		}
		return [
			'lines' => $lines,
			'rows'  => $rows,
			'total' => $found['total'],
			'pages' => (int) max( 1, ceil( $found['total'] / max( 1, $per_page ) ) ),
		];
	}

	/**
	 * The product row, then its variation rows, as key => cell.
	 *
	 * @param Layout $layout
	 * @return list<array<string,string>>
	 */
	public function records( Product $product, array $layout ): array {
		$post = get_post( $product->id );
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		$base = $product->base;
		$type = [ $product->type ];
		if ( ! $product->is_variable() ) {
			$type = array_merge( $type, $base->virtual ? [ 'virtual' ] : [], $base->downloadable ? [ 'downloadable' ] : [] );
		}

		$images = [];
		$thumb  = (int) get_post_thumbnail_id( $post );
		foreach ( array_merge( $thumb > 0 ? [ $thumb ] : [], $product->gallery_ids ) as $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( is_string( $url ) && ! in_array( $url, $images, true ) ) {
				$images[] = $url;
			}
		}

		$row = [
			'id'                 => (string) $product->id,
			'type'               => implode( ', ', $type ),
			'name'               => $post->post_title,
			'published'          => self::STATUSES[ $post->post_status ] ?? '-1',
			'featured'           => CsvValues::flag( $product->featured ),
			'catalog_visibility' => $product->visibility,
			'short_description'  => self::text( $post->post_excerpt ),
			'description'        => self::text( $post->post_content ),
			'reviews_allowed'    => CsvValues::flag( $post->comment_status === 'open' ),
			'category_ids'       => CsvValues::join( $this->category_paths( $product->id ) ),
			'tag_ids'            => CsvValues::join( $this->term_names( $product->id, PostTypes::TAG ) ),
			'images'             => CsvValues::join( $images ),
			'menu_order'         => (string) $post->menu_order,
		] + $this->row_fields( $base, $layout );

		foreach ( $product->attributes as $i => $attribute ) {
			if ( $i >= $layout['attributes'] ) {
				break;
			}
			$n                                     = $i + 1;
			$row[ 'attribute:' . $n . ':name' ]    = $this->attribute_name( $attribute );
			$row[ 'attribute:' . $n . ':value' ]   = CsvValues::join( $this->value_names( $attribute ) );
			$row[ 'attribute:' . $n . ':visible' ] = CsvValues::flag( $attribute->visible );
			$row[ 'attribute:' . $n . ':global' ]  = CsvValues::flag( $attribute->is_global() );
		}
		foreach ( $layout['meta'] as $key ) {
			$value = get_post_meta( $product->id, $key, true );
			if ( is_scalar( $value ) ) {
				$row[ 'meta:' . $key ] = (string) $value;
			}
		}

		$records = [ $row ];
		if ( ! $product->is_variable() ) {
			return $records;
		}
		$parent = $base->sku !== null ? $base->sku : 'id:' . $product->id;
		$axes   = array_values( array_filter( $product->attributes, static fn ( AttributeDefinition $a ): bool => $a->variation ) );
		foreach ( $product->variations as $variation ) {
			$labels = [];
			$record = [
				'type'        => implode( ', ', array_merge( [ 'variation' ], $variation->virtual ? [ 'virtual' ] : [], $variation->downloadable ? [ 'downloadable' ] : [] ) ),
				'published'   => CsvValues::flag( $variation->enabled ),
				'description' => self::text( (string) $variation->description ),
				'parent_id'   => $parent,
				'menu_order'  => (string) $variation->menu_order,
				'images'      => $variation->image_id !== null ? (string) wp_get_attachment_url( $variation->image_id ) : '',
			] + $this->row_fields( $variation, $layout );
			foreach ( $axes as $i => $attribute ) {
				if ( $i >= $layout['attributes'] ) {
					break;
				}
				$n     = $i + 1;
				$value = $this->value_label( $attribute, $variation->combination );
				if ( $value !== '' ) {
					$labels[] = $value;
				}
				$record[ 'attribute:' . $n . ':name' ]   = $this->attribute_name( $attribute );
				$record[ 'attribute:' . $n . ':value' ]  = str_replace( ',', '\\,', $value );
				$record[ 'attribute:' . $n . ':global' ] = CsvValues::flag( $attribute->is_global() );
			}
			$record['name'] = $labels === [] ? $post->post_title : $post->post_title . ' - ' . implode( ', ', $labels );
			$records[]      = $record;
		}
		return $records;
	}

	/**
	 * Cells a base row and a variation row share.
	 *
	 * @param Layout $layout
	 * @return array<string,string>
	 */
	private function row_fields( Variant $row, array $layout ): array {
		$stock = '';
		if ( $row->stock_from_base ) {
			$stock = 'parent';
		} elseif ( $row->manage_stock ) {
			$stock = (string) ( $row->stock_quantity ?? 0 );
		}
		$fields = [
			'sku'               => (string) $row->sku,
			'date_on_sale_from' => self::local_date( $row->sale_from ),
			'date_on_sale_to'   => self::local_date( $row->sale_to ),
			'tax_status'        => $row->tax_status,
			'tax_class'         => $row->tax_class,
			'stock_status'      => self::STOCK[ $row->stock_status ] ?? '1',
			'stock_quantity'    => $stock,
			'low_stock_amount'  => $row->low_stock_threshold !== null ? (string) $row->low_stock_threshold : '',
			'backorders'        => self::BACKORDERS[ $row->backorders ] ?? '0',
			'weight'            => $row->weight !== null ? CsvValues::number( Units::weight( (float) $row->weight ) ) : '',
			'length'            => $row->length !== null ? CsvValues::number( Units::dimension( (float) $row->length ) ) : '',
			'width'             => $row->width !== null ? CsvValues::number( Units::dimension( (float) $row->width ) ) : '',
			'height'            => $row->height !== null ? CsvValues::number( Units::dimension( (float) $row->height ) ) : '',
			'sale_price'        => self::price( $row->sale_price ),
			'regular_price'     => self::price( $row->regular_price ),
			'download_limit'    => $row->downloads->limit !== null ? (string) $row->downloads->limit : '',
			'download_expiry'   => $row->downloads->expiry_days !== null ? (string) $row->downloads->expiry_days : '',
		];
		foreach ( $row->downloads->files as $i => $file ) {
			if ( $i >= $layout['downloads'] ) {
				break;
			}
			$n                                    = $i + 1;
			$fields[ 'download:' . $n . ':id' ]   = $file['id'];
			$fields[ 'download:' . $n . ':name' ] = $file['name'];
			$fields[ 'download:' . $n . ':url' ]  = $file['file'];
		}
		return $fields;
	}

	/** In the store's decimal separator, as WooCommerce writes prices. */
	private static function price( ?int $amount ): string {
		if ( $amount === null ) {
			return '';
		}
		$currency = Settings::string( 'currency' );
		$decimal  = ( new Money( $amount, $currency ) )->to_decimal_string( Currency::exponent( $currency ) );
		return Settings::string( 'decimal_separator' ) === ',' ? str_replace( '.', ',', $decimal ) : $decimal;
	}

	/** A stored UTC time in the site's timezone. */
	private static function local_date( ?string $utc ): string {
		if ( $utc === null ) {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );
		return $date === false ? '' : $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	/** Line breaks as the two characters `\n`, the WooCommerce way. */
	private static function text( string $text ): string {
		return str_replace( [ '\\n', "\r\n", "\n" ], [ '\\\\n', '\\n', '\\n' ], $text );
	}

	/** @return list<string> "Parent > Child" for each category */
	private function category_paths( int $product_id ): array {
		$terms = get_the_terms( $product_id, PostTypes::CATEGORY );
		if ( ! is_array( $terms ) ) {
			return [];
		}
		$paths = [];
		foreach ( $terms as $term ) {
			$names = [ $term->name ];
			$seen  = [ $term->term_id => true ];
			$up    = (int) $term->parent;
			while ( $up > 0 && ! isset( $seen[ $up ] ) ) {
				$parent = get_term( $up, PostTypes::CATEGORY );
				if ( ! $parent instanceof \WP_Term ) {
					break;
				}
				array_unshift( $names, $parent->name );
				$seen[ $up ] = true;
				$up          = (int) $parent->parent;
			}
			$paths[] = implode( ' > ', $names );
		}
		sort( $paths );
		return $paths;
	}

	/** @return list<string> */
	private function term_names( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		return is_array( $terms ) ? array_values( array_map( static fn ( \WP_Term $t ): string => $t->name, $terms ) ) : [];
	}

	private function attribute_name( AttributeDefinition $attribute ): string {
		return $attribute->is_global() ? $this->attributes->name( $attribute->attribute_term_id ) : $attribute->name;
	}

	/** @return list<string> */
	private function value_names( AttributeDefinition $attribute ): array {
		if ( ! $attribute->is_global() ) {
			return $attribute->values;
		}
		$names = [];
		foreach ( $attribute->value_term_ids as $id ) {
			$name = $this->attributes->name( $id );
			if ( $name !== '' ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/** The display value of a variation for one attribute; '' for "any". */
	private function value_label( AttributeDefinition $attribute, Combination $combination ): string {
		$value = $combination->get( $attribute->key );
		if ( $value === null || $value === Combination::WILDCARD ) {
			return '';
		}
		return $attribute->is_global() ? $this->attributes->name( (int) $value ) : (string) $attribute->local_label( $value );
	}
}
