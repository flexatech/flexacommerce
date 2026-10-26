<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationError;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Migration\Sources\WooSource;
use Flexa\Commerce\Migration\Transport\Uploads;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The Migration screen (doc 05 §4.5). The screen drives a job by polling
 * `batch`; each call runs one Runner batch under the job lease, so a second
 * tab or the Action Scheduler driver gets `locked` instead of a duplicate run.
 * An export file from another site (doc 05 §4.6) is sent in chunks to
 * `uploads`, then `import` turns it into a job.
 */
final class MigrationsEndpoint extends Endpoint {
	private const ID = '/admin/migrations/(?P<id>\d+)';

	private const UPLOAD = '/admin/migrations/uploads/(?P<token>[a-f0-9]{32})';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/migrations',
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
					'args'                => [
						'direction' => [
							'type'     => 'string',
							'enum'     => Direction::ALL,
							'required' => true,
						],
						'dry_run'   => [
							'type'    => 'boolean',
							'default' => true,
						],
						'options'   => [
							'type'    => 'object',
							'default' => [],
						],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			self::ID,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'show' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			self::ID . '/log',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'log' ],
				'permission_callback' => [ $this, 'manage_permission' ],
				'args'                => [
					'level'    => [
						'type'    => 'string',
						'enum'    => [ '', 'info', 'warning', 'error' ],
						'default' => '',
					],
					'page'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
						'default' => 100,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/migrations/uploads',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'begin_upload' ],
				'permission_callback' => [ $this, 'manage_permission' ],
				'args'                => [
					'name' => [
						'type'     => 'string',
						'required' => true,
					],
					'size' => [
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			self::UPLOAD,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'append_upload' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'offset' => [
							'type'     => 'integer',
							'minimum'  => 0,
							'required' => true,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'abort_upload' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/migrations/import',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import' ],
				'permission_callback' => [ $this, 'manage_permission' ],
				'args'                => [
					'upload'  => [
						'type'     => 'string',
						'pattern'  => '^[a-f0-9]{32}$',
						'required' => true,
					],
					'dry_run' => [
						'type'    => 'boolean',
						'default' => true,
					],
					'options' => [
						'type'    => 'object',
						'default' => [],
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			self::ID . '/remove-file',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'remove_file' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
		foreach ( [ 'preflight', 'start', 'batch', 'background', 'pause', 'cancel', 'verify', 'purge' ] as $action ) {
			register_rest_route(
				self::NAMESPACE,
				self::ID . '/' . $action,
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, $action ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => $action === 'start' ? [
						'backup_confirmed' => [
							'type'    => 'boolean',
							'default' => false,
						],
					] : [],
				]
			);
		}
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$jobs = array_map( static fn ( Job $job ): array => self::summary( $job ), self::service()->recent( 20 ) );
		return new WP_REST_Response(
			[
				'jobs'        => $jobs,
				'woocommerce' => WooSource::available(),
				'directions'  => [ Direction::WC_TO_FXC, Direction::FXC_TO_WC ],
				'stages'      => Job::STAGES,
				'defaults'    => Job::DEFAULT_OPTIONS,
			],
			200
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$options = $request->get_param( 'options' );
		return self::respond(
			fn (): Job => self::service()->create(
				(string) $request->get_param( 'direction' ),
				is_array( $options ) ? $options : [],
				(bool) $request->get_param( 'dry_run' ),
				get_current_user_id()
			),
			201
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function begin_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$upload = ( new Uploads() )->begin( (string) $request->get_param( 'name' ), (int) $request->get_param( 'size' ), get_current_user_id() );
		} catch ( MigrationError $e ) {
			return self::migration_error( $e );
		}
		return new WP_REST_Response( $upload, 201 );
	}

	/**
	 * One chunk, sent as the raw request body.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function append_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$received = ( new Uploads() )->append( (string) $request->get_param( 'token' ), (int) $request->get_param( 'offset' ), $request->get_body(), get_current_user_id() );
		} catch ( MigrationError $e ) {
			if ( $e->reason === 'upload_offset' ) {
				return new WP_Error(
					'flexacommerce_migration_upload_offset',
					'The chunk does not start where the upload stands.',
					[
						'status'   => 409,
						'received' => (int) $e->getMessage(),
					]
				);
			}
			return self::migration_error( $e );
		}
		return new WP_REST_Response( [ 'received' => $received ], 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function abort_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			( new Uploads() )->abort( (string) $request->get_param( 'token' ), get_current_user_id() );
		} catch ( MigrationError $e ) {
			return self::migration_error( $e );
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * A job from a finished upload. The whole file is checked here, so a
	 * broken one is deleted and no job is created.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$options = $request->get_param( 'options' );
		$options = is_array( $options ) ? $options : [];
		try {
			$file = ( new Uploads() )->finish( (string) $request->get_param( 'upload' ), get_current_user_id() );
		} catch ( MigrationError $e ) {
			return self::migration_error( $e );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reading a large file once; disabled on some hosts.
		}
		$options['file_name'] = $file['name'];
		try {
			$job = self::service()->create_from_file( $file['path'], $options, (bool) $request->get_param( 'dry_run' ), get_current_user_id() );
		} catch ( MigrationError $e ) {
			Uploads::remove( $file['path'] );
			return self::migration_error( $e );
		}
		return new WP_REST_Response( self::payload( $job ), 201 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function remove_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::respond( fn (): Job => self::service()->remove_file( self::id( $request ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::respond( fn (): Job => self::service()->find( self::id( $request ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function log( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$page = self::service()->log(
				self::service()->find( self::id( $request ) )->id,
				(string) $request->get_param( 'level' ),
				(int) $request->get_param( 'page' ),
				(int) $request->get_param( 'per_page' )
			);
		} catch ( MigrationError $e ) {
			return self::migration_error( $e );
		}
		$response = new WP_REST_Response( [ 'items' => $page['rows'] ], 200 );
		$response->header( 'X-WP-Total', (string) $page['total'] );
		return $response;
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function preflight( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::respond( fn (): Job => self::service()->recheck( self::id( $request ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = self::id( $request );
		return self::respond(
			static function () use ( $id, $request ): Job {
				if ( (bool) $request->get_param( 'backup_confirmed' ) ) {
					self::service()->confirm_backup( $id );
				}
				return self::service()->start( $id );
			}
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = self::id( $request );
		try {
			self::service()->find( $id );
			$result = self::service()->run_batch( $id );
		} catch ( MigrationError $e ) {
			return self::migration_error( $e );
		}
		$job = $result['job'] ?? self::service()->find( $id );
		return new WP_REST_Response(
			[
				'job'       => self::payload( $job ),
				'processed' => $result['processed'],
				'done'      => $result['done'],
				'locked'    => $result['locked'],
			],
			200
		);
	}

	/**
	 * Hand a running job to the background driver, so it continues after
	 * the screen is closed.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function background( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::respond(
			static function () use ( $request ): Job {
				$job = self::service()->find( self::id( $request ) );
				if ( ! in_array( $job->status, [ JobStatus::RUNNING, JobStatus::VERIFYING ], true ) ) {
					throw new MigrationError( 'invalid_status', 'Only a running job can continue in the background.' );
				}
				Plugin::instance()->migration()->background()->dispatch( $job->id );
				return $job;
			}
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function pause( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::respond( fn (): Job => self::service()->pause( self::id( $request ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = self::id( $request );
		return self::respond(
			static function () use ( $id ): Job {
				$job = self::service()->cancel( $id );
				Plugin::instance()->migration()->background()->cancel( $id );
				return $job;
			}
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function verify( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::respond( fn (): Job => self::service()->verify( self::id( $request ) ) );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function purge( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$result = self::service()->purge( self::id( $request ) );
		} catch ( MigrationError $e ) {
			return self::migration_error( $e );
		}
		return new WP_REST_Response( [ 'purged' => $result ], 200 );
	}

	private static function service(): MigrationService {
		return Plugin::instance()->migration()->service();
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	private static function id( WP_REST_Request $request ): int {
		return (int) $request->get_param( 'id' );
	}

	/** @param callable():Job $work */
	private static function respond( callable $work, int $status = 200 ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( self::payload( $work() ), $status );
		} catch ( MigrationError $e ) {
			return self::migration_error( $e );
		}
	}

	private static function migration_error( MigrationError $e ): WP_Error {
		$status = match ( $e->reason ) {
			'not_found'                                                   => 404,
			'invalid_status', 'backup_required', 'upload_incomplete', 'file_missing' => 409,
			'direction_unavailable', 'invalid_direction', 'invalid_file'  => 400,
			'disk_full'                                                   => 507,
			default                                                       => 500,
		};
		return new WP_Error( 'flexacommerce_migration_' . $e->reason, $e->getMessage(), [ 'status' => $status ] );
	}

	/**
	 * The job, plus what the screen shows of its file: the server path stays
	 * in the options for WP-CLI.
	 *
	 * @return array<string,mixed>
	 */
	private static function payload( Job $job ): array {
		$out = $job->to_array();
		if ( $job->is_file() ) {
			$path        = (string) $job->option( 'file' );
			$exists      = is_file( $path );
			$name        = (string) $job->option( 'file_name' );
			$out['file'] = [
				'name'      => $name !== '' ? $name : wp_basename( $path ),
				'size'      => $exists ? (int) filesize( $path ) : 0,
				'exists'    => $exists,
				'removable' => $exists && Uploads::owns( $path ),
			];
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private static function summary( Job $job ): array {
		$out = self::payload( $job );
		unset( $out['cursor'], $out['report'] );
		return $out;
	}
}
