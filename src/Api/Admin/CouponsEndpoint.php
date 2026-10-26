<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\RuleTargets;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The Coupons screen (doc 04 §3). Store managers only. Fixed amounts are in
 * the minor unit of the store currency, a percent in hundredths of a
 * percent; dates are UTC and come back as ISO 8601 with `Z`.
 */
final class CouponsEndpoint extends Endpoint {
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
			'/admin/coupons',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'search'   => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'status'   => [
							'type' => 'string',
							'enum' => [ '', Coupon::ACTIVE, Coupon::DISABLED ],
						],
						'page'     => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						],
					],
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
			'/admin/coupons/targets',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'targets' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'kind'         => [
							'type'    => 'string',
							'enum'    => [ RuleTargets::PRODUCT, RuleTargets::CATEGORY ],
							'default' => RuleTargets::PRODUCT,
						],
						'search'       => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'product_ids'  => [
							'type'     => 'array',
							'items'    => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'maxItems' => 1000,
							'default'  => [],
						],
						'variant_ids'  => [
							'type'     => 'array',
							'items'    => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'maxItems' => 1000,
							'default'  => [],
						],
						'category_ids' => [
							'type'     => 'array',
							'items'    => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'maxItems' => 1000,
							'default'  => [],
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/coupons/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => $id,
				],
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
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = Plugin::instance()->coupons()->repository()->query(
			[
				'search'   => (string) $request->get_param( 'search' ),
				'status'   => (string) $request->get_param( 'status' ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => $per_page,
			]
		);
		$response = new WP_REST_Response( array_map( [ self::class, 'view' ], $result['coupons'] ), 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$coupon = Plugin::instance()->coupons()->repository()->find( (int) $request->get_param( 'id' ) );
		if ( $coupon === null ) {
			return new WP_Error( 'flexacommerce_invalid_coupon', __( 'Coupon not found.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( self::view( $coupon ), 200 );
	}

	/**
	 * Picker rows: the names of the given ids, or with no ids a search of
	 * products (with variations) or categories.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function targets( WP_REST_Request $request ): WP_REST_Response {
		$catalog = Plugin::instance()->catalog();
		$targets = new RuleTargets( $catalog->products(), $catalog->variants() );
		$ids     = static fn ( string $key ): array => array_values( array_unique( array_map( 'intval', (array) $request->get_param( $key ) ) ) );
		$given   = [ $ids( 'product_ids' ), $ids( 'variant_ids' ), $ids( 'category_ids' ) ];
		if ( $given !== [ [], [], [] ] ) {
			return new WP_REST_Response( $targets->lookup( ...$given ), 200 );
		}
		$search = (string) $request->get_param( 'search' );
		$rows   = $request->get_param( 'kind' ) === RuleTargets::CATEGORY ? $targets->search_categories( $search ) : $targets->search_products( $search );
		return new WP_REST_Response( $rows, 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->save( $request, 0, 201 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->save( $request, (int) $request->get_param( 'id' ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			Plugin::instance()->coupons()->editor()->delete( (int) $request->get_param( 'id' ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	private function save( WP_REST_Request $request, int $id, int $status ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();
		unset( $body['id'], $body['usage_count'], $body['created_at'], $body['updated_at'] );
		try {
			$coupon = Plugin::instance()->coupons()->editor()->save( $body, $id );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( self::view( $coupon ), $status );
	}

	/** @return array<string,mixed> */
	private static function view( Coupon $coupon ): array {
		$data = $coupon->to_array();
		foreach ( [ 'starts_at', 'expires_at', 'created_at', 'updated_at' ] as $key ) {
			$data[ $key ] = is_string( $data[ $key ] ) && $data[ $key ] !== '' ? str_replace( ' ', 'T', $data[ $key ] ) . 'Z' : null;
		}
		$data['currency'] = Settings::string( 'currency' );
		return $data;
	}
}
