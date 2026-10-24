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

	public function test_admin_can_read_status_and_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$status = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/status' ) );
		self::assertSame( 200, $status->get_status() );
		self::assertSame( 1, $status->get_data()['database']['installed_version'] );

		$settings = $this->server->dispatch( new \WP_REST_Request( 'GET', '/flexacommerce/v1/admin/settings' ) );
		self::assertSame( 'USD', $settings->get_data()['settings']['currency'] );
	}

	public function test_enabling_data_removal_requires_confirmation(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new \WP_REST_Request( 'PATCH', '/flexacommerce/v1/admin/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'remove_data_on_uninstall' => true ] ) );
		self::assertSame( 400, $this->server->dispatch( $request )->get_status() );

		$request->set_body( (string) wp_json_encode( [ 'remove_data_on_uninstall' => true, 'confirm' => 'DELETE' ] ) );
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
