<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Inventory\OptionStockRepository;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Options\OptionSet;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Product option sets (ADR-025). Every call answers with the whole list,
 * in order, so the screen never has to merge a change into what it shows.
 */
final class OptionSetsEndpoint extends Endpoint {
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
			'/admin/product-options',
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
			'/admin/product-options/reorder',
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
			'/admin/product-options/(?P<id>\d+)',
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
		return self::attempt( static fn () => Plugin::instance()->option_sets()->save( self::input( $request ) ), 201 );
	}

	/**
	 * Changes only the fields sent.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::attempt( static fn () => Plugin::instance()->option_sets()->save( self::input( $request ), (int) $request->get_param( 'id' ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::attempt( static fn () => Plugin::instance()->option_sets()->delete( (int) $request->get_param( 'id' ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function reorder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ), static fn ( int $id ): bool => $id > 0 ) );
		return self::attempt( static fn () => Plugin::instance()->option_sets()->reorder( $ids ) );
	}

	/**
	 * The fields of a set in the request; the repository checks them.
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
		return new WP_REST_Response(
			[
				'items' => self::with_stock( Plugin::instance()->option_sets()->all() ),
			],
			$status
		);
	}

	/**
	 * The sets with each choice's `stock`: what is left, or null when the
	 * choice does not track stock (ADR-040).
	 *
	 * @param list<OptionSet> $sets
	 * @return list<array<string,mixed>>
	 */
	private static function with_stock( array $sets ): array {
		$keys = [];
		foreach ( $sets as $set ) {
			foreach ( $set->fields as $field ) {
				foreach ( $field->choices as $choice ) {
					if ( $choice['track_stock'] ) {
						$keys[] = OptionStockRepository::key( $set->id, $field->id, $choice['id'] );
					}
				}
			}
		}
		$levels = $keys === [] ? [] : Plugin::instance()->option_stock()->levels( $keys );
		$items  = [];
		foreach ( $sets as $set ) {
			$row = $set->to_array();
			foreach ( $set->fields as $i => $field ) {
				foreach ( $field->choices as $j => $choice ) {
					$row['fields'][ $i ]['choices'][ $j ]['stock'] = $choice['track_stock']
						? ( $levels[ OptionStockRepository::key( $set->id, $field->id, $choice['id'] ) ] ?? 0 )
						: null;
				}
			}
			$items[] = $row;
		}
		return $items;
	}
}
