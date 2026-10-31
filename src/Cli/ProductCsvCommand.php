<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Catalog\Csv\CsvColumns;
use Flexa\Commerce\Catalog\Csv\CsvValues;
use Flexa\Commerce\Catalog\Csv\ImportContext;
use Flexa\Commerce\Catalog\Csv\ProductCsvExporter;
use Flexa\Commerce\Catalog\Csv\ProductCsvImporter;
use Flexa\Commerce\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 *   wp flexacommerce products export [<file>] [--meta]
 *   wp flexacommerce products import <file> [--update] [--dry-run]
 *
 * The same exporter and importer as the Products screen (ADR-059), in the
 * WooCommerce column format.
 */
final class ProductCsvCommand {
	private const BATCH = 50;

	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		$instance = new self();
		WP_CLI::add_command( 'flexacommerce products export', [ $instance, 'export' ] );
		WP_CLI::add_command( 'flexacommerce products import', [ $instance, 'import' ] );
	}

	/**
	 * Writes every product to a CSV file in the WooCommerce format.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Where to write. Without it the CSV goes to standard output.
	 *
	 * [--meta]
	 * : Add a column for each public custom field.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flexacommerce products export products.csv
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function export( array $args, array $assoc_args ): void {
		$exporter = Plugin::instance()->catalog()->csv_exporter();
		$layout   = $exporter->layout( isset( $assoc_args['meta'] ) );
		$path     = $args[0] ?? 'php://stdout';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streams a file of any size.
		$handle = fopen( $path, 'wb' );
		if ( $handle === false ) {
			WP_CLI::error( sprintf( 'Cannot write %s.', $path ) );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, "\xEF\xBB\xBF" . ProductCsvExporter::header( $layout ) );
		$rows = 0;
		for ( $page = 1, $pages = 1; $page <= $pages; $page++ ) {
			$chunk = $exporter->page( $page, $layout );
			fwrite( $handle, $chunk['lines'] );
			$rows += $chunk['rows'];
			$pages = $chunk['pages'];
		}
		// phpcs:enable
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
		if ( isset( $args[0] ) ) {
			WP_CLI::success( sprintf( 'Wrote %d rows to %s.', $rows, $path ) );
		}
	}

	/**
	 * Imports products from a CSV file in the WooCommerce format.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The CSV file. Commas or semicolons; UTF-8 or Windows-1252.
	 *
	 * [--update]
	 * : Update a product whose SKU (or ID) matches a row. Without it such rows are skipped.
	 *
	 * [--dry-run]
	 * : Check the file and report what would happen, without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flexacommerce products import wc-product-export.csv --dry-run
	 *     wp flexacommerce products import products.csv --update
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$path = $args[0] ?? '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads a file of any size line by line.
		$handle = is_readable( $path ) ? fopen( $path, 'rb' ) : false;
		if ( $handle === false ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $path ) );
		}
		$first     = (string) fgets( $handle );
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		rewind( $handle );

		$headers = self::cells( fgetcsv( $handle, null, $delimiter, '"', '' ) );
		if ( $headers === [] ) {
			WP_CLI::error( 'The file has no header row.' );
		}
		$headers     = array_map( [ CsvValues::class, 'strip_bom' ], $headers );
		$mapping     = [];
		$unsupported = [];
		foreach ( $headers as $header ) {
			$key       = CsvColumns::match( $header ) ?? '';
			$mapping[] = $key;
			if ( $key === '' || str_starts_with( $key, 'unsupported:' ) ) {
				$unsupported[] = $header;
			}
		}
		if ( $unsupported !== [] ) {
			WP_CLI::warning( 'Not imported: ' . implode( ', ', $unsupported ) );
		}
		$type   = array_search( 'type', $mapping, true );
		$parent = array_search( 'parent_id', $mapping, true );

		$importer = Plugin::instance()->catalog()->csv_importer();
		$context  = new ImportContext();
		$update   = isset( $assoc_args['update'] );
		$dry_run  = isset( $assoc_args['dry-run'] );
		$results  = [];
		$batch    = [];
		$line     = 1;
		while ( true ) {
			$cells = fgetcsv( $handle, null, $delimiter, '"', '' );
			if ( $cells === false ) {
				break;
			}
			++$line;
			$cells = self::cells( $cells );
			if ( $cells === [] || implode( '', $cells ) === '' ) {
				continue;
			}
			$variation = ( $type !== false && str_contains( strtolower( $cells[ $type ] ?? '' ), 'variation' ) )
				|| ( $parent !== false && trim( $cells[ $parent ] ?? '' ) !== '' );
			if ( count( $batch ) >= ProductCsvImporter::MAX_ROWS || ( count( $batch ) >= self::BATCH && ! $variation ) ) {
				array_push( $results, ...$importer->import( $headers, $mapping, $batch, $context, $update, $dry_run ) );
				$batch = [];
			}
			$batch[] = [
				'line'  => $line,
				'cells' => $cells,
			];
		}
		if ( $batch !== [] ) {
			array_push( $results, ...$importer->import( $headers, $mapping, $batch, $context, $update, $dry_run ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		$problems = [];
		foreach ( $results as $result ) {
			if ( $result['action'] === 'error' || $result['warnings'] !== [] ) {
				$problems[] = [
					'line'    => $result['line'],
					'action'  => $result['action'],
					'sku'     => (string) $result['sku'],
					'message' => trim( $result['message'] . ' ' . implode( ' ', $result['warnings'] ) ),
				];
			}
		}
		if ( $problems !== [] ) {
			\WP_CLI\Utils\format_items( 'table', $problems, [ 'line', 'action', 'sku', 'message' ] );
		}
		$summary = ProductCsvImporter::summary( $results );
		$text    = sprintf(
			'%s%d created, %d updated, %d skipped, %d failed.',
			$dry_run ? 'Check only: ' : '',
			$summary['created'],
			$summary['updated'],
			$summary['skipped'],
			$summary['failed']
		);
		$summary['failed'] > 0 ? WP_CLI::warning( $text ) : WP_CLI::success( $text );
	}

	/**
	 * Cells as UTF-8 strings; a file in Windows-1252 is converted.
	 *
	 * @param array<int,string|null>|false $cells
	 * @return list<string>
	 */
	private static function cells( array|false $cells ): array {
		if ( $cells === false ) {
			return [];
		}
		$out = [];
		foreach ( $cells as $cell ) {
			$cell  = (string) $cell;
			$out[] = mb_check_encoding( $cell, 'UTF-8' ) ? $cell : (string) mb_convert_encoding( $cell, 'UTF-8', 'Windows-1252' );
		}
		return $out;
	}
}
