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
 */
final class MigrationsEndpoint extends Endpoint {
	private const ID = '/admin/migrations/(?P<id>\d+)';

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
				'job'       => $job->to_array(),
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
			return new WP_REST_Response( $work()->to_array(), $status );
		} catch ( MigrationError $e ) {
			return self::migration_error( $e );
		}
	}

	private static function migration_error( MigrationError $e ): WP_Error {
		$status = match ( $e->reason ) {
			'not_found'                                 => 404,
			'invalid_status', 'backup_required'         => 409,
			'direction_unavailable', 'invalid_direction' => 400,
			default                                     => 500,
		};
		return new WP_Error( 'flexacommerce_migration_' . $e->reason, $e->getMessage(), [ 'status' => $status ] );
	}

	/** @return array<string,mixed> */
	private static function summary( Job $job ): array {
		$out = $job->to_array();
		unset( $out['cursor'], $out['report'] );
		return $out;
	}
}
