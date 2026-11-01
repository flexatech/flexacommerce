<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Api;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Tests\Integration\Catalog\CatalogTestCase;

/**
 * The whole-product admin API a standalone editor saves through (ADR-019).
 */
final class AdminProductsTest extends CatalogTestCase {
	private \WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
	}

	public function test_create_saves_post_and_commerce_data_together(): void {
		$category = self::factory()->term->create( [ 'taxonomy' => PostTypes::CATEGORY ] );
		$response = $this->send(
			'POST',
			'/admin/products',
			[
				'title'      => 'Mug "Classic" \\ blue',
				'status'     => 'publish',
				'content'    => '<p>Hand made.</p>',
				'categories' => [ $category ],
				'base'       => [
					'sku'            => 'MUG-' . $this->run,
					'regular_price'  => 1200,
					'manage_stock'   => true,
					'stock_quantity' => 4,
				],
			]
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->track_post( $data['id'] );

		self::assertSame( 'Mug "Classic" \\ blue', get_post( $data['id'] )->post_title );
		self::assertSame( 'publish', $data['status'] );
		self::assertSame( [ $category ], $data['categories'] );
		self::assertSame( 1200, $data['base']['regular_price'] );
		self::assertSame( 4, $data['base']['stock_quantity'] );
		self::assertStringEndsWith( '/admin/products/' . $data['id'], $response->get_headers()['Location'] );
	}

	public function test_a_failed_commerce_save_leaves_no_post(): void {
		$sku = 'DUP-' . $this->run;
		$this->service()->save( $this->product(), \Flexa\Commerce\Catalog\ProductInput::from_array( [ 'base' => [ 'sku' => $sku ] ] ) );
		$before = $this->count_products();

		$response = $this->send(
			'POST',
			'/admin/products',
			[
				'title' => 'Copy',
				'base'  => [ 'sku' => $sku ],
			]
		);
		self::assertGreaterThanOrEqual( 400, $response->get_status() );
		self::assertSame( $before, $this->count_products() );
	}

	public function test_bad_post_fields_are_refused_before_anything_is_written(): void {
		$before = $this->count_products();
		foreach ( [ [ 'status' => 'trash' ], [ 'date' => 'tomorrow' ], [ 'categories' => [ 999999 ] ], [ 'featured_image' => 999999 ] ] as $payload ) {
			$response = $this->send( 'POST', '/admin/products', $payload + [ 'title' => 'x' ] );
			self::assertSame( 400, $response->get_status(), (string) wp_json_encode( $payload ) );
		}
		self::assertSame( $before, $this->count_products() );
	}

	public function test_partial_update_keeps_the_rest(): void {
		$id = $this->product( 'Old title' );
		$this->service()->save( $id, \Flexa\Commerce\Catalog\ProductInput::from_array( [ 'base' => [ 'regular_price' => 500 ] ] ) );

		$response = $this->send( 'PATCH', '/admin/products/' . $id, [ 'title' => 'New title' ] );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'New title', $response->get_data()['title'] );
		self::assertSame( 500, $response->get_data()['base']['regular_price'] );
	}

	public function test_list_pages_and_counts(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->product( 'Listed ' . $this->run . ' ' . $i );
		}
		$response = $this->send(
			'GET',
			'/admin/products',
			null,
			[
				'search'   => 'Listed ' . $this->run,
				'per_page' => 2,
			]
		);
		self::assertSame( 200, $response->get_status() );
		self::assertCount( 2, $response->get_data() );
		self::assertSame( '3', $response->get_headers()['X-WP-Total'] );
		self::assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_delete_trashes_then_force_removes_variants(): void {
		global $wpdb;
		$id = $this->product();
		$this->service()->ensure_base( $id );

		$response = $this->send( 'DELETE', '/admin/products/' . $id );
		self::assertTrue( $response->get_data()['trashed'] );
		self::assertSame( 'trash', get_post_status( $id ) );

		$response = $this->send( 'DELETE', '/admin/products/' . $id, null, [ 'force' => true ] );
		self::assertTrue( $response->get_data()['deleted'] );
		self::assertNull( get_post( $id ) );
		self::assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fxc_variants WHERE product_id = %d", $id ) ) );
	}

	public function test_contributors_cannot_publish_or_delete_others(): void {
		$id = $this->product();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		self::assertSame( 403, $this->send( 'PATCH', '/admin/products/' . $id, [ 'title' => 'x' ] )->get_status() );
		self::assertSame( 403, $this->send( 'DELETE', '/admin/products/' . $id )->get_status() );
		self::assertSame(
			403,
			$this->send(
				'POST',
				'/admin/products',
				[
					'title'  => 'x',
					'status' => 'publish',
				]
			)->get_status()
		);

		wp_set_current_user( 0 );
		self::assertSame( 401, $this->send( 'GET', '/admin/products' )->get_status() );
	}

	public function test_the_editor_mode_filter_turns_the_metabox_off(): void {
		self::assertSame( 'post', \Flexa\Commerce\Admin\ProductEditor::mode() );
		add_filter( 'flexacommerce.admin.product_editor', static fn (): string => 'app' );
		self::assertSame( 'app', \Flexa\Commerce\Admin\ProductEditor::mode() );
		add_filter( 'flexacommerce.admin.product_editor', static fn (): string => 'nonsense', 20 );
		self::assertSame( 'post', \Flexa\Commerce\Admin\ProductEditor::mode() );
	}

	public function test_a_grouped_product_lists_its_children_and_an_external_its_link(): void {
		$child = $this->product( 'Child ' . $this->run );
		$this->service()->save( $child, \Flexa\Commerce\Catalog\ProductInput::from_array( [ 'base' => [ 'sku' => 'CH-' . $this->run ] ] ) );
		$response = $this->send(
			'POST',
			'/admin/products',
			[
				'title'    => 'Pack',
				'type'     => 'grouped',
				'children' => [ [ 'id' => $child ] ],
			]
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->track_post( $response->get_data()['id'] );
		self::assertSame( 'grouped', $response->get_data()['type'] );
		self::assertSame( [ $child ], array_column( $response->get_data()['children'], 'id' ) );
		self::assertSame( 'CH-' . $this->run, $response->get_data()['children'][0]['sku'] );
		self::assertSame(
			1,
			count(
				$this->send(
					'GET',
					'/admin/products',
					null,
					[
						'type'   => 'grouped',
						'search' => 'Pack',
					]
				)->get_data()
			)
		);

		$response = $this->send(
			'POST',
			'/admin/products',
			[
				'title'       => 'Lamp',
				'type'        => 'external',
				'product_url' => 'not a link',
			]
		);
		self::assertSame( 400, $response->get_status() );
		$response = $this->send(
			'POST',
			'/admin/products',
			[
				'title'       => 'Lamp',
				'type'        => 'external',
				'product_url' => 'https://partner.example/lamp',
			]
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->track_post( $response->get_data()['id'] );
		self::assertSame( [ 'https://partner.example/lamp', '' ], [ $response->get_data()['product_url'], $response->get_data()['button_text'] ] );
	}

	public function test_a_bundle_saves_its_items_and_reads_them_back_with_labels(): void {
		$item = $this->product( 'Item ' . $this->run );
		$this->service()->save(
			$item,
			\Flexa\Commerce\Catalog\ProductInput::from_array(
				[
					'base' => [
						'sku'           => 'IT-' . $this->run,
						'regular_price' => 700,
					],
				]
			)
		);
		$response = $this->send(
			'POST',
			'/admin/products',
			[
				'title'  => 'Kit',
				'type'   => 'bundle',
				'bundle' => [
					'mode'  => 'sum',
					'items' => [
						[
							'product_id' => $item,
							'quantity'   => 3,
						],
					],
				],
			]
		);
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->track_post( $data['id'] );
		self::assertSame( 'bundle', $data['type'] );
		self::assertSame( 'sum', $data['bundle']['mode'] );
		self::assertSame( [ $item, 3, 'IT-' . $this->run, false ], [ $data['bundle']['items'][0]['product_id'], $data['bundle']['items'][0]['quantity'], $data['bundle']['items'][0]['sku'], $data['bundle']['items'][0]['missing'] ] );
		self::assertSame( 2100, $data['base']['regular_price'] );

		// A save without `bundle` keeps the items.
		$after = $this->send( 'PUT', '/admin/products/' . $data['id'], [ 'title' => 'Kit 2' ] )->get_data();
		self::assertCount( 1, $after['bundle']['items'] );

		$bad = $this->send(
			'POST',
			'/admin/products',
			[
				'title'  => 'Kit',
				'type'   => 'bundle',
				'bundle' => [ 'items' => [ [ 'product_id' => $data['id'] ] ] ],
			]
		);
		self::assertSame( 400, $bad->get_status() );
	}

	private function count_products(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", PostTypes::PRODUCT ) );
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,mixed>      $query
	 */
	private function send( string $method, string $path, ?array $body = null, array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/flexacommerce/v1' . $path );
		$request->set_query_params( $query );
		if ( $body !== null ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return $this->server->dispatch( $request );
	}
}
