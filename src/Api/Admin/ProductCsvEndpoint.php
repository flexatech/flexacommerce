<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Catalog\Csv\CsvColumns;
use Flexa\Commerce\Catalog\Csv\ImportContext;
use Flexa\Commerce\Catalog\Csv\ProductCsvExporter;
use Flexa\Commerce\Catalog\Csv\ProductCsvImporter;
use Flexa\Commerce\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Product CSV export and import (ADR-059). The browser reads and writes
 * the file; these routes take and return rows a page or a batch at a time
 * so no request runs long. An import keeps what earlier batches created in
 * a transient named by the screen's session id.
 */
final class ProductCsvEndpoint extends Endpoint {
	private const CONTEXT_TTL = DAY_IN_SECONDS;

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/products/csv/export',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'export' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'page'   => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'meta'   => [
							'type'    => 'boolean',
							'default' => false,
						],
						'layout' => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/products/csv/map',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'map' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'headers' => [
							'type'     => 'array',
							'items'    => [ 'type' => 'string' ],
							'required' => true,
							'maxItems' => 1000,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/products/csv/import',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'import' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'session'         => [
							'type'     => 'string',
							'pattern'  => '^[A-Za-z0-9-]{8,64}$',
							'required' => true,
						],
						'headers'         => [
							'type'     => 'array',
							'items'    => [ 'type' => 'string' ],
							'required' => true,
							'maxItems' => 1000,
						],
						'mapping'         => [
							'type'     => 'array',
							'items'    => [ 'type' => 'string' ],
							'required' => true,
							'maxItems' => 1000,
						],
						'rows'            => [
							'type'     => 'array',
							'required' => true,
							'maxItems' => ProductCsvImporter::MAX_ROWS,
							'items'    => [
								'type'       => 'object',
								'properties' => [
									'line'  => [
										'type'     => 'integer',
										'required' => true,
									],
									'cells' => [
										'type'     => 'array',
										'items'    => [ 'type' => 'string' ],
										'required' => true,
									],
								],
							],
						],
						'update_existing' => [
							'type'    => 'boolean',
							'default' => false,
						],
						'dry_run'         => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);
	}

	/**
	 * Page 1 also returns the layout and the header line; the screen sends
	 * the layout back for the later pages so every page has the same columns.
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		$exporter = Plugin::instance()->catalog()->csv_exporter();
		$page     = (int) $request->get_param( 'page' );
		$layout   = null;
		if ( $page > 1 ) {
			$data   = json_decode( (string) $request->get_param( 'layout' ), true );
			$layout = is_array( $data ) ? ProductCsvExporter::layout_from( $data ) : null;
		}
		$first  = $layout === null;
		$layout = $layout ?? $exporter->layout( (bool) $request->get_param( 'meta' ) );
		$chunk  = $exporter->page( $first ? 1 : $page, $layout );
		$body   = $chunk + [ 'page' => $first ? 1 : $page ];
		if ( $first ) {
			$body['layout'] = $layout;
			$body['header'] = ProductCsvExporter::header( $layout );
			$body['name']   = sanitize_file_name( 'products-' . wp_date( 'Y-m-d' ) . '.csv' );
		}
		return new WP_REST_Response( $body, 200 );
	}

	/** The suggested field for each column, and the fields a column can take. */
	public function map( WP_REST_Request $request ): WP_REST_Response {
		$columns = [];
		foreach ( (array) $request->get_param( 'headers' ) as $header ) {
			$header    = is_string( $header ) ? $header : '';
			$key       = CsvColumns::match( $header ) ?? '';
			$columns[] = [
				'header' => $header,
				'key'    => $key,
				'label'  => $key === '' ? '' : CsvColumns::label( $key ),
			];
		}
		$fields = [];
		foreach ( CsvColumns::choices() as $key => $label ) {
			$fields[] = [
				'key'   => $key,
				'label' => $label,
			];
		}
		return new WP_REST_Response(
			[
				'columns' => $columns,
				'fields'  => $fields,
			],
			200
		);
	}

	public function import( WP_REST_Request $request ): WP_REST_Response {
		$headers = array_values( array_map( 'strval', (array) $request->get_param( 'headers' ) ) );
		$mapping = [];
		foreach ( array_values( (array) $request->get_param( 'mapping' ) ) as $key ) {
			$key       = is_string( $key ) ? $key : '';
			$mapping[] = CsvColumns::is_key( $key ) ? $key : '';
		}
		$rows = [];
		foreach ( (array) $request->get_param( 'rows' ) as $row ) {
			if ( ! is_array( $row ) || ! is_array( $row['cells'] ?? null ) ) {
				continue;
			}
			$rows[] = [
				'line'  => (int) ( $row['line'] ?? 0 ),
				'cells' => array_values( array_map( static fn ( mixed $cell ): string => is_scalar( $cell ) ? (string) $cell : '', $row['cells'] ) ),
			];
		}

		$dry_run   = (bool) $request->get_param( 'dry_run' );
		$transient = 'fxc_csv_import_' . get_current_user_id() . '_' . ( $dry_run ? 'check_' : '' ) . md5( (string) $request->get_param( 'session' ) );
		$stored    = get_transient( $transient );
		$context   = is_array( $stored ) ? ImportContext::from_array( $stored ) : new ImportContext();

		wp_raise_memory_limit( 'admin' );
		$results = Plugin::instance()->catalog()->csv_importer()->import(
			$headers,
			$mapping,
			$rows,
			$context,
			(bool) $request->get_param( 'update_existing' ),
			$dry_run
		);
		set_transient( $transient, $context->to_array(), self::CONTEXT_TTL );

		return new WP_REST_Response(
			[
				'results' => $results,
				'summary' => ProductCsvImporter::summary( $results ),
			],
			200
		);
	}
}
