<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Catalog\AttributeDefinition;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Product data for the editor metabox (doc 04 §2). Prices are integers in
 * the currency's minor unit; weight and dimensions are grams and millimetres.
 */
final class ProductsEndpoint extends Endpoint {
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
			'/admin/products/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'edit_product_permission' ],
					'args'                => $id,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'edit_product_permission' ],
					'args'                => $id,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/products/(?P<id>\d+)/variants',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'variants' ],
					'permission_callback' => [ $this, 'edit_product_permission' ],
					'args'                => $id,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/products/(?P<id>\d+)/variants/generate',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'generate' ],
					'permission_callback' => [ $this, 'edit_product_permission' ],
					'args'                => $id + [
						'values' => [
							'type'    => 'object',
							'default' => [],
						],
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$product = Plugin::instance()->catalog()->product_service()->get( (int) $request->get_param( 'id' ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( $this->product_data( $product ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$input   = ProductInput::from_array( (array) $request->get_json_params() );
			$product = Plugin::instance()->catalog()->product_service()->save( (int) $request->get_param( 'id' ), $input );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( $this->product_data( $product ), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function variants( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$product = Plugin::instance()->catalog()->product_service()->get( (int) $request->get_param( 'id' ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response(
			[
				'base'       => $this->variant_data( $product->base ),
				'variations' => array_map( [ $this, 'variant_data' ], $product->variations ),
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function generate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$values = [];
		$raw    = $request->get_param( 'values' );
		foreach ( is_array( $raw ) ? $raw : [] as $key => $list ) {
			if ( is_array( $list ) ) {
				$values[ (string) $key ] = array_values( array_map( 'strval', array_filter( $list, 'is_scalar' ) ) );
			}
		}

		try {
			$result = Plugin::instance()->catalog()->product_service()->generate_variants( (int) $request->get_param( 'id' ), $values );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response(
			[
				'created'       => $result->created,
				'skipped'       => $result->skipped,
				'limit_reached' => $result->limit_reached,
				'product'       => $this->product_data( $result->product ),
			],
			200
		);
	}

	/** @return array<string,mixed> */
	private function product_data( Product $product ): array {
		$data = [
			'id'           => $product->id,
			'type'         => $product->type,
			'status'       => $product->status,
			'attributes'   => array_map( [ $this, 'attribute_data' ], $product->attributes ),
			'base'         => $this->variant_data( $product->base ),
			'variations'   => array_map( [ $this, 'variant_data' ], $product->variations ),
			'gallery_ids'  => $product->gallery_ids,
			'visibility'   => $product->visibility,
			'featured'     => $product->featured,
			'price_min'    => self::meta_int( $product->id, ProductRepository::META_PRICE_MIN ),
			'price_max'    => self::meta_int( $product->id, ProductRepository::META_PRICE_MAX ),
			'stock_status' => (string) get_post_meta( $product->id, ProductRepository::META_STOCK_STATUS, true ),
		];

		/**
		 * Filters the admin REST shape of a product.
		 *
		 * @param array<string,mixed> $data
		 * @param Product             $product
		 */
		return (array) apply_filters( 'flexacommerce.rest.product.response', $data, $product );
	}

	/** @return array<string,mixed> */
	private function variant_data( Variant $variant ): array {
		return (array) apply_filters( 'flexacommerce.rest.variant.response', $variant->to_array(), $variant );
	}

	/**
	 * Stored definition plus `name` and `options` (combination key and label
	 * of each value), so the editor needs no second request.
	 *
	 * @return array<string,mixed>
	 */
	private function attribute_data( AttributeDefinition $attribute ): array {
		$data = $attribute->to_array() + [ 'type' => $attribute->type ];
		if ( $attribute->is_global() ) {
			$repository      = Plugin::instance()->catalog()->attributes();
			$data['name']    = $repository->name( $attribute->attribute_term_id );
			$data['options'] = array_map(
				static fn ( int $id ): array => [
					'key'   => (string) $id,
					'label' => $repository->name( $id ),
				],
				$attribute->value_term_ids
			);
		} else {
			$data['options'] = array_map(
				static fn ( string $value ): array => [
					'key'   => Combination::local_value( $value ),
					'label' => $value,
				],
				$attribute->values
			);
		}
		return $data;
	}

	private static function meta_int( int $product_id, string $key ): ?int {
		$value = get_post_meta( $product_id, $key, true );
		return is_numeric( $value ) ? (int) $value : null;
	}
}
