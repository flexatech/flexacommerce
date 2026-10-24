<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\InvalidAttribute;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Global attributes and their values (ADR-006). Reading is open to product
 * editors, who pick from the list; changes need the term capability.
 */
final class AttributesEndpoint extends Endpoint {
	public function register_routes(): void {
		$name = [
			'name' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
		$id   = [
			'id' => [
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			],
		];

		register_rest_route(
			self::NAMESPACE,
			'/admin/attributes',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'edit_products_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'manage_product_terms_permission' ],
					'args'                => $name,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/attributes/reorder',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'reorder' ],
					'permission_callback' => [ $this, 'manage_product_terms_permission' ],
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
			'/admin/attributes/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'rename' ],
					'permission_callback' => [ $this, 'manage_product_terms_permission' ],
					'args'                => $id + $name,
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'manage_product_terms_permission' ],
					'args'                => $id,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/attributes/(?P<id>\d+)/values',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_value' ],
					'permission_callback' => [ $this, 'manage_product_terms_permission' ],
					'args'                => $id + $name,
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->listing( 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->attempt(
			static fn () => Plugin::instance()->catalog()->attributes()->create_attribute( (string) $request->get_param( 'name' ) ),
			201
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function create_value( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->attempt(
			static fn () => Plugin::instance()->catalog()->attributes()->create_value( (int) $request->get_param( 'id' ), (string) $request->get_param( 'name' ) ),
			201
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function rename( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->attempt(
			static function () use ( $request ): void {
				$repository = Plugin::instance()->catalog()->attributes();
				$id         = (int) $request->get_param( 'id' );
				if ( $repository->name( $id ) === '' ) {
					throw new InvalidAttribute( __( 'Attribute not found.', 'flexacommerce' ) );
				}
				$repository->rename( $id, (string) $request->get_param( 'name' ) );
			}
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->attempt(
			static fn () => Plugin::instance()->catalog()->attributes()->delete( (int) $request->get_param( 'id' ) )
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function reorder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ), static fn ( int $id ): bool => $id > 0 ) );
		return $this->attempt( static fn () => Plugin::instance()->catalog()->attributes()->reorder( $ids ) );
	}

	/** @param callable():mixed $work */
	private function attempt( callable $work, int $status = 200 ): WP_REST_Response|WP_Error {
		try {
			$work();
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return $this->listing( $status );
	}

	private function listing( int $status ): WP_REST_Response {
		$repository = Plugin::instance()->catalog()->attributes();
		$items      = array_map(
			static function ( array $attribute ) use ( $repository ): array {
				$attribute['usage'] = $repository->usage( $attribute['id'] );
				return $attribute;
			},
			$repository->all()
		);
		return new WP_REST_Response( [ 'items' => $items ], $status );
	}
}
