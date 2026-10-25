<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration;

final class RoutesHavePermissionsTest extends \WP_UnitTestCase {
	private \WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
	}

	public function test_every_route_has_a_real_permission_callback(): void {
		$checked = 0;
		foreach ( $this->server->get_routes( 'flexacommerce/v1' ) as $route => $handlers ) {
			if ( $route === '/flexacommerce/v1' ) {
				continue; // The namespace index belongs to core and is public by design.
			}
			foreach ( $handlers as $handler ) {
				$callback = $handler['permission_callback'] ?? null;
				self::assertNotNull( $callback, $route );
				self::assertNotSame( '__return_true', $callback, $route );
				++$checked;
			}
		}
		self::assertGreaterThan( 0, $checked );
	}

	public function test_admin_routes_reject_anonymous_and_subscribers(): void {
		foreach ( [ '/admin/settings', '/admin/status', '/admin/logs' ] as $path ) {
			wp_set_current_user( 0 );
			$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1' . $path ) );
			self::assertSame( 401, $response->get_status(), $path );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1' . $path ) );
			self::assertSame( 403, $response->get_status(), $path );
		}
	}

	/**
	 * Every admin route and method, orders and customers included. The
	 * permission callback is called directly: a dispatch would answer 400
	 * for a missing required argument before the permission check runs.
	 */
	public function test_every_admin_handler_refuses_anonymous_and_subscribers(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$checked    = 0;
		foreach ( $this->server->get_routes( 'flexacommerce/v1' ) as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/flexacommerce/v1/admin/' ) ) {
				continue;
			}
			$path = (string) preg_replace( '/\(\?P<[^>]+>[^)]+\)/', '1', $route );
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( $handler['methods'] ) as $method ) {
					foreach ( [ 0, $subscriber ] as $user ) {
						wp_set_current_user( $user );
						$request = new \WP_REST_Request( $method, $path );
						$request->set_url_params( [ 'id' => 1 ] );
						$allowed = call_user_func( $handler['permission_callback'], $request );
						self::assertNotTrue( $allowed, "{$method} {$route} as user {$user}" );
					}
					++$checked;
				}
			}
		}
		self::assertGreaterThan( 20, $checked );
	}

	public function test_catalog_routes_reject_anonymous_and_subscribers(): void {
		$product = self::factory()->post->create( [ 'post_type' => 'fxc_product' ] );
		foreach ( [ '/admin/products/' . $product, '/admin/products/' . $product . '/variants', '/admin/attributes' ] as $path ) {
			wp_set_current_user( 0 );
			$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1' . $path ) );
			self::assertSame( 401, $response->get_status(), $path );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1' . $path ) );
			self::assertSame( 403, $response->get_status(), $path );
		}
		wp_delete_post( $product, true );
	}

	public function test_product_route_hides_other_post_types(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$page     = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/products/' . $page ) );
		self::assertSame( 404, $response->get_status() );
	}

	public function test_store_manager_can_edit_products_but_not_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'fxc_store_manager' ] ) );
		$product = self::factory()->post->create( [ 'post_type' => 'fxc_product' ] );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/products/' . $product ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'simple', $response->get_data()['type'] );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/settings' ) );
		self::assertSame( 403, $response->get_status() );
		wp_delete_post( $product, true );
	}

	public function test_admin_can_read_status_and_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$status = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/status' ) );
		self::assertSame( 200, $status->get_status() );
		self::assertSame( (int) \Flexa\Commerce\Database\Schema::DB_VERSION, $status->get_data()['database']['installed_version'] );

		$settings = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/settings' ) );
		self::assertSame( 'USD', $settings->get_data()['settings']['currency'] );
	}

	public function test_enabling_data_removal_requires_confirmation(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new \WP_REST_Request( 'PATCH', '/flexacommerce/v1/admin/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'remove_data_on_uninstall' => true ] ) );
		self::assertSame( 400, $this->server->dispatch( $request )->get_status() );

		$request->set_body(
			(string) wp_json_encode(
				[
					'remove_data_on_uninstall' => true,
					'confirm'                  => 'DELETE',
				]
			)
		);
		$response = $this->server->dispatch( $request );
		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['settings']['remove_data_on_uninstall'] );

		$request->set_body( (string) wp_json_encode( [ 'remove_data_on_uninstall' => false ] ) );
		self::assertSame( 200, $this->server->dispatch( $request )->get_status() );
	}

	public function test_logs_endpoint_lists_and_clears(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		\Flexa\Commerce\Plugin::instance()->services()->logger( 'test' )->warning( 'Hello {who}', [ 'who' => 'logs' ] );

		$request = new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/logs' );
		$request->set_param( 'channel', 'test' );
		$data = $this->server->dispatch( $request )->get_data();
		self::assertSame( 'Hello logs', $data['items'][0]['message'] );
		self::assertContains( 'test', $data['channels'] );

		$cleared = $this->server->dispatch( new \WP_REST_Request( 'DELETE', '/flexacommerce/v1/admin/logs' ) );
		self::assertSame( 200, $cleared->get_status() );
	}
}
