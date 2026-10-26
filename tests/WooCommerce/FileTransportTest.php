<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationError;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Migration\Transport\FileIndex;
use Flexa\Commerce\Plugin;

/**
 * Cross-site moves through a JSONL file (doc 05 §4.6). The other site is
 * simulated on this one: after the export its users and images are deleted,
 * so the import has to bring them back from the file.
 */
final class FileTransportTest extends \WP_UnitTestCase {
	/** A 1x1 PNG, the body of every mocked image download. */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	private GoldenStore $store;
	private MigrationService $service;

	/** @var list<string> */
	private array $files = [];

	/** @var list<string> */
	private array $downloads = [];

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
		add_filter( 'pre_http_request', [ $this, 'download' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'download' ] );
		foreach ( $this->files as $file ) {
			foreach ( [ $file, $file . '.index', $file . '.part' ] as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
		parent::tear_down();
	}

	/**
	 * @param false|array<string,mixed> $pre
	 * @param array<string,mixed>       $args
	 * @return array<string,mixed>
	 */
	public function download( mixed $pre, array $args, string $url ): array {
		unset( $pre );
		$this->downloads[] = $url;
		if ( is_string( $args['filename'] ?? null ) && $args['filename'] !== '' ) {
			file_put_contents( $args['filename'], base64_decode( self::PNG ) );
		}
		return [
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => $args['filename'] ?? null,
		];
	}

	public function test_a_woocommerce_export_imports_into_flexacommerce_on_another_site(): void {
		$image = $this->tee_image();
		$file  = $this->path();
		$out   = Plugin::instance()->migration()->export( 'wc', [], $file );
		$this->assertSame( 9, $out['counts']['orders'] );
		$this->assertSame( 1, $out['users'] );
		$this->assertSame( 1, $out['media'] );

		$lines  = file( $file );
		$header = json_decode( (string) $lines[0], true );
		$this->assertSame( 'header', $header['type'] );
		$this->assertSame( 'wc', $header['system'] );
		$this->assertSame( Job::STAGES, $header['stages'] );
		$this->assertStringNotContainsString( 'secret', (string) file_get_contents( $file ), 'Passwords never leave the site.' );

		$this->forget_people( $image );
		$job = $this->import( $file );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( Direction::WC_TO_FXC, $job->direction );
		$this->assertSame( 7, $job->counter( 'products', 'created' ) );
		$this->assertSame( 9, $job->counter( 'orders', 'created' ) );
		$this->assertSame( [], $job->report['mismatches'] );
		$this->assertSame( 9, $job->report['checked'] );

		$ana = get_user_by( 'email', 'ana@example.com' );
		$this->assertInstanceOf( \WP_User::class, $ana, 'The customer account is created from the file.' );
		$this->assertNotSame( $this->store->customer, $ana->ID );
		$this->assertContains( 'customer', $ana->roles );
		global $wpdb;
		$customers = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT customer_id FROM %i WHERE customer_id IS NOT NULL', Schema::table( 'orders' ) ) );
		$this->assertSame( [ (string) $ana->ID ], $customers );

		$tee       = $this->target( $job, 'product', $this->store->products['tee'] );
		$thumbnail = (int) get_post_thumbnail_id( $tee );
		$this->assertGreaterThan( 0, $thumbnail );
		$this->assertNotSame( $image, $thumbnail );
		$this->assertSame( 'A plain tee', get_post_meta( $thumbnail, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Tee photo', get_the_title( $thumbnail ) );
		$this->assertCount( 1, $this->downloads );
		$this->assertStringEndsWith( '/tee.png', $this->downloads[0] );

		$this->assertSame( 0, $this->map_rows( 'wc' ), 'A file job keeps its own id map.' );
		$this->assertGreaterThan( 30, $this->map_rows( $job->id_system() ) );
		$this->assertStringStartsWith( 'wc:', $job->id_system() );
	}

	public function test_importing_the_same_file_again_changes_nothing(): void {
		$image = $this->tee_image();
		$file  = $this->path();
		Plugin::instance()->migration()->export( 'wc', [], $file );
		$this->forget_people( $image );
		$first = $this->import( $file );
		$users = count_users()['total_users'];

		$again  = $this->import( $file );
		$totals = $again->totals();
		$this->assertSame( $first->id_system(), $again->id_system() );
		$this->assertArrayNotHasKey( 'created', $totals );
		$this->assertArrayNotHasKey( 'updated', $totals );
		$this->assertSame( 1 + 1 + 1 + 2 + 6 + 7 + 1 + 9 + 1, $totals['unchanged'] );
		$this->assertSame( $users, count_users()['total_users'], 'The customer is found again, not created twice.' );
		$this->assertCount( 1, $this->downloads, 'The image is not downloaded twice.' );
	}

	public function test_images_can_be_left_out(): void {
		$image = $this->tee_image();
		$file  = $this->path();
		Plugin::instance()->migration()->export( 'wc', [ 'stages' => [ 'products' ] ], $file );
		$this->forget_people( $image );
		$job = $this->import( $file, [ 'media' => 'skip' ] );
		$this->assertSame( [], $this->downloads );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $this->target( $job, 'product', $this->store->products['tee'] ) ) );
		$this->assertContains( 'missing_media', array_column( $this->service->log( $job->id )['rows'], 'code' ) );
		$this->assertSame( 6, $job->counter( 'terms', 'created' ), 'Terms the products need come with them, attribute values too.' );
	}

	public function test_a_flexacommerce_export_imports_into_woocommerce(): void {
		$same_site = $this->service->create( Direction::WC_TO_FXC, [ 'backup_confirmed' => true ], false, 1 );
		$this->service->start( $same_site->id );
		$this->service->run( $same_site->id );
		$users = count_users()['total_users'];

		$file = $this->path();
		$out  = Plugin::instance()->migration()->export( 'fxc', [], $file );
		$this->assertSame( 9, $out['counts']['orders'] );
		$job = $this->import( $file );
		$this->assertSame( Direction::FXC_TO_WC, $job->direction );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 9, $job->counter( 'orders', 'created' ) );
		$this->assertSame( 9, $job->report['checked'], 'The verify step reads every order back from WooCommerce.' );
		$this->assertSame( [], $job->report['mismatches'] );
		$this->assertSame( $users, count_users()['total_users'], 'The customer is matched by email.' );
		$this->assertStringStartsWith( 'fxc:', $job->id_system() );
	}

	public function test_a_broken_file_is_refused_before_the_job_exists(): void {
		$header = '{"type":"header","format_version":"1.0","system":"wc","site":{"id":"s1","url":"https://a.test/"},"stages":["products"]}';
		$cases  = [
			'empty'      => [ '', 'empty' ],
			'no header'  => [ '{"type":"product","id":"wc:product:1","data":{}}', 'Line 1' ],
			'bad json'   => [ $header . "\n" . '{"type":"product","id":"wc:product:1","data":{}}' . "\n{nope", 'Line 3' ],
			'unknown'    => [ $header . "\n" . '{"type":"widget","id":"wc:widget:1","data":{}}', 'unknown record type' ],
			'twice'      => [ $header . "\n" . '{"type":"product","id":"wc:product:1","data":{}}' . "\n" . '{"type":"product","id":"wc:product:1","data":{}}', 'twice' ],
			'twice late' => [ $header . "\n" . '{"type":"product","id":"wc:product:2","data":{}}' . "\n" . '{"type":"product","id":"wc:product:1","data":{}}' . "\n" . '{"type":"product","id":"wc:product:2","data":{}}', 'twice' ],
		];
		foreach ( $cases as $name => [ $body, $message ] ) {
			$file = $this->write( $body );
			try {
				$this->service->create_from_file( $file, [], true, 1 );
				$this->fail( "The {$name} file was accepted." );
			} catch ( MigrationError $e ) {
				$this->assertSame( 'invalid_file', $e->reason, $name );
				$this->assertStringContainsString( $message, $e->getMessage(), $name );
			}
		}
	}

	public function test_preflight_refuses_a_newer_format(): void {
		$file = $this->write( '{"type":"header","format_version":"2.0","system":"wc","site":{"id":"s1","url":"https://a.test/"},"stages":["products"]}' );
		$job  = $this->service->create_from_file( $file, [], true, 1 );
		$this->assertSame( JobStatus::DRAFT, $job->status );
		$this->assertContains( 'file_version', array_column( $job->report['preflight'], 'code' ) );
	}

	public function test_a_rest_request_cannot_point_a_job_at_a_file(): void {
		$job = $this->service->create(
			Direction::WC_TO_FXC,
			[
				'file'      => '/etc/passwd',
				'id_system' => 'x',
			],
			true,
			1
		);
		$this->assertFalse( $job->is_file() );
		$this->assertSame( 'wc', $job->id_system() );
	}

	public function test_the_index_seeks_by_id(): void {
		$file  = $this->write(
			implode(
				"\n",
				[
					'{"type":"header","format_version":"1.0","system":"wc","site":{"id":"s1","url":"https://a.test/"},"stages":["products"]}',
					'{"type":"product","id":"wc:product:5","data":{"n":5}}',
					'{"type":"user","id":"wp:user:3","data":{"email":"a@b.test"}}',
					'{"type":"product","id":"wc:product:3","data":{"n":3}}',
					'',
					'{"type":"product","id":"wc:product:9","data":{"n":9}}',
				]
			)
		);
		$index = FileIndex::open( $file );
		$this->assertSame( 3, $index->count( 'products' ) );
		$this->assertSame( 9, $index->last_id( 'products' ) );
		$this->assertSame( [ 3, 5 ], $index->ids( 'products', 0, 100, 2 ) );
		$this->assertSame( [ 5 ], $index->ids( 'products', 3, 8, 10 ) );
		$this->assertSame( [], $index->ids( 'orders', 0, 100, 10 ) );
		$this->assertSame( 9, $index->line( 'products', 9 )['data']['n'] ?? null );
		$this->assertSame( 'a@b.test', $index->line( FileIndex::USER, 3 )['data']['email'] ?? null );
		$this->assertNull( $index->line( 'products', 4 ) );

		$this->assertSame( [ 3, 5, 9 ], FileIndex::open( $file )->ids( 'products', 0, 100, 10 ), 'The sidecar is read back.' );
		file_put_contents( $file, "\n" . '{"type":"product","id":"wc:product:11","data":{}}', FILE_APPEND );
		touch( $file, time() + 5 );
		$this->assertSame( 11, FileIndex::open( $file )->last_id( 'products' ), 'A changed file is indexed again.' );
	}

	/** An image on the tee, with the details the export carries. */
	private function tee_image(): int {
		$id = wp_insert_attachment(
			[
				'post_title'     => 'Tee photo',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			],
			'2026/10/tee.png'
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'A plain tee' );
		$tee = wc_get_product( $this->store->products['tee'] );
		$tee->set_image_id( $id );
		$tee->save();
		return $id;
	}

	/** What the other site would not have: the customer account and the image. */
	private function forget_people( int $image ): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->store->customer );
		wp_delete_attachment( $image, true );
		$this->assertFalse( get_user_by( 'email', 'ana@example.com' ) );
	}

	/** @param array<string,mixed> $options */
	private function import( string $file, array $options = [] ): Job {
		$job = $this->service->create_from_file( $file, [ 'backup_confirmed' => true ] + $options, false, 1 );
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}

	private function target( Job $job, string $entity, int $wc_id ): int {
		$row = Plugin::instance()->migration()->ids()->for_system( $job->id_system() )->by_remote( $entity, (string) $wc_id );
		return $row === null ? 0 : $row->local_id;
	}

	private function map_rows( string $system ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE remote_system = %s', Schema::table( 'id_map' ), $system ) );
	}

	private function path(): string {
		$file          = get_temp_dir() . 'fxc-export-' . wp_generate_password( 8, false ) . '.jsonl';
		$this->files[] = $file;
		return $file;
	}

	private function write( string $body ): string {
		$file = $this->path();
		file_put_contents( $file, $body );
		return $file;
	}
}
