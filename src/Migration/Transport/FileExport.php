<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Transport;

use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationError;
use Flexa\Commerce\Migration\Sources\SourceAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one store as a JSONL file for another site (doc 05 §4.6): a header
 * line, then the canonical records stage by stage. A record's dependency
 * that the file would otherwise hold later, or not at all, is written first.
 * The users and images records point at follow as `user` and `media` lines
 * before the first record that needs them.
 */
final class FileExport {
	public const SITE_OPTION = 'flexacommerce_site_id';

	private const JSON      = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
	private const BATCH     = 200;
	private const MAX_DEPTH = 20;
	private const SIDE_REF  = '/^wp:(user|attachment):(\d+)$/';

	/** @var resource|null */
	private $handle = null;

	private ?Job $job = null;

	/** @var array<string,true> record ids written ahead of their stage */
	private array $early = [];

	/** @var array<string,array<int,true>> side lines written, by section */
	private array $sides = [];

	/** @var array<string,int> */
	private array $counts = [];

	/** @var list<array{stage:string,id:string,message:string}> */
	private array $rejected = [];

	/** @var list<string> */
	private array $stages = [];

	private string $stage = '';
	private int $cursor   = 0;

	public function __construct( private readonly SourceAdapter $source ) {}

	/**
	 * A job that is never saved, to hand the readers their options.
	 *
	 * @param array<string,mixed> $options
	 */
	public static function job( string $direction, array $options ): Job {
		$now = gmdate( 'Y-m-d H:i:s' );
		return Job::from_row(
			[
				'id'           => 0,
				'direction'    => $direction,
				'status'       => JobStatus::DRAFT,
				'dry_run'      => 1,
				'options'      => (string) wp_json_encode( $options ),
				'stage'        => '',
				'cursor_state' => '{}',
				'counters'     => '{}',
				'created_by'   => 0,
				'report'       => '{}',
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
	}

	/** Stable id of this site, made on the first export; the importing site keys its id map by it. */
	public static function site_id(): string {
		$id = get_option( self::SITE_OPTION );
		if ( ! is_string( $id ) || $id === '' ) {
			$id = wp_generate_uuid4();
			add_option( self::SITE_OPTION, $id, '', false );
		}
		return $id;
	}

	/**
	 * @param (callable(string,int):void)|null $progress stage and records written so far
	 * @return array{counts:array<string,int>,rejected:list<array{stage:string,id:string,message:string}>,users:int,media:int}
	 */
	public function export( Job $job, string $path, ?callable $progress = null ): array {
		$partial = $path . '.part';
		$handle  = fopen( $partial, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed write of a local file.
		if ( $handle === false ) {
			throw new MigrationError( 'file_unwritable', sprintf( 'Could not write %s.', $path ) );
		}
		$this->handle   = $handle;
		$this->job      = $job;
		$this->stages   = $job->stages();
		$this->early    = [];
		$this->sides    = [];
		$this->counts   = array_fill_keys( $this->stages, 0 );
		$this->rejected = [];
		try {
			$this->line(
				[
					'type'           => 'header',
					'format_version' => Record::FORMAT_VERSION,
					'system'         => $this->source->system(),
					'site'           => [
						'id'  => self::site_id(),
						'url' => home_url( '/' ),
					],
					'created_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'stages'         => $this->stages,
					'plugin_version' => defined( 'FLEXACOMMERCE_VERSION' ) ? (string) FLEXACOMMERCE_VERSION : '',
				]
			);
			foreach ( $this->stages as $stage ) {
				$this->stage  = $stage;
				$this->cursor = 0;
				$upper        = $this->source->upper_id( $stage, $job );
				while ( true ) {
					$ids = $this->source->ids( $stage, $this->cursor, $upper, self::BATCH, $job );
					if ( $ids === [] ) {
						break;
					}
					foreach ( $ids as $id ) {
						$this->cursor = $id;
						$this->entity( $stage, $id );
					}
					if ( function_exists( 'wp_cache_flush_runtime' ) ) {
						wp_cache_flush_runtime();
					}
					if ( $progress !== null ) {
						$progress( $stage, $this->counts[ $stage ] );
					}
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed write of a local file.
			$this->handle = null;
		}
		if ( ! rename( $partial, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- finished export replaces the target path.
			throw new MigrationError( 'file_unwritable', sprintf( 'Could not move the export to %s.', $path ) );
		}
		return [
			'counts'   => $this->counts,
			'rejected' => $this->rejected,
			'users'    => count( $this->sides[ FileIndex::USER ] ?? [] ),
			'media'    => count( $this->sides[ FileIndex::MEDIA ] ?? [] ),
		];
	}

	private function entity( string $stage, int $id ): void {
		try {
			$record = $this->source->read( $stage, $id, $this->current() );
		} catch ( Rejected $e ) {
			$this->rejected[] = [
				'stage'   => $stage,
				'id'      => (string) $id,
				'message' => $e->getMessage(),
			];
			return;
		}
		if ( $record !== null && ! isset( $this->early[ $record->id ] ) ) {
			$this->record( $record, 0 );
		}
	}

	private function record( Record $record, int $depth ): void {
		foreach ( $record->depends as $ref ) {
			$this->dependency( $ref, $depth + 1 );
		}
		$this->sides( $record );
		$line = [
			'type'     => $record->type,
			'id'       => $record->id,
			'checksum' => $record->checksum,
		];
		if ( $record->depends !== [] ) {
			$line['depends'] = $record->depends;
		}
		$line['data'] = $record->data;
		$this->line( $line );
		$stage = Job::stage_of( $record->type );
		if ( $stage !== null ) {
			$this->counts[ $stage ] = ( $this->counts[ $stage ] ?? 0 ) + 1;
		}
	}

	/** Writes a dependency now unless its own stage already wrote it. */
	private function dependency( string $ref, int $depth ): void {
		$parsed = Ref::parse( $ref );
		if ( $parsed === null || $parsed->system !== $this->source->system() || isset( $this->early[ $ref ] ) || $depth > self::MAX_DEPTH ) {
			return;
		}
		$stage = Job::stage_of( $parsed->entity );
		if ( $stage === null ) {
			return;
		}
		$position = array_search( $stage, $this->stages, true );
		$current  = array_search( $this->stage, $this->stages, true );
		if ( $position !== false && ( $position < $current || ( $position === $current && $parsed->int() <= $this->cursor ) ) ) {
			return;
		}
		try {
			$record = $this->source->read_ref( $ref, $this->current() );
		} catch ( Rejected $e ) {
			$this->rejected[] = [
				'stage'   => $stage,
				'id'      => $ref,
				'message' => $e->getMessage(),
			];
			return;
		}
		$this->early[ $ref ] = true;
		if ( $record !== null ) {
			$this->record( $record, $depth );
		}
	}

	/** User and media lines for what a record points at, before the record. */
	private function sides( Record $record ): void {
		$refs = [];
		$data = $record->data;
		array_walk_recursive(
			$data,
			static function ( mixed $value ) use ( &$refs ): void {
				if ( is_string( $value ) && preg_match( self::SIDE_REF, $value, $match ) === 1 ) {
					$refs[] = [ $match[1], (int) $match[2] ];
				}
			}
		);
		if ( preg_match( self::SIDE_REF, $record->id, $match ) === 1 ) {
			$refs[] = [ $match[1], (int) $match[2] ];
		}
		if ( $record->type === Record::PRODUCT && (int) ( $record->data['author'] ?? 0 ) > 0 ) {
			$refs[] = [ 'user', (int) $record->data['author'] ];
		}
		foreach ( $refs as [ $kind, $id ] ) {
			$section = $kind === 'user' ? FileIndex::USER : FileIndex::MEDIA;
			if ( isset( $this->sides[ $section ][ $id ] ) ) {
				continue;
			}
			$this->sides[ $section ][ $id ] = true;
			$data                           = $section === FileIndex::USER ? self::user( $id ) : self::media( $id );
			if ( $data !== null ) {
				$this->line(
					[
						'type' => $section,
						'id'   => Ref::make( 'wp', $kind, $id ),
						'data' => $data,
					]
				);
			}
		}
	}

	/** @return array<string,string>|null */
	private static function user( int $id ): ?array {
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}
		return [
			'email'        => $user->user_email,
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'registered'   => $user->user_registered,
		];
	}

	/** @return array<string,string>|null */
	private static function media( int $id ): ?array {
		$post = get_post( $id );
		$url  = wp_get_attachment_url( $id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' || ! is_string( $url ) ) {
			return null;
		}
		$file = get_attached_file( $id );
		return [
			'url'         => $url,
			'title'       => $post->post_title,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'mime'        => $post->post_mime_type,
			'filename'    => is_string( $file ) ? wp_basename( $file ) : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
		];
	}

	/** @param array<string,mixed> $row */
	private function line( array $row ): void {
		if ( $this->handle === null ) {
			throw new \LogicException( 'The export has not started.' );
		}
		fwrite( $this->handle, json_encode( $row, self::JSON ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed write of a local file, json_encode for its flags.
	}

	private function current(): Job {
		return $this->job ?? throw new \LogicException( 'The export has not started.' );
	}

	/** Direction of a job that reads a file exported from `$system`. */
	public static function direction( string $system ): string {
		return $system === 'fxc' ? Direction::FXC_TO_WC : Direction::WC_TO_FXC;
	}
}
