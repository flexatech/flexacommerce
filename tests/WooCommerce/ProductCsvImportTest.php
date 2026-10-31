<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Catalog\Csv\CsvColumns;
use Flexa\Commerce\Catalog\Csv\CsvValues;
use Flexa\Commerce\Catalog\Csv\ImportContext;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Plugin;

/**
 * A file from the WooCommerce product exporter imports as it is (ADR-059).
 */
final class ProductCsvImportTest extends \WP_UnitTestCase {
	private GoldenStore $store;

	public function set_up(): void {
		parent::set_up();
		$this->store = GoldenStore::build();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_woocommerce_export_imports(): void {
		[ $headers, $rows ] = $this->woocommerce_csv();
		$mapping            = array_map( static fn ( string $header ): string => CsvColumns::match( $header ) ?? '', $headers );
		self::assertNotContains( '', $mapping, (string) wp_json_encode( array_combine( $headers, $mapping ) ) );

		$batch = [];
		foreach ( $rows as $i => $cells ) {
			$batch[] = [
				'line'  => $i + 2,
				'cells' => $cells,
			];
		}
		$results = Plugin::instance()->catalog()->csv_importer()->import( $headers, $mapping, $batch, new ImportContext(), false, false );
		$by_name = [];
		foreach ( $results as $result ) {
			$by_name[ $result['name'] ] = $result;
		}
		$debug = (string) wp_json_encode( $results );

		self::assertSame( 'error', $by_name['Gift Set']['action'], $debug );
		self::assertSame( 'error', $by_name['Second Tee']['action'], $debug );
		self::assertStringContainsString( 'line', $by_name['Second Tee']['message'] );
		foreach ( [ 'Plain Tee', 'Sale Mug', 'Áo dài Việt Nam', 'Draft Lamp', 'Hoodie', 'Scarf' ] as $name ) {
			self::assertSame( 'create', $by_name[ $name ]['action'] ?? null, $name . ' ' . $debug );
		}

		$tee = $this->product( (int) $by_name['Plain Tee']['id'] );
		self::assertSame( 2000, $tee->base->regular_price );
		self::assertTrue( $tee->base->manage_stock );
		self::assertSame( wc_get_product( $this->store->products['tee'] )->get_stock_quantity(), $tee->base->stock_quantity );
		self::assertSame( [ 'Shirts' ], wp_get_object_terms( $tee->id, PostTypes::CATEGORY, [ 'fields' => 'names' ] ) );
		self::assertSame( [ 'Summer' ], wp_get_object_terms( $tee->id, PostTypes::TAG, [ 'fields' => 'names' ] ) );

		$mug = $this->product( (int) $by_name['Sale Mug']['id'] );
		self::assertSame( [ 5000, 4000 ], [ $mug->base->regular_price, $mug->base->sale_price ] );
		self::assertNotNull( $mug->base->sale_to );

		self::assertSame( 'draft', $this->product( (int) $by_name['Draft Lamp']['id'] )->status );

		$hoodie = $this->product( (int) $by_name['Hoodie']['id'] );
		self::assertTrue( $hoodie->is_variable() );
		self::assertCount( 2, $hoodie->variation_attributes() );
		$skus = [];
		foreach ( $hoodie->variations as $row ) {
			$skus[ (string) $row->sku ] = [ $row->regular_price, $row->combination->wildcard_count() ];
		}
		ksort( $skus );
		// HOOD-B has no size: "any size" stays a wildcard.
		self::assertSame(
			[
				'HOOD-B'  => [ 3200, 1 ],
				'HOOD-RL' => [ 3000, 0 ],
			],
			$skus
		);

		$scarf = $this->product( (int) $by_name['Scarf']['id'] );
		self::assertCount( 2, $scarf->variations );
	}

	/** @return array{list<string>,list<list<string>>} */
	private function woocommerce_csv(): array {
		require_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
		$exporter = new class() extends \WC_Product_CSV_Exporter {
			public function csv(): string {
				$this->set_limit( 1000 );
				$this->prepare_data_to_export();
				return $this->export_column_headers() . $this->get_csv_data();
			}
		};
		$handle   = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		self::assertNotFalse( $handle );
		fwrite( $handle, $exporter->csv() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		$headers = array_map( [ CsvValues::class, 'strip_bom' ], array_map( 'strval', (array) fgetcsv( $handle, null, ',', '"', '' ) ) );
		$rows    = [];
		while ( ( $cells = fgetcsv( $handle, null, ',', '"', '' ) ) !== false ) {
			$rows[] = array_map( 'strval', $cells );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return [ $headers, $rows ];
	}

	private function product( int $id ): Product {
		$product = Plugin::instance()->catalog()->product_service()->get( $id );
		self::assertNotNull( $product );
		return $product;
	}
}
