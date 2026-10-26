<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Badges\Badge;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Product badges (ADR-023). Every call answers with the whole list, in
 * order, so the screen never has to merge a change into what it shows.
 */
final class BadgesEndpoint extends Endpoint {
	public function register_routes(): void {
		$id = [
			'id' => [
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			],
		];

		register_rest_route(
			self::NAMESPACE,
			'/admin/badges',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/badges/reorder',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'reorder' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'ids' => [
							'type'     => 'array',
							'items'    => [ 'type' => 'integer' ],
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/badges/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => $id,
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => $id,
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return self::listing( 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::attempt( static fn () => Plugin::instance()->badges()->save( self::input( $request ) ), 201 );
	}

	/**
	 * Changes only the fields sent.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::attempt( static fn () => Plugin::instance()->badges()->save( self::input( $request ), (int) $request->get_param( 'id' ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::attempt( static fn () => Plugin::instance()->badges()->delete( (int) $request->get_param( 'id' ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function reorder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ), static fn ( int $id ): bool => $id > 0 ) );
		return self::attempt( static fn () => Plugin::instance()->badges()->reorder( $ids ) );
	}

	/**
	 * The fields of a badge in the request; the repository checks them.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 */
	private static function input( WP_REST_Request $request ): array {
		$input = $request->get_params();
		unset( $input['id'] );
		return $input;
	}

	/** @param callable():mixed $work */
	private static function attempt( callable $work, int $status = 200 ): WP_REST_Response|WP_Error {
		try {
			$work();
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return self::listing( $status );
	}

	private static function listing( int $status ): WP_REST_Response {
		$items = array_map(
			static function ( Badge $badge ): array {
				$row = $badge->to_array();
				foreach ( [ 'starts_at', 'ends_at' ] as $key ) {
					$row[ $key ] = is_string( $row[ $key ] ) ? str_replace( ' ', 'T', $row[ $key ] ) . 'Z' : null;
				}
				return $row;
			},
			Plugin::instance()->badges()->all()
		);
		return new WP_REST_Response( [ 'items' => $items ], $status );
	}
}
