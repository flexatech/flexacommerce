<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cli;

use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationError;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Migration jobs from the command line (doc 05 §4.4, §4.5), the driver for
 * stores with more than 10,000 orders.
 *
 *   wp flexacommerce migrate preflight [--direction=<d>] [--stages=<list>]
 *   wp flexacommerce migrate run [<job>] [--direction=<d>] [--dry-run] [--yes-i-have-a-backup] [--stages=<list>]
 *   wp flexacommerce migrate status <job> [--format=<format>]
 *   wp flexacommerce migrate list [--format=<format>]
 *   wp flexacommerce migrate log <job> [--level=<level>] [--format=<format>]
 *   wp flexacommerce migrate verify <job> [--format=<format>]
 *   wp flexacommerce migrate pause <job>
 *   wp flexacommerce migrate cancel <job>
 *   wp flexacommerce migrate purge <job> [--yes]
 *   wp flexacommerce migrate cutover [<step>] [--job=<id>] [--pages=<mode>] [--acknowledge-warnings] [--accept-failures]
 *   wp flexacommerce migrate export <file> [--from=<store>] [--stages=<list>] [--orders-from=<date>] [--orders-to=<date>]
 *   wp flexacommerce migrate import <file> [--stages=<list>] [--dry-run] [--yes-i-have-a-backup] [--media=<policy>]
 */
final class MigrateCommand {
	/** CLI flag => job option. */
	private const DATE_FLAGS = [
		'orders-from' => 'orders_from',
		'orders-to'   => 'orders_to',
	];

	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		$instance = new self();
		foreach ( [ 'preflight', 'run', 'status', 'list', 'log', 'verify', 'pause', 'cancel', 'purge', 'cutover', 'export', 'import' ] as $command ) {
			WP_CLI::add_command( 'flexacommerce migrate ' . $command, [ $instance, $command === 'list' ? 'list_jobs' : $command ] );
		}
	}

	/**
	 * Creates a dry-run job and prints its preflight report. Exits with code 1 on errors.
	 *
	 * ## OPTIONS
	 *
	 * [--direction=<direction>]
	 * : wc_to_fxc or fxc_to_wc.
	 * ---
	 * default: wc_to_fxc
	 * ---
	 *
	 * [--stages=<stages>]
	 * : Comma-separated stages: settings, taxes, shipping, attributes, terms, products, customers, orders, coupons.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function preflight( array $args, array $assoc_args ): void {
		unset( $args );
		$job = self::attempt( fn (): Job => self::service()->create( self::direction( $assoc_args ), self::options( $assoc_args ), true, get_current_user_id() ) );
		WP_CLI::log( sprintf( 'Job %d: %s', $job->id, $job->status ) );
		self::print_problems( $job, $assoc_args['format'] ?? 'table' );
		if ( $job->status !== JobStatus::READY ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Runs a job batch by batch until it finishes. Without <job> a new job is
	 * created first. A finished job runs again as an incremental re-run.
	 *
	 * ## OPTIONS
	 *
	 * [<job>]
	 * : Job id to start or resume.
	 *
	 * [--direction=<direction>]
	 * : For a new job: wc_to_fxc or fxc_to_wc.
	 * ---
	 * default: wc_to_fxc
	 * ---
	 *
	 * [--dry-run]
	 * : For a new job: read and validate everything, write only the job and its log.
	 *
	 * [--stages=<stages>]
	 * : For a new job: comma-separated stages.
	 *
	 * [--orders-from=<date>]
	 * : For a new job: only orders created on or after this date (Y-m-d).
	 *
	 * [--orders-to=<date>]
	 * : For a new job: only orders created before this date (Y-m-d).
	 *
	 * [--yes-i-have-a-backup]
	 * : Confirms a current database backup. Required for a real run.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>                $args
	 * @param array<string,string|bool>        $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$service = self::service();
		if ( isset( $args[0] ) ) {
			$job = self::attempt( fn (): Job => $service->find( (int) $args[0] ) );
		} else {
			$job = self::attempt( fn (): Job => $service->create( self::direction( $assoc_args ), self::options( $assoc_args ), (bool) ( $assoc_args['dry-run'] ?? false ), get_current_user_id() ) );
			WP_CLI::log( sprintf( 'Created job %d (%s).', $job->id, $job->status ) );
			if ( $job->status !== JobStatus::READY ) {
				self::print_problems( $job, 'table' );
				WP_CLI::error( 'The preflight found errors.' );
			}
		}
		if ( ! empty( $assoc_args['yes-i-have-a-backup'] ) ) {
			$job = $service->confirm_backup( $job->id );
		}
		if ( $job->status !== JobStatus::RUNNING && $job->status !== JobStatus::VERIFYING ) {
			$job = self::attempt( fn (): Job => $service->start( $job->id ) );
		}

		$started   = microtime( true );
		$processed = 0;
		$batches   = 0;
		$peak      = 0;
		$growth    = 0;
		$job       = $service->run(
			$job->id,
			static function ( array $result ) use ( &$processed, &$batches, &$peak, &$growth, $started ): void {
				++$batches;
				$processed += $result['processed'];
				$peak       = max( $peak, $result['peak'] );
				$growth     = max( $growth, $result['growth'] );
				$current    = $result['job'];
				if ( $result['locked'] ) {
					WP_CLI::warning( 'Another runner holds the lease on this job. Try again in a minute.' );
					return;
				}
				WP_CLI::log(
					sprintf(
						'batch %d  %-10s %7d entities  %6.0f/s  batch peak %s, growth %s',
						$batches,
						$current instanceof Job ? $current->stage : '',
						$processed,
						$processed / max( 0.001, microtime( true ) - $started ),
						size_format( $peak, 1 ),
						size_format( $growth, 1 )
					)
				);
			}
		);

		$seconds = microtime( true ) - $started;
		self::print_totals( $job );
		WP_CLI::log( sprintf( '%d entities in %.1f s (%.0f/s), highest batch peak memory %s, largest growth in a batch %s.', $processed, $seconds, $processed / max( 0.001, $seconds ), size_format( $peak, 1 ), size_format( $growth, 1 ) ) );
		match ( $job->status ) {
			JobStatus::COMPLETED               => WP_CLI::success( sprintf( 'Job %d completed.', $job->id ) ),
			JobStatus::COMPLETED_WITH_WARNINGS => WP_CLI::success( sprintf( 'Job %d completed with warnings. See `wp flexacommerce migrate log %d`.', $job->id, $job->id ) ),
			default                            => WP_CLI::error( sprintf( 'Job %d is %s. See `wp flexacommerce migrate log %d --level=error`.', $job->id, $job->status, $job->id ) ),
		};
	}

	/**
	 * Shows a job: status, stage and counters.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$job = self::attempt( fn (): Job => self::service()->find( (int) ( $args[0] ?? 0 ) ) );
		if ( ( $assoc_args['format'] ?? 'table' ) === 'json' ) {
			WP_CLI::line( (string) wp_json_encode( $job->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		WP_CLI::log( sprintf( 'Job %d  %s  %s%s  stage: %s', $job->id, $job->direction, $job->status, $job->dry_run ? ' (dry run)' : '', $job->stage !== '' ? $job->stage : '-' ) );
		self::print_totals( $job, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Lists recent jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function list_jobs( array $args, array $assoc_args ): void {
		unset( $args );
		$rows = [];
		foreach ( self::service()->recent( 50 ) as $job ) {
			$rows[] = [
				'id'          => $job->id,
				'direction'   => $job->direction,
				'status'      => $job->status,
				'dry_run'     => $job->dry_run ? 'yes' : 'no',
				'created_at'  => $job->created_at,
				'finished_at' => $job->finished_at ?? '',
			];
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'id', 'direction', 'status', 'dry_run', 'created_at', 'finished_at' ] );
	}

	/**
	 * Prints the log of a job.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * [--level=<level>]
	 * : info, warning or error.
	 *
	 * [--page=<page>]
	 * : Page of 500 rows.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function log( array $args, array $assoc_args ): void {
		$service = self::service();
		$job     = self::attempt( fn (): Job => $service->find( (int) ( $args[0] ?? 0 ) ) );
		$page    = $service->log( $job->id, (string) ( $assoc_args['level'] ?? '' ), max( 1, (int) ( $assoc_args['page'] ?? 1 ) ), 500 );
		$rows    = [];
		foreach ( $page['rows'] as $row ) {
			$rows[] = [
				'level'     => $row['level'],
				'entity'    => $row['entity'],
				'source_id' => $row['source_id'],
				'code'      => $row['code'],
				'message'   => $row['message'],
			];
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'level', 'entity', 'source_id', 'code', 'message' ] );
		WP_CLI::log( sprintf( '%d of %d rows.', count( $rows ), $page['total'] ) );
	}

	/**
	 * Builds the verification report of a finished job again.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function verify( array $args, array $assoc_args ): void {
		$job    = self::attempt( fn (): Job => self::service()->verify( (int) ( $args[0] ?? 0 ) ) );
		$report = $job->report;
		unset( $report['preflight'] );
		if ( ( $assoc_args['format'] ?? 'table' ) === 'json' ) {
			WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$mismatches = is_array( $report['mismatches'] ?? null ) ? $report['mismatches'] : [];
		WP_CLI::log( sprintf( 'Checked %d orders, %d mismatches.', (int) ( $report['checked'] ?? 0 ), count( $mismatches ) ) );
		if ( $mismatches !== [] ) {
			\WP_CLI\Utils\format_items( 'table', array_map( static fn ( $m ): array => (array) $m, $mismatches ), array_keys( (array) reset( $mismatches ) ) );
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( sprintf( 'Job %d is %s.', $job->id, $job->status ) );
	}

	/**
	 * Pauses a running job after its current batch.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function pause( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$job = self::attempt( fn (): Job => self::service()->pause( (int) ( $args[0] ?? 0 ) ) );
		WP_CLI::success( sprintf( 'Job %d is paused.', $job->id ) );
	}

	/**
	 * Cancels a job. What it imported stays; use purge to remove it.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function cancel( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$job = self::attempt( fn (): Job => self::service()->cancel( (int) ( $args[0] ?? 0 ) ) );
		WP_CLI::success( sprintf( 'Job %d is cancelled.', $job->id ) );
	}

	/**
	 * Deletes what a job created on its target, except entities edited after
	 * the import. Settings, tax rates and customers stay.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>         $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function purge( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		WP_CLI::confirm( sprintf( 'Delete the entities job %d created? This cannot be undone.', $id ), $assoc_args );
		$result = self::attempt( fn (): array => self::service()->purge( $id ) );
		$rows   = [];
		foreach ( $result as $entity => $counts ) {
			$rows[] = [ 'entity' => $entity ] + $counts;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'entity', 'deleted', 'kept' ] );
		WP_CLI::success( sprintf( 'Job %d purged.', $id ) );
	}

	/**
	 * Runs one step of the cutover wizard (doc 05 §9.2), or shows where it
	 * stands. Deactivating WooCommerce between "pages" and "urls" is yours to do.
	 *
	 * ## OPTIONS
	 *
	 * [<step>]
	 * : freeze, verify, bases, pages, urls, redirects, reopen or rollback. Leave out to show the steps.
	 *
	 * [--job=<id>]
	 * : The verified job, for the verify step.
	 *
	 * [--pages=<mode>]
	 * : flexacommerce (keep our pages) or woocommerce (reuse theirs), for the pages step.
	 *
	 * [--acknowledge-warnings]
	 * : Let the verify step pass a job that logged warnings.
	 *
	 * [--accept-failures]
	 * : Let the urls step pass when a sample URL failed.
	 *
	 * [--yes]
	 * : Skip the confirmation of rollback.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>         $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function cutover( array $args, array $assoc_args ): void {
		$cutover = Plugin::instance()->migration()->cutover();
		$step    = (string) ( $args[0] ?? '' );
		if ( $step === 'rollback' ) {
			WP_CLI::confirm( 'Put back the settings and pages the cutover changed?', $assoc_args );
		}
		$status = self::attempt(
			fn (): array => match ( $step ) {
				''          => $cutover->status(),
				'freeze'    => $cutover->freeze(),
				'verify'    => $cutover->verify( (int) ( $assoc_args['job'] ?? 0 ), ! empty( $assoc_args['acknowledge-warnings'] ) ),
				'bases'     => $cutover->adopt_bases(),
				'pages'     => $cutover->assign_pages( (string) ( $assoc_args['pages'] ?? 'flexacommerce' ) ),
				'urls'      => $cutover->check_urls( ! empty( $assoc_args['accept-failures'] ) ),
				'redirects' => $cutover->enable_redirects(),
				'reopen'    => $cutover->reopen(),
				'rollback'  => $cutover->rollback(),
				default     => throw new MigrationError( 'cutover_step', sprintf( 'Unknown step "%s".', $step ) ),
			}
		);
		$rows   = [];
		foreach ( (array) $status['steps'] as $row ) {
			$rows[] = [
				'step' => $row['key'],
				'done' => $row['done'] ? 'yes' : 'no',
				'at'   => $row['at'] ?? '',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'step', 'done', 'at' ] );
		if ( $step !== '' ) {
			WP_CLI::success( sprintf( 'Cutover step "%s" done.', $step ) );
		}
	}

	/**
	 * Writes the WooCommerce or FlexaCommerce store of this site to a JSONL
	 * file that `migrate import` reads on another site. The file holds
	 * customer names, emails and addresses: keep it private and delete it
	 * after the import.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path of the file to write.
	 *
	 * [--from=<store>]
	 * : wc or fxc.
	 * ---
	 * default: wc
	 * ---
	 *
	 * [--stages=<stages>]
	 * : Comma-separated stages.
	 *
	 * [--orders-from=<date>]
	 * : Only orders created on or after this date (Y-m-d).
	 *
	 * [--orders-to=<date>]
	 * : Only orders created before this date (Y-m-d).
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>         $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function export( array $args, array $assoc_args ): void {
		$path = (string) ( $args[0] ?? '' );
		if ( $path === '' ) {
			WP_CLI::error( 'Give the path of the file to write.' );
		}
		$started = microtime( true );
		$result  = self::attempt(
			fn (): array => Plugin::instance()->migration()->export(
				(string) ( $assoc_args['from'] ?? 'wc' ),
				self::options( $assoc_args ),
				$path,
				static function ( string $stage, int $written ): void {
					WP_CLI::log( sprintf( '%-10s %7d records', $stage, $written ) );
				}
			)
		);
		$rows    = [];
		foreach ( $result['counts'] as $stage => $count ) {
			$rows[] = [
				'stage'   => $stage,
				'records' => $count,
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'stage', 'records' ] );
		foreach ( $result['rejected'] as $rejected ) {
			WP_CLI::warning( sprintf( '%1$s %2$s left out: %3$s', $rejected['stage'], $rejected['id'], $rejected['message'] ) );
		}
		WP_CLI::success( sprintf( 'Wrote %1$s in %2$.1f s with %3$d users and %4$d images. It holds personal data: keep it private and delete it after the import.', $path, microtime( true ) - $started, $result['users'], $result['media'] ) );
	}

	/**
	 * Imports a file written by `migrate export` on another site. A WooCommerce
	 * export goes into FlexaCommerce, a FlexaCommerce export into WooCommerce.
	 * Customers are matched by email or created, images are downloaded from
	 * the source site. Running the same file again updates what changed.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path of the export file.
	 *
	 * [--stages=<stages>]
	 * : Comma-separated stages.
	 *
	 * [--dry-run]
	 * : Read and validate everything, write only the job and its log.
	 *
	 * [--media=<policy>]
	 * : download or skip.
	 * ---
	 * default: download
	 * ---
	 *
	 * [--yes-i-have-a-backup]
	 * : Confirms a current database backup. Required for a real run.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>         $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$options          = self::options( $assoc_args );
		$options['media'] = ( $assoc_args['media'] ?? 'download' ) === 'skip' ? 'skip' : 'download';
		$job              = self::attempt( fn (): Job => self::service()->create_from_file( (string) ( $args[0] ?? '' ), $options, (bool) ( $assoc_args['dry-run'] ?? false ), get_current_user_id() ) );
		WP_CLI::log( sprintf( 'Created job %1$d (%2$s, %3$s).', $job->id, $job->direction, $job->status ) );
		if ( $job->status !== JobStatus::READY ) {
			self::print_problems( $job, 'table' );
			WP_CLI::error( 'The preflight found errors.' );
		}
		$this->run( [ (string) $job->id ], $assoc_args );
	}

	private static function service(): MigrationService {
		return Plugin::instance()->migration()->service();
	}

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	private static function attempt( callable $work ): mixed {
		try {
			return $work();
		} catch ( MigrationError $e ) {
			WP_CLI::error( sprintf( '%s (%s)', $e->getMessage(), $e->reason ) );
			throw $e; // WP_CLI::error() exits; this only runs when a caller made it return.
		}
	}

	/** @param array<string,string|bool> $assoc_args */
	private static function direction( array $assoc_args ): string {
		$direction = (string) ( $assoc_args['direction'] ?? Direction::WC_TO_FXC );
		return str_replace( '-', '_', $direction );
	}

	/**
	 * @param array<string,string|bool> $assoc_args
	 * @return array<string,mixed>
	 */
	private static function options( array $assoc_args ): array {
		$options = [];
		if ( isset( $assoc_args['stages'] ) && is_string( $assoc_args['stages'] ) ) {
			$options['stages'] = array_map( 'trim', explode( ',', $assoc_args['stages'] ) );
		}
		foreach ( self::DATE_FLAGS as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) && is_string( $assoc_args[ $flag ] ) ) {
				$options[ $key ] = $assoc_args[ $flag ];
			}
		}
		if ( ! empty( $assoc_args['yes-i-have-a-backup'] ) ) {
			$options['backup_confirmed'] = true;
		}
		return $options;
	}

	private static function print_problems( Job $job, string $format ): void {
		$rows = [];
		foreach ( (array) ( $job->report['preflight'] ?? [] ) as $problem ) {
			$rows[] = [
				'level'   => $problem['level'],
				'code'    => $problem['code'],
				'message' => $problem['message'],
				'context' => $problem['context'] === [] ? '' : (string) wp_json_encode( $problem['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			];
		}
		\WP_CLI\Utils\format_items( $format, $rows, [ 'level', 'code', 'message', 'context' ] );
	}

	private static function print_totals( Job $job, string $format = 'table' ): void {
		$names = [ 'created', 'updated', 'unchanged', 'skipped', 'rejected', 'warnings' ];
		$rows  = [];
		foreach ( $job->counters as $stage => $counters ) {
			$row = [ 'stage' => $stage ];
			foreach ( $names as $name ) {
				$row[ $name ] = (int) ( $counters[ $name ] ?? 0 );
			}
			$rows[] = $row;
		}
		\WP_CLI\Utils\format_items( $format, $rows, [ 'stage', ...$names ] );
	}
}
