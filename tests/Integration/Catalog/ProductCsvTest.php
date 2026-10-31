<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Catalog;

use Flexa\Commerce\Catalog\Csv\CsvColumns;
use Flexa\Commerce\Catalog\Csv\CsvValues;
use Flexa\Commerce\Catalog\Csv\ImportContext;
use Flexa\Commerce\Catalog\Csv\ProductCsvExporter;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Plugin;

final class ProductCsvTest extends CatalogTestCase {
	/** @var list<array{int,string}> */
	private array $created_terms = [];

	public function tear_down(): void {
		foreach ( $this->created_terms as [ $id, $taxonomy ] ) {
			wp_delete_term( $id, $taxonomy );
		}
		$this->created_terms = [];
		parent::tear_down();
	}

	public function test_export_then_import_with_update_restores_the_fields(): void {
		$simple = $this->product( 'Plain mug' );
		$this->save(
			$simple,
			[
				'base' => [
					'sku'           => $this->sku( 'MUG' ),
					'regular_price' => 1250,
					'sale_price'    => 999,
					'weight'        => '350',
				],
			]
		);
		$variable = $this->variable_product( [ 'S', 'M' ], [ 'base' => [ 'sku' => $this->sku( 'TEE' ) ] ] );
		$tee      = $this->service()->generate_variants( $variable )->product;
		$this->save(
			$variable,
			[
				'variations' => array_map(
					fn ( int $i ): array => [
						'id'            => $tee->variations[ $i ]->id,
						'sku'           => $this->sku( 'TEE-' . $i ),
						'regular_price' => 2000,
					],
					array_keys( $tee->variations )
				),
			]
		);

		[ $headers, $rows ] = $this->export_rows( [ $this->sku( 'MUG' ), $this->sku( 'TEE' ), $this->sku( 'TEE-0' ), $this->sku( 'TEE-1' ) ] );
		self::assertCount( 4, $rows );

		// Change the stored values, then import the exported file back.
		$this->save(
			$simple,
			[
				'base' => [
					'regular_price' => 5000,
					'sale_price'    => null,
				],
			]
		);
		$results = $this->import( $headers, $rows, true );

		self::assertSame( [ 'update', 'update', 'update', 'update' ], array_column( $results, 'action' ), wp_json_encode( $results ) );
		$mug = $this->get( $simple );
		self::assertSame( 1250, $mug->base->regular_price );
		self::assertSame( 999, $mug->base->sale_price );
		self::assertSame( '350.000', $mug->base->weight );
		$tee = $this->get( $variable );
		self::assertCount( 2, $tee->variations );
		foreach ( $tee->variations as $row ) {
			self::assertSame( 2000, $row->regular_price );
		}
	}

	public function test_import_creates_a_variable_product_with_categories(): void {
		$parent   = $this->sku( 'HOOD' );
		$category = 'Clothes ' . $this->run;
		$headers  = [ 'Type', 'SKU', 'Name', 'Published', 'Regular price', 'Categories', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Attribute 1 visible', 'Attribute 1 global' ];
		$rows     = [
			[ 'variable', $parent, 'Hoodie', '1', '', $category . ' > Winter', '', 'Colour', 'Red, Blue', '1', '0' ],
			[ 'variation', $parent . '-R', 'Hoodie - Red', '1', '45.00', '', $parent, 'Colour', 'Red', '', '0' ],
			[ 'variation', $parent . '-B', 'Hoodie - Blue', '0', '47,50', '', $parent, 'Colour', 'Blue', '', '0' ],
		];

		$results = $this->import( $headers, $rows, false );

		self::assertSame( [ 'create', 'create', 'create' ], array_column( $results, 'action' ), wp_json_encode( $results ) );
		$id      = (int) $results[0]['id'];
		$product = $this->get( $id );
		self::assertTrue( $product->is_variable() );
		self::assertSame( 'Hoodie', $product->title );
		$prices = [];
		foreach ( $product->variations as $row ) {
			$prices[ (string) $row->sku ] = [ $row->regular_price, $row->enabled ];
		}
		self::assertSame( [ 4500, true ], $prices[ $parent . '-R' ] );
		self::assertSame( [ 4750, false ], $prices[ $parent . '-B' ] );

		$terms = wp_get_object_terms( $id, PostTypes::CATEGORY );
		self::assertIsArray( $terms );
		self::assertSame( [ 'Winter' ], array_map( static fn ( \WP_Term $term ): string => $term->name, $terms ) );
		self::assertSame( $category, get_term( $terms[0]->parent, PostTypes::CATEGORY )->name ?? '' );
	}

	public function test_dry_run_writes_nothing(): void {
		$sku     = $this->sku( 'DRY' );
		$headers = [ 'Type', 'SKU', 'Name', 'Regular price', 'Tags' ];
		$results = $this->import( $headers, [ [ 'simple', $sku, 'Dry product', '10', 'Tag ' . $this->run ] ], false, true );

		self::assertSame( 'create', $results[0]['action'] );
		self::assertNull( $results[0]['id'] );
		self::assertSame( 0, Plugin::instance()->catalog()->variants()->find_by_sku( $sku )?->id ?? 0 );
		self::assertNull( term_exists( 'Tag ' . $this->run, PostTypes::TAG ) );
	}

	public function test_existing_sku_is_skipped_without_update(): void {
		$id = $this->product( 'Kept' );
		$this->save(
			$id,
			[
				'base' => [
					'sku'           => $this->sku( 'KEEP' ),
					'regular_price' => 100,
				],
			]
		);

		$results = $this->import( [ 'SKU', 'Name', 'Regular price' ], [ [ $this->sku( 'KEEP' ), 'Changed', '99' ] ], false );

		self::assertSame( 'skip', $results[0]['action'] );
		self::assertSame( $id, $results[0]['id'] );
		self::assertSame( 100, $this->get( $id )->base->regular_price );
		self::assertSame( 'Kept', get_the_title( $id ) );
	}

	public function test_duplicate_sku_in_the_file_is_an_error(): void {
		$sku     = $this->sku( 'TWICE' );
		$results = $this->import( [ 'SKU', 'Name' ], [ [ $sku, 'One' ], [ $sku, 'Two' ] ], false );

		self::assertSame( [ 'create', 'error' ], array_column( $results, 'action' ) );
		self::assertStringContainsString( 'line 2', $results[1]['message'] );
	}

	/**
	 * Exports the store and keeps the rows whose SKU is listed.
	 *
	 * @param list<string> $skus
	 * @return array{list<string>,list<list<string>>}
	 */
	private function export_rows( array $skus ): array {
		$exporter = Plugin::instance()->catalog()->csv_exporter();
		$layout   = $exporter->layout( false );
		$headers  = str_getcsv( trim( ProductCsvExporter::header( $layout ) ), ',', '"', '' );
		$sku      = array_search( 'SKU', $headers, true );
		$rows     = [];
		for ( $page = 1, $pages = 1; $page <= $pages; $page++ ) {
			$chunk = $exporter->page( $page, $layout );
			$pages = $chunk['pages'];
			$lines = str_getcsv( $chunk['lines'], "\n", '"', '' );
			foreach ( $lines as $line ) {
				$cells = str_getcsv( (string) $line, ',', '"', '' );
				if ( in_array( $cells[ $sku ] ?? '', $skus, true ) ) {
					$rows[] = array_map( 'strval', $cells );
				}
			}
		}
		return [ array_map( 'strval', $headers ), $rows ];
	}

	/**
	 * @param list<string>       $headers
	 * @param list<list<string>> $rows
	 * @return list<array<string,mixed>>
	 */
	private function import( array $headers, array $rows, bool $update, bool $dry_run = false ): array {
		$mapping = array_map( static fn ( string $header ): string => CsvColumns::match( $header ) ?? '', $headers );
		$batch   = [];
		foreach ( $rows as $i => $cells ) {
			$batch[] = [
				'line'  => $i + 2,
				'cells' => $cells,
			];
		}
		$results = Plugin::instance()->catalog()->csv_importer()->import( $headers, $mapping, $batch, new ImportContext(), $update, $dry_run );
		foreach ( $results as $result ) {
			if ( $result['action'] === 'create' && ! $dry_run && $result['id'] !== null && get_post_type( (int) $result['id'] ) === PostTypes::PRODUCT ) {
				$this->track_post( (int) $result['id'] );
				foreach ( [ PostTypes::CATEGORY, PostTypes::TAG ] as $taxonomy ) {
					foreach ( (array) wp_get_object_terms( (int) $result['id'], $taxonomy ) as $term ) {
						if ( $term instanceof \WP_Term ) {
							$this->created_terms[] = [ $term->term_id, $taxonomy ];
							if ( $term->parent > 0 ) {
								$this->created_terms[] = [ $term->parent, $taxonomy ];
							}
						}
					}
				}
			}
		}
		return $results;
	}

	private function get( int $id ): Product {
		$product = $this->service()->get( $id );
		self::assertNotNull( $product );
		return $product;
	}
}
