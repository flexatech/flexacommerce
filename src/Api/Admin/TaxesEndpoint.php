<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Countries;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Tax\RateRow;
use Flexa\Commerce\Tax\TaxCsv;
use Flexa\Commerce\Tax\TaxService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The Taxes screen (doc 02 §7.12): rate table per class, CSV import in
 * batches and export, and market presets. Tax options and classes are
 * plain settings and go through admin/settings. Every rate write recompiles
 * the store base rates, which catalog prices read.
 */
final class TaxesEndpoint extends Endpoint {
	public function register_routes(): void {
		$routes = [
			'/admin/taxes'                             => [ WP_REST_Server::READABLE, 'overview', [] ],
			'/admin/taxes/rates'                       => [
				WP_REST_Server::READABLE,
				'rates',
				[
					'tax_class' => [
						'type'    => 'string',
						'default' => '',
					],
					'search'    => [
						'type'    => 'string',
						'default' => '',
					],
					'page'      => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'per_page'  => [
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 500,
					],
				],
			],
			'/admin/taxes/import'                      => [ WP_REST_Server::CREATABLE, 'import', [] ],
			'/admin/taxes/export'                      => [ WP_REST_Server::READABLE, 'export', [] ],
			'/admin/taxes/presets/(?P<id>[a-z0-9_-]+)' => [ WP_REST_Server::CREATABLE, 'preview', [] ],
			'/admin/taxes/presets/(?P<id>[a-z0-9_-]+)/apply' => [ WP_REST_Server::CREATABLE, 'apply', [] ],
		];
		foreach ( $routes as $route => [ $methods, $callback, $args ] ) {
			$handlers = [
				[
					'methods'             => $methods,
					'callback'            => [ $this, $callback ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => $args,
				],
			];
			if ( $route === '/admin/taxes/rates' ) {
				$handlers[] = [
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				];
			}
			register_rest_route( self::NAMESPACE, $route, $handlers );
		}
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function overview( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$tax     = self::tax();
		$counts  = $tax->repository()->counts();
		$classes = [
			[
				'slug'  => '',
				'name'  => __( 'Standard', 'flexacommerce' ),
				'rates' => $counts[''] ?? 0,
			],
		];
		$stored  = Settings::get( 'tax_classes' );
		foreach ( is_array( $stored ) ? $stored : [] as $class ) {
			$classes[] = [
				'slug'  => (string) $class['slug'],
				'name'  => (string) $class['name'],
				'rates' => $counts[ (string) $class['slug'] ] ?? 0,
			];
		}
		$presets = [];
		foreach ( Plugin::instance()->tax()->presets()->all() as $preset ) {
			$presets[] = $preset->to_array();
		}
		return new WP_REST_Response(
			[
				'classes'    => $classes,
				'presets'    => $presets,
				'countries'  => self::countries(),
				'batch'      => TaxCsv::BATCH,
				'csv_header' => TaxCsv::HEADER,
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function rates( WP_REST_Request $request ): WP_REST_Response {
		$page = self::tax()->repository()->page(
			sanitize_title( (string) $request->get_param( 'tax_class' ) ),
			sanitize_text_field( (string) $request->get_param( 'search' ) ),
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' )
		);
		return new WP_REST_Response(
			[
				'rows'  => array_map( static fn ( RateRow $r ): array => $r->to_array(), $page['rows'] ),
				'total' => $page['total'],
			],
			200
		);
	}

	/**
	 * Save edited and new rows (`rows`, id 0 for new) and delete `deleted`
	 * ids, all or nothing. An edited preset row becomes `manual`, so a
	 * preset re-apply leaves it alone (doc 02 §7.12.8).
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body    = (array) $request->get_json_params();
		$tax     = self::tax();
		$known   = [ '', ...$tax->classes() ];
		$rows    = [];
		$errors  = [];
		$entries = is_array( $body['rows'] ?? null ) ? array_values( $body['rows'] ) : [];
		foreach ( $entries as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			try {
				$row = RateRow::from_input( [ 'source' => 'manual' ] + $entry, max( 0, (int) ( $entry['id'] ?? 0 ) ) );
				if ( ! in_array( $row->tax_class, $known, true ) ) {
					throw new \InvalidArgumentException( __( 'Unknown tax class.', 'flexacommerce' ) );
				}
				$rows[] = $row;
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = [
					'index'   => $index,
					'message' => $e->getMessage(),
				];
			}
		}
		if ( $errors !== [] ) {
			return new WP_Error(
				'flexacommerce_invalid_tax_rates',
				__( 'Some rates are not valid. Nothing was saved.', 'flexacommerce' ),
				[
					'status' => 400,
					'errors' => $errors,
				]
			);
		}
		$deleted = is_array( $body['deleted'] ?? null ) ? array_map( 'intval', $body['deleted'] ) : [];
		$tax->repository()->change( $rows, array_values( $deleted ) );
		$tax->compile_base_rates();
		return new WP_REST_Response(
			[
				'saved'   => count( $rows ),
				'deleted' => count( $deleted ),
			],
			200
		);
	}

	/**
	 * One CSV batch (at most TaxCsv::BATCH rows). `validate_only` writes
	 * nothing; the screen runs the whole file that way first, so a file with
	 * an error is never half imported. `replace` with `start` clears the
	 * selected class before the first batch.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$rows = is_array( $body['rows'] ?? null ) ? array_values( $body['rows'] ) : [];
		if ( count( $rows ) > TaxCsv::BATCH ) {
			return new WP_Error(
				'flexacommerce_batch_too_large',
				/* translators: %d: rows per batch. */
				sprintf( __( 'Send at most %d rows per request.', 'flexacommerce' ), TaxCsv::BATCH ),
				[ 'status' => 400 ]
			);
		}
		$tax     = self::tax();
		$replace = ( $body['mode'] ?? 'add' ) === 'replace';
		$class   = sanitize_title( (string) ( $body['tax_class'] ?? '' ) );
		$offset  = max( 0, (int) ( $body['offset'] ?? 0 ) );
		$parsed  = TaxCsv::parse(
			$rows,
			[ '', ...$tax->classes() ],
			$replace ? $class : null,
			max( 1, (int) ( $body['first_line'] ?? $offset + 2 ) ),
			$offset
		);
		if ( $parsed['errors'] !== [] || ! empty( $body['validate_only'] ) ) {
			return new WP_REST_Response(
				[
					'written' => 0,
					'valid'   => count( $parsed['rows'] ),
					'errors'  => $parsed['errors'],
				],
				$parsed['errors'] !== [] ? 400 : 200
			);
		}
		$repo = $tax->repository();
		if ( $replace && ! empty( $body['start'] ) ) {
			$repo->delete_class( $class );
		}
		$written = $repo->save_many( $parsed['rows'] );
		if ( ! empty( $body['last'] ) ) {
			$tax->compile_base_rates();
		}
		return new WP_REST_Response(
			[
				'written' => $written,
				'valid'   => $written,
				'errors'  => [],
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		$class = $request->get_param( 'tax_class' );
		$rows  = self::tax()->repository()->all( is_string( $class ) ? sanitize_title( $class ) : null );
		return new WP_REST_Response(
			[
				'filename' => 'tax-rates-' . gmdate( 'Y-m-d' ) . '.csv',
				'csv'      => TaxCsv::export( $rows ),
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$presets = Plugin::instance()->tax()->presets();
		$preset  = $presets->get( (string) $request->get_param( 'id' ) );
		if ( $preset === null ) {
			return self::no_preset();
		}
		return new WP_REST_Response( $presets->preview( $preset, self::states( $request ) ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$presets = Plugin::instance()->tax()->presets();
		$preset  = $presets->get( (string) $request->get_param( 'id' ) );
		if ( $preset === null ) {
			return self::no_preset();
		}
		$body     = (array) $request->get_json_params();
		$settings = array_values( array_filter( is_array( $body['settings'] ?? null ) ? $body['settings'] : [], 'is_string' ) );
		return new WP_REST_Response( [ 'written' => $presets->apply( $preset, self::states( $request ), $settings ) ], 200 );
	}

	private static function tax(): TaxService {
		return Plugin::instance()->tax()->service();
	}

	/**
	 * @param WP_REST_Request<array<string,mixed>> $request
	 * @return list<string>
	 */
	private static function states( WP_REST_Request $request ): array {
		$body = (array) $request->get_json_params();
		$out  = [];
		foreach ( is_array( $body['states'] ?? null ) ? $body['states'] : [] as $state ) {
			if ( is_string( $state ) && preg_match( '/^[A-Za-z0-9]{1,20}$/', $state ) === 1 ) {
				$out[] = strtoupper( $state );
			}
		}
		return $out;
	}

	private static function no_preset(): WP_Error {
		return new WP_Error( 'flexacommerce_unknown_preset', __( 'There is no tax preset with that id.', 'flexacommerce' ), [ 'status' => 404 ] );
	}

	/** @return list<array{code:string,name:string,states:list<array{code:string,name:string}>}> */
	private static function countries(): array {
		$out = [];
		foreach ( Countries::all() as $code => $name ) {
			$states = [];
			foreach ( Countries::states( $code ) as $state => $label ) {
				$states[] = [
					'code' => $state,
					'name' => $label,
				];
			}
			$out[] = [
				'code'   => $code,
				'name'   => $name,
				'states' => $states,
			];
		}
		return $out;
	}
}
