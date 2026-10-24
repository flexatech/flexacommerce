<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Catalog\AttributeDefinition;
use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Catalog\ProductPostInput;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin product API (doc 04 §2): the list, create, read, partial update and
 * delete, with the post fields and the commerce data in one payload. It
 * serves the editor metabox today and is the whole contract a standalone
 * editor app needs (doc 02 §6.2). Prices are integers in the currency's
 * minor unit; weight and dimensions are grams and millimetres.
 */
final class ProductsEndpoint extends Endpoint {
	public const MAX_PER_PAGE = 100;

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
			'/admin/products',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'edit_products_permission' ],
					'args'                => self::collection_args(),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'edit_products_permission' ],
				],
				'schema' => [ $this, 'schema' ],
			]
		);

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
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'delete_product_permission' ],
					'args'                => $id + [
						'force' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
				'schema' => [ $this, 'schema' ],
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

	/**
	 * One page of products, newest first by default. Post, meta and term
	 * caches come from the query and the variants of the page are loaded
	 * with one more query, so the cost does not grow with `per_page`.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$status   = (string) $request->get_param( 'status' );
		$args     = [
			'post_type'      => PostTypes::PRODUCT,
			'post_status'    => $status === 'any' ? [ 'publish', 'future', 'draft', 'pending', 'private' ] : $status,
			'perm'           => 'editable',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => (string) $request->get_param( 'orderby' ),
			'order'          => strtoupper( (string) $request->get_param( 'order' ) ),
			'no_found_rows'  => false,
		];
		$search = (string) $request->get_param( 'search' );
		if ( $search !== '' ) {
			$args['s'] = $search;
		}
		$type = (string) $request->get_param( 'type' );
		if ( $type !== '' ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed key, admin only.
				[
					'key'   => ProductRepository::META_TYPE,
					'value' => $type,
				],
			];
		}
		$tax = [];
		foreach ( [ 'category' => PostTypes::CATEGORY, 'tag' => PostTypes::TAG ] as $param => $taxonomy ) {
			$term = (int) $request->get_param( $param );
			if ( $term > 0 ) {
				$tax[] = [
					'taxonomy' => $taxonomy,
					'terms'    => [ $term ],
				];
			}
		}
		if ( $tax !== [] ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- admin list filter.
		}

		/** Filters the WP_Query arguments of the admin product list. */
		$query = new \WP_Query( (array) apply_filters( 'flexacommerce.rest.admin.products.query', $args, $request ) );
		$ids   = array_values( array_filter( array_map( static fn ( mixed $post ): int => $post instanceof \WP_Post ? $post->ID : 0, $query->posts ) ) );

		$repository = Plugin::instance()->catalog()->products();
		$repository->prime( $ids );
		$items = [];
		foreach ( $ids as $product_id ) {
			$product = $repository->find( $product_id );
			if ( $product !== null ) {
				$items[] = $this->summary_data( $product );
			}
		}

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) $query->max_num_pages ) );
		return $response;
	}

	/**
	 * Creates the post, then saves the commerce data. Both payloads are
	 * validated first; if the commerce save still fails (a duplicate SKU),
	 * the new post is removed again so a failed request leaves nothing.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = (array) $request->get_json_params();
		try {
			$post  = ProductPostInput::from_array( $data );
			$input = ProductInput::from_array( $data );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		$denied = self::check_post_caps( $post, 0 );
		if ( $denied !== null ) {
			return $denied;
		}

		try {
			$product_id = $post->apply( 0 );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		try {
			$product = Plugin::instance()->catalog()->product_service()->save( $product_id, $input );
		} catch ( CommerceException $e ) {
			wp_delete_post( $product_id, true );
			return self::error( $e );
		}

		$response = new WP_REST_Response( $this->product_data( $product ), 201 );
		$response->header( 'Location', rest_url( self::NAMESPACE . '/admin/products/' . $product_id ) );
		return $response;
	}

	/**
	 * Partial update (PUT and PATCH behave the same): absent keys keep what
	 * is stored. The commerce data is saved first because it is the part
	 * that can be refused (validation, SKUs) after the payload was read.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product_id = (int) $request->get_param( 'id' );
		$data       = (array) $request->get_json_params();
		try {
			$post  = ProductPostInput::from_array( $data );
			$input = ProductInput::from_array( $data );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		$denied = self::check_post_caps( $post, $product_id );
		if ( $denied !== null ) {
			return $denied;
		}

		$service = Plugin::instance()->catalog()->product_service();
		try {
			$product = $service->save( $product_id, $input );
			if ( ! $post->is_empty() ) {
				$post->apply( $product_id );
				$product = $service->get( $product_id );
			}
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		return new WP_REST_Response( $this->product_data( $product ), 200 );
	}

	/**
	 * Moves the product to the trash, or deletes it with `force`. Deleting
	 * removes the variant rows through the `before_delete_post` handler.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product_id = (int) $request->get_param( 'id' );
		try {
			$previous = $this->product_data( Plugin::instance()->catalog()->product_service()->get( $product_id ) );
		} catch ( CommerceException $e ) {
			return self::error( $e );
		}
		$force  = (bool) $request->get_param( 'force' );
		$result = $force ? wp_delete_post( $product_id, true ) : wp_trash_post( $product_id );
		if ( ! $result instanceof \WP_Post ) {
			return new WP_Error( 'flexacommerce_delete_failed', __( 'The product could not be deleted.', 'flexacommerce' ), [ 'status' => 500 ] );
		}
		return new WP_REST_Response(
			[
				'deleted'  => $force,
				'trashed'  => ! $force,
				'previous' => $previous,
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function delete_product_permission( WP_REST_Request $request ): bool|WP_Error {
		$allowed = $this->edit_product_permission( $request );
		if ( $allowed !== true ) {
			return $allowed;
		}
		return current_user_can( 'delete_post', (int) $request->get_param( 'id' ) ) ? true : self::forbidden();
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
		] + self::post_data( $product->id ) + [
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

	/**
	 * The list row: enough for a product table without loading the editor.
	 *
	 * @return array<string,mixed>
	 */
	private function summary_data( Product $product ): array {
		$data = [
			'id'              => $product->id,
			'type'            => $product->type,
			'status'          => $product->status,
		] + self::post_data( $product->id ) + [
			'sku'             => $product->base->sku,
			'variation_count' => count( $product->variations ),
			'visibility'      => $product->visibility,
			'featured'        => $product->featured,
			'price_min'       => self::meta_int( $product->id, ProductRepository::META_PRICE_MIN ),
			'price_max'       => self::meta_int( $product->id, ProductRepository::META_PRICE_MAX ),
			'stock_status'    => (string) get_post_meta( $product->id, ProductRepository::META_STOCK_STATUS, true ),
		];
		unset( $data['content'] );

		/**
		 * Filters a row of the admin product list.
		 *
		 * @param array<string,mixed> $data
		 * @param Product             $product
		 */
		return (array) apply_filters( 'flexacommerce.rest.product.summary', $data, $product );
	}

	/** @return array<string,mixed> */
	private static function post_data( int $product_id ): array {
		$post = get_post( $product_id );
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		$terms = static function ( string $taxonomy ) use ( $product_id ): array {
			$list = get_the_terms( $product_id, $taxonomy );
			return is_array( $list ) ? array_map( static fn ( \WP_Term $term ): int => $term->term_id, $list ) : [];
		};
		return [
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'menu_order'     => $post->menu_order,
			'categories'     => $terms( PostTypes::CATEGORY ),
			'tags'           => $terms( PostTypes::TAG ),
			'featured_image' => (int) get_post_thumbnail_id( $post ),
			'permalink'      => (string) get_permalink( $post ),
		];
	}

	/** Publishing, private and scheduled statuses, and terms, need their own capabilities. */
	private static function check_post_caps( ProductPostInput $post, int $product_id ): ?WP_Error {
		$type = get_post_type_object( PostTypes::PRODUCT );
		if ( $type === null ) {
			return self::forbidden();
		}
		$current = $product_id > 0 ? get_post_status( $product_id ) : 'draft';
		if ( $post->status !== null && $post->status !== $current && in_array( $post->status, [ 'publish', 'future', 'private' ], true )
			&& ! current_user_can( $type->cap->publish_posts ) ) {
			return self::forbidden();
		}
		foreach ( [ PostTypes::CATEGORY => $post->categories, PostTypes::TAG => $post->tags ] as $taxonomy => $terms ) {
			$object = get_taxonomy( $taxonomy );
			if ( $terms !== null && ( ! $object instanceof \WP_Taxonomy || ! current_user_can( $object->cap->assign_terms ) ) ) {
				return self::forbidden();
			}
		}
		if ( $post->featured_image !== null && $post->featured_image > 0 && ! current_user_can( 'read_post', $post->featured_image ) ) {
			return self::error( new InvalidProduct( __( 'The featured image must be an image from the media library.', 'flexacommerce' ), 'featured_image' ) );
		}
		return null;
	}

	/** @return array<string,array<string,mixed>> */
	private static function collection_args(): array {
		return [
			'page'     => [
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => self::MAX_PER_PAGE,
				'default' => 20,
			],
			'search'   => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status'   => [
				'type'    => 'string',
				'enum'    => [ 'any', ...ProductPostInput::STATUSES, 'trash' ],
				'default' => 'any',
			],
			'type'     => [
				'type'    => 'string',
				'enum'    => [ '', 'simple', 'variable' ],
				'default' => '',
			],
			'category' => [
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			],
			'tag'      => [
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			],
			'orderby'  => [
				'type'    => 'string',
				'enum'    => [ 'date', 'modified', 'title', 'menu_order', 'ID' ],
				'default' => 'date',
			],
			'order'    => [
				'type'    => 'string',
				'enum'    => [ 'asc', 'desc' ],
				'default' => 'desc',
			],
		];
	}

	/**
	 * JSON schema of a product (the shape of GET, and what PUT, PATCH and
	 * POST accept). Commerce fields are validated by ProductInput, which
	 * gives field paths in its errors; the schema documents them.
	 *
	 * @return array<string,mixed>
	 */
	public function schema(): array {
		$money = [ 'type' => [ 'integer', 'null' ], 'minimum' => 0 ];
		$row   = [
			'type'       => 'object',
			'properties' => [
				'id'                  => [ 'type' => 'integer', 'readonly' => true ],
				'attributes'          => [ 'type' => 'object', 'description' => __( 'Attribute key => value key, or "*" for any value.', 'flexacommerce' ) ],
				'enabled'             => [ 'type' => 'boolean' ],
				'menu_order'          => [ 'type' => 'integer' ],
				'sku'                 => [ 'type' => [ 'string', 'null' ], 'maxLength' => ProductInput::MAX_SKU_LENGTH ],
				'regular_price'       => $money,
				'sale_price'          => $money,
				'sale_from'           => [ 'type' => [ 'string', 'null' ], 'description' => __( 'UTC, Y-m-d H:i:s.', 'flexacommerce' ) ],
				'sale_to'             => [ 'type' => [ 'string', 'null' ], 'description' => __( 'UTC, Y-m-d H:i:s.', 'flexacommerce' ) ],
				'manage_stock'        => [ 'type' => 'boolean' ],
				'stock_from_base'     => [ 'type' => 'boolean' ],
				'stock_quantity'      => [ 'type' => [ 'integer', 'null' ] ],
				'backorders'          => [ 'type' => 'string', 'enum' => [ 'no', 'notify', 'yes' ] ],
				'low_stock_threshold' => [ 'type' => [ 'integer', 'null' ] ],
				'stock_status'        => [ 'type' => 'string' ],
				'weight'              => [ 'type' => [ 'string', 'null' ], 'description' => __( 'Grams.', 'flexacommerce' ) ],
				'length'              => [ 'type' => [ 'string', 'null' ], 'description' => __( 'Millimetres.', 'flexacommerce' ) ],
				'width'               => [ 'type' => [ 'string', 'null' ], 'description' => __( 'Millimetres.', 'flexacommerce' ) ],
				'height'              => [ 'type' => [ 'string', 'null' ], 'description' => __( 'Millimetres.', 'flexacommerce' ) ],
				'image_id'            => [ 'type' => [ 'integer', 'null' ] ],
				'tax_class'           => [ 'type' => [ 'string', 'null' ] ],
				'description'         => [ 'type' => [ 'string', 'null' ] ],
			],
		];
		$ids   = [
			'type'  => 'array',
			'items' => [ 'type' => 'integer' ],
		];

		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'flexacommerce-product',
			'type'       => 'object',
			'properties' => [
				'id'             => [ 'type' => 'integer', 'readonly' => true ],
				'type'           => [ 'type' => 'string', 'enum' => [ 'simple', 'variable' ] ],
				'status'         => [ 'type' => 'string', 'enum' => ProductPostInput::STATUSES ],
				'title'          => [ 'type' => 'string' ],
				'slug'           => [ 'type' => 'string' ],
				'content'        => [ 'type' => 'string', 'description' => __( 'Raw post content (block markup).', 'flexacommerce' ) ],
				'excerpt'        => [ 'type' => 'string' ],
				'date'           => [ 'type' => 'string', 'description' => __( 'Site time, Y-m-d H:i:s.', 'flexacommerce' ) ],
				'modified'       => [ 'type' => 'string', 'readonly' => true ],
				'menu_order'     => [ 'type' => 'integer' ],
				'categories'     => $ids,
				'tags'           => $ids,
				'featured_image' => [ 'type' => 'integer', 'description' => __( 'Attachment ID, 0 for none.', 'flexacommerce' ) ],
				'permalink'      => [ 'type' => 'string', 'format' => 'uri', 'readonly' => true ],
				'attributes'     => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'base'           => $row,
				'variations'     => [
					'type'  => 'array',
					'items' => $row,
				],
				'gallery_ids'    => $ids,
				'visibility'     => [ 'type' => 'string', 'enum' => Product::VISIBILITIES ],
				'featured'       => [ 'type' => 'boolean' ],
				'price_min'      => [ 'type' => [ 'integer', 'null' ], 'readonly' => true ],
				'price_max'      => [ 'type' => [ 'integer', 'null' ], 'readonly' => true ],
				'stock_status'   => [ 'type' => 'string', 'readonly' => true ],
			],
		];
	}

	private static function meta_int( int $product_id, string $key ): ?int {
		$value = get_post_meta( $product_id, $key, true );
		return is_numeric( $value ) ? (int) $value : null;
	}
}
