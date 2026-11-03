<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Setup\Pages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The store pages card (ADR-083): GET lists each page and what is wrong
 * with it, POST /fix creates, restores or repairs pages, POST /assign
 * points a page setting at an existing page, POST /shop sets the shop's
 * filter sidebar (ADR-084).
 */
final class PagesEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/pages',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'show' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/pages/fix',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'fix' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/pages/assign',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'assign' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/pages/shop',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'shop' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( self::payload(), 200 );
	}

	/**
	 * `keys` names the pages to fix; without it, the required pages that are missing or in the trash.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function fix( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$keys = isset( $body['keys'] ) && is_array( $body['keys'] ) ? array_values( array_filter( $body['keys'], 'is_string' ) ) : null;
		if ( $keys === null ) {
			$keys = array_values( array_filter( Pages::REQUIRED, static fn ( string $key ): bool => in_array( Pages::problem( $key ), [ 'missing', 'trash' ], true ) ) );
		}
		$unknown = array_diff( $keys, Pages::KEYS );
		if ( $unknown !== [] ) {
			return new WP_Error( 'flexacommerce_unknown_page', __( 'Unknown store page.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		$fixed = array_values( array_filter( $keys, static fn ( string $key ): bool => Pages::fix( $key ) ) );
		return new WP_REST_Response( self::payload() + [ 'fixed' => $fixed ], 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function assign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$key  = isset( $body['key'] ) && is_string( $body['key'] ) ? $body['key'] : '';
		$id   = isset( $body['page_id'] ) && is_numeric( $body['page_id'] ) ? (int) $body['page_id'] : -1;
		if ( ! in_array( $key, Pages::KEYS, true ) || $id < 0 || ! Pages::assign( $key, $id ) ) {
			return new WP_Error( 'flexacommerce_invalid_page', __( 'That page cannot be used here.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		return new WP_REST_Response( self::payload(), 200 );
	}

	/**
	 * `filters` is off, left or right.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function shop( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		$side = isset( $body['filters'] ) && is_string( $body['filters'] ) ? $body['filters'] : '';
		if ( ! Pages::shop_filters( $side ) ) {
			return new WP_Error( 'flexacommerce_invalid_filters', __( 'Choose off, left or right.', 'flexacommerce' ), [ 'status' => 400 ] );
		}
		return new WP_REST_Response( self::payload(), 200 );
	}

	/** @return array{pages:list<array<string,mixed>>,choices:list<array{id:int,title:string,status:string}>,shop:array<string,mixed>} */
	private static function payload(): array {
		return [
			'pages'   => Pages::status(),
			'choices' => Pages::choices(),
			'shop'    => Pages::shop(),
		];
	}
}
