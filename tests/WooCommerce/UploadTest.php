<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\Transport\Uploads;
use Flexa\Commerce\Plugin;

/**
 * An export file sent from the Migration screen (doc 05 §4.6): chunks over
 * REST into `uploads/flexacommerce-imports`, then a file job.
 */
final class UploadTest extends \WP_UnitTestCase {
	private string $export = '';

	public function set_up(): void {
		parent::set_up();
		GoldenStore::build();
		wp_set_current_user( 1 );
		rest_get_server();
		$this->export = get_temp_dir() . 'fxc-upload-' . wp_generate_password( 8, false ) . '.jsonl';
		Plugin::instance()->migration()->export( 'wc', [], $this->export );
	}

	public function tear_down(): void {
		foreach ( [ $this->export, $this->export . '.index' ] as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		// The suite shares the uploads folder of the dev site; leave nothing in it.
		$dir = Uploads::dir();
		if ( is_dir( $dir ) ) {
			foreach ( (array) scandir( $dir ) as $name ) {
				if ( is_string( $name ) && is_file( $dir . '/' . $name ) ) {
					unlink( $dir . '/' . $name );
				}
			}
			rmdir( $dir );
		}
		parent::tear_down();
	}

	public function test_a_file_sent_in_chunks_becomes_a_file_job(): void {
		$body  = (string) file_get_contents( $this->export );
		$begin = $this->json( 'POST', '/admin/migrations/uploads', [ 'name' => 'shop export.jsonl', 'size' => strlen( $body ) ] );
		$this->assertSame( 201, $begin->get_status() );
		$token = $begin->get_data()['token'];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
		$this->assertFileExists( Uploads::dir() . '/.htaccess' );

		$half = intdiv( strlen( $body ), 2 );
		$this->assertSame( $half, $this->chunk( $token, 0, substr( $body, 0, $half ) )->get_data()['received'] );

		$again = $this->chunk( $token, 0, substr( $body, 0, $half ) );
		$this->assertSame( 409, $again->get_status(), 'A resent chunk is refused.' );
		$this->assertSame( $half, $again->as_error()->get_error_data()['received'] );

		$early = $this->json( 'POST', '/admin/migrations/import', [ 'upload' => $token ] );
		$this->assertSame( 409, $early->get_status() );
		$this->assertSame( 'flexacommerce_migration_upload_incomplete', $early->as_error()->get_error_code() );

		$this->assertSame( strlen( $body ), $this->chunk( $token, $half, substr( $body, $half ) )->get_data()['received'] );

		$created = $this->json(
			'POST',
			'/admin/migrations/import',
			[
				'upload'  => $token,
				'dry_run' => true,
				'options' => [ 'file' => '/etc/passwd' ],
			]
		);
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$job = $created->get_data();
		$this->assertSame( Direction::WC_TO_FXC, $job['direction'] );
		$this->assertSame( JobStatus::READY, $job['status'] );
		$this->assertSame(
			[
				'name'      => 'shop-export.jsonl',
				'size'      => strlen( $body ),
				'exists'    => true,
				'removable' => true,
			],
			$job['file']
		);
		$path = (string) Plugin::instance()->migration()->service()->find( $job['id'] )->option( 'file' );
		$this->assertSame( Uploads::dir() . '/' . $token . '.jsonl', $path, 'The path in the request options is ignored.' );
		$this->assertFileDoesNotExist( Uploads::dir() . '/' . $token . '.part' );

		$listed = $this->json( 'GET', '/admin/migrations' )->get_data()['jobs'][0];
		$this->assertSame( 'shop-export.jsonl', $listed['file']['name'] );

		$removed = $this->json( 'POST', "/admin/migrations/{$job['id']}/remove-file" );
		$this->assertSame( 200, $removed->get_status() );
		$this->assertFalse( $removed->get_data()['file']['exists'] );
		$this->assertFalse( $removed->get_data()['file']['removable'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertFileDoesNotExist( $path . '.index' );

		$start = $this->json( 'POST', "/admin/migrations/{$job['id']}/start" );
		$this->assertSame( 409, $start->get_status() );
		$this->assertSame( 'flexacommerce_migration_file_missing', $start->as_error()->get_error_code() );
	}

	public function test_a_broken_upload_is_deleted_and_no_job_is_made(): void {
		$jobs  = count( Plugin::instance()->migration()->service()->recent( 50 ) );
		$body  = '{"type":"product","id":"wc:product:1","data":{}}';
		$token = $this->json( 'POST', '/admin/migrations/uploads', [ 'name' => 'x.jsonl', 'size' => strlen( $body ) ] )->get_data()['token'];
		$this->chunk( $token, 0, $body );

		$created = $this->json( 'POST', '/admin/migrations/import', [ 'upload' => $token ] );
		$this->assertSame( 400, $created->get_status() );
		$this->assertSame( 'flexacommerce_migration_invalid_file', $created->as_error()->get_error_code() );
		$this->assertFileDoesNotExist( Uploads::dir() . '/' . $token . '.jsonl' );
		$this->assertCount( $jobs, Plugin::instance()->migration()->service()->recent( 50 ) );
	}

	public function test_an_upload_belongs_to_the_user_who_began_it(): void {
		$token = $this->json( 'POST', '/admin/migrations/uploads', [ 'name' => 'x.jsonl', 'size' => 10 ] )->get_data()['token'];
		$this->assertSame( 400, $this->chunk( $token, 0, str_repeat( 'a', 11 ) )->get_status(), 'More than the announced size is refused.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 404, $this->chunk( $token, 0, 'a' )->get_status() );
		$this->assertSame( 404, $this->json( 'DELETE', "/admin/migrations/uploads/{$token}" )->get_status() );

		wp_set_current_user( 1 );
		$this->assertSame( 204, $this->json( 'DELETE', "/admin/migrations/uploads/{$token}" )->get_status() );
		$this->assertFileDoesNotExist( Uploads::dir() . '/' . $token . '.part' );
		$this->assertSame( 404, $this->chunk( $token, 0, 'a' )->get_status() );
	}

	public function test_a_file_given_to_the_cli_is_not_deleted_from_the_screen(): void {
		$job = Plugin::instance()->migration()->service()->create_from_file( $this->export, [], true, 1 );
		$this->assertSame( false, $this->json( 'GET', "/admin/migrations/{$job->id}" )->get_data()['file']['removable'] );

		$removed = $this->json( 'POST', "/admin/migrations/{$job->id}/remove-file" );
		$this->assertSame( 400, $removed->get_status() );
		$this->assertFileExists( $this->export );
	}

	/** @param array<string,mixed> $body */
	private function json( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/' . FLEXACOMMERCE_REST_NAMESPACE . $route );
		if ( $body !== [] ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	private function chunk( string $token, int $offset, string $bytes ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/' . FLEXACOMMERCE_REST_NAMESPACE . '/admin/migrations/uploads/' . $token );
		$request->set_header( 'Content-Type', 'application/octet-stream' );
		$request->set_query_params( [ 'offset' => $offset ] );
		$request->set_body( $bytes );
		return rest_do_request( $request );
	}
}
