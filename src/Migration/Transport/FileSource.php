<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Transport;

use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\Sources\SourceAdapter;

defined( 'ABSPATH' ) || exit;

/** A JSONL export as the source of a job (doc 05 §4.6). */
final class FileSource implements SourceAdapter {
	public function __construct( private readonly FileIndex $index ) {}

	public function system(): string {
		return $this->index->system();
	}

	public function index(): FileIndex {
		return $this->index;
	}

	/** Users and attachments of the source site for this job's Mapping. */
	public function relocation( Job $job ): Relocation {
		return new Relocation( $this->index, $job->option( 'media' ) !== 'skip' );
	}

	public function upper_id( string $stage, Job $job ): int {
		return $this->index->last_id( $stage );
	}

	public function ids( string $stage, int $after, int $upper, int $limit, Job $job ): array {
		return $this->index->ids( $stage, $after, $upper, $limit );
	}

	public function read( string $stage, int $id, Job $job ): ?Record {
		$line = $this->index->line( $stage, $id );
		return $line === null ? null : self::record( $stage, $line );
	}

	public function read_ref( string $ref, Job $job ): ?Record {
		$parsed = Ref::parse( $ref );
		$stage  = $parsed === null ? null : Job::stage_of( $parsed->entity );
		if ( $parsed === null || $stage === null || $parsed->int() <= 0 ) {
			return null;
		}
		$line = $this->index->line( $stage, $parsed->int() );
		return $line === null || ( $line['id'] ?? null ) !== $ref ? null : self::record( $stage, $line );
	}

	public function count( string $stage, Job $job ): int {
		return $this->index->count( $stage );
	}

	public function preflight( Job $job ): array {
		$header  = $this->index->header();
		$version = (string) ( $header['format_version'] ?? '' );
		if ( (int) explode( '.', $version )[0] !== (int) explode( '.', Record::FORMAT_VERSION )[0] ) {
			return [ Problem::error( 'file_version', sprintf( 'The file uses format %1$s; this version of FlexaCommerce reads format %2$s.', $version, Record::FORMAT_VERSION ) ) ];
		}
		if ( $this->index->system() !== $job->source() ) {
			return [ Problem::error( 'file_direction', sprintf( 'The file was exported from %1$s, but the job reads from %2$s.', $this->index->system(), $job->source() ) ) ];
		}
		if ( $this->index->id_system() !== $job->id_system() ) {
			return [ Problem::error( 'file_changed', 'The file now comes from a different site than when the job was created. Create a new job for it.' ) ];
		}
		$problems = [];
		$exported = is_array( $header['stages'] ?? null ) ? $header['stages'] : [];
		$missing  = array_values( array_diff( $job->stages(), $exported ) );
		if ( $missing !== [] ) {
			$problems[] = Problem::warning( 'file_stage_missing', sprintf( 'The file has no %s; those stages import nothing.', implode( ', ', $missing ) ), [ 'stages' => $missing ] );
		}
		$site       = is_array( $header['site'] ?? null ) ? $header['site'] : [];
		$problems[] = Problem::info(
			'file_source',
			sprintf( 'Export of %1$s made %2$s.', (string) ( $site['url'] ?? '' ), (string) ( $header['created_at'] ?? '' ) ),
			[
				'site'       => (string) ( $site['url'] ?? '' ),
				'created_at' => (string) ( $header['created_at'] ?? '' ),
				'plugin'     => (string) ( $header['plugin_version'] ?? '' ),
				'users'      => $this->index->count( FileIndex::USER ),
				'media'      => $this->index->count( FileIndex::MEDIA ),
			]
		);
		if ( $job->option( 'media' ) !== 'skip' && $this->index->count( FileIndex::MEDIA ) > 0 ) {
			$problems[] = Problem::info( 'media_download', sprintf( '%1$d images will be downloaded from %2$s while the job runs.', $this->index->count( FileIndex::MEDIA ), (string) ( $site['url'] ?? '' ) ) );
		}
		return $problems;
	}

	/** @param array<string,mixed> $line */
	private static function record( string $stage, array $line ): Record {
		$type = $line['type'] ?? null;
		$id   = (string) ( $line['id'] ?? '' );
		if ( $type !== Job::entity( $stage ) || ! is_array( $line['data'] ?? null ) ) {
			throw new Rejected( 'invalid_line', sprintf( 'The line for %s is not a %s record.', $id, Job::entity( $stage ) ) );
		}
		$depends = is_array( $line['depends'] ?? null ) ? array_values( array_filter( $line['depends'], 'is_string' ) ) : [];
		/** @var array<string,mixed> $data */
		$data   = $line['data'];
		$record = new Record( $type, $id, $data, $depends );
		if ( is_string( $line['checksum'] ?? null ) && $line['checksum'] !== $record->checksum ) {
			throw new Rejected( 'checksum_mismatch', sprintf( 'The record %s was changed after the export.', $id ) );
		}
		return $record;
	}
}
