<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Transport;

use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\MigrationError;

defined( 'ABSPATH' ) || exit;

/**
 * Byte offsets of the lines of a JSONL export (doc 05 §4.6), kept in a
 * sidecar `<file>.index` so a batch seeks to its records instead of reading
 * the file from the top. The sidecar is one JSON line (file size and mtime,
 * the header, each section's [start, count]) followed by 16-byte entries,
 * `pack('J2', id, offset)`, sorted by id within each section. Sections are
 * the stages plus `user` and `media`. A sidecar that does not match the file
 * is built again.
 */
final class FileIndex {
	public const VERSION = 1;

	/** Side sections: the users and attachments records point at. */
	public const USER  = 'user';
	public const MEDIA = 'media';

	private const ENTRY = 16;

	/** @var resource|null */
	private $entries = null;

	/** @var resource|null */
	private $lines = null;

	/**
	 * @param array<string,mixed>                   $header
	 * @param array<string,array{0:int,1:int}>      $sections name => [first entry, count]
	 */
	private function __construct(
		private readonly string $path,
		private readonly array $header,
		private readonly array $sections,
		private readonly int $base,
	) {}

	public function __destruct() {
		foreach ( [ $this->entries, $this->lines ] as $handle ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- seekable reads of a local file.
			}
		}
	}

	/** The index of a file, built when the sidecar is missing or stale. */
	public static function open( string $path ): self {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new MigrationError( 'file_missing', sprintf( 'The file %s does not exist or cannot be read.', $path ) );
		}
		clearstatcache( true, $path );
		$sidecar = $path . '.index';
		if ( is_file( $sidecar ) ) {
			$handle = fopen( $sidecar, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- seekable reads of a local file.
			$first  = $handle === false ? false : fgets( $handle );
			if ( $handle !== false ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- seekable reads of a local file.
			}
			$meta = is_string( $first ) ? json_decode( $first, true ) : null;
			if ( is_array( $meta ) && ( $meta['version'] ?? 0 ) === self::VERSION && ( $meta['size'] ?? -1 ) === filesize( $path ) && ( $meta['mtime'] ?? -1 ) === filemtime( $path ) && is_array( $meta['header'] ?? null ) && is_array( $meta['sections'] ?? null ) ) {
				/** @var array<string,array{0:int,1:int}> $sections */
				$sections = $meta['sections'];
				return new self( $path, $meta['header'], $sections, strlen( (string) $first ) );
			}
		}
		return self::build( $path );
	}

	/** Reads every line once, checks it and writes the sidecar. */
	public static function build( string $path ): self {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed read of a local file.
		if ( $handle === false ) {
			throw new MigrationError( 'file_missing', sprintf( 'The file %s cannot be read.', $path ) );
		}
		$header = null;
		/** @var array<string,string> $packed */
		$packed = [];
		/** @var array<string,int> $last */
		$last   = [];
		$sorted = [];
		$number = 0;
		try {
			while ( true ) {
				$offset = (int) ftell( $handle );
				$line   = fgets( $handle );
				if ( $line === false ) {
					break;
				}
				++$number;
				if ( trim( $line ) === '' ) {
					continue;
				}
				$row = json_decode( $line, true );
				if ( ! is_array( $row ) ) {
					throw self::invalid( $number, 'not a JSON object' );
				}
				if ( $header === null ) {
					if ( ( $row['type'] ?? null ) !== 'header' || ! in_array( $row['system'] ?? null, [ 'wc', 'fxc' ], true ) || ! is_string( $row['format_version'] ?? null ) || ! is_array( $row['site'] ?? null ) || ! is_string( $row['site']['id'] ?? null ) ) {
						throw self::invalid( $number, 'the first line must be the export header' );
					}
					$header = $row;
					continue;
				}
				[ $section, $id ] = self::locate( $row, $number );
				if ( ! isset( $packed[ $section ] ) ) {
					$packed[ $section ] = '';
					$sorted[ $section ] = true;
				} elseif ( $id === $last[ $section ] ) {
					throw self::invalid( $number, sprintf( 'the id %s appears twice', (string) $row['id'] ) );
				} elseif ( $id < $last[ $section ] ) {
					$sorted[ $section ] = false;
				}
				$last[ $section ]    = $id;
				$packed[ $section ] .= pack( 'J2', $id, $offset );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed read of a local file.
		}
		if ( $header === null ) {
			throw new MigrationError( 'invalid_file', 'The file is empty.' );
		}

		$sections = [];
		$body     = '';
		$start    = 0;
		foreach ( $packed as $section => $entries ) {
			if ( ! $sorted[ $section ] ) {
				$entries = self::sort( $section, $entries );
			}
			$count                = intdiv( strlen( $entries ), self::ENTRY );
			$sections[ $section ] = [ $start, $count ];
			$start               += $count;
			$body                .= $entries;
		}
		clearstatcache( true, $path );
		$meta = [
			'version'  => self::VERSION,
			'size'     => filesize( $path ),
			'mtime'    => filemtime( $path ),
			'header'   => $header,
			'sections' => $sections,
		];
		$json = (string) wp_json_encode( $meta );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- sidecar next to a file WP-CLI was given.
		if ( file_put_contents( $path . '.index', $json . "\n" . $body ) === false ) {
			throw new MigrationError( 'index_failed', sprintf( 'Could not write the index %s.index. The folder must be writable.', $path ) );
		}
		return new self( $path, $header, $sections, strlen( $json ) + 1 );
	}

	/** @return array<string,mixed> */
	public function header(): array {
		return $this->header;
	}

	/** 'wc' or 'fxc', the store the file was exported from. */
	public function system(): string {
		return (string) $this->header['system'];
	}

	/** remote_system of the id map rows of jobs reading this file: one key per source site and store. */
	public function id_system(): string {
		$site = is_array( $this->header['site'] ?? null ) ? (string) ( $this->header['site']['id'] ?? '' ) : '';
		return $this->system() . ':' . substr( hash( 'sha256', $site ), 0, 24 );
	}

	public function path(): string {
		return $this->path;
	}

	public function count( string $section ): int {
		return $this->sections[ $section ][1] ?? 0;
	}

	public function last_id( string $section ): int {
		$count = $this->count( $section );
		return $count === 0 ? 0 : $this->entry( $section, $count - 1 )[0];
	}

	/** @return list<int> ids after `$after`, up to `$upper`, ascending */
	public function ids( string $section, int $after, int $upper, int $limit ): array {
		$count = $this->count( $section );
		$low   = 0;
		$high  = $count;
		while ( $low < $high ) {
			$mid = intdiv( $low + $high, 2 );
			if ( $this->entry( $section, $mid )[0] <= $after ) {
				$low = $mid + 1;
			} else {
				$high = $mid;
			}
		}
		$out = [];
		$end = min( $count, $low + $limit );
		for ( $i = $low; $i < $end; $i++ ) {
			$id = $this->entry( $section, $i )[0];
			if ( $id > $upper ) {
				break;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * The decoded line of an id, null when the file has none.
	 *
	 * @return array<string,mixed>|null
	 */
	public function line( string $section, int $id ): ?array {
		$low  = 0;
		$high = $this->count( $section ) - 1;
		while ( $low <= $high ) {
			$mid                = intdiv( $low + $high, 2 );
			[ $found, $offset ] = $this->entry( $section, $mid );
			if ( $found === $id ) {
				return $this->read_at( $offset );
			}
			if ( $found < $id ) {
				$low = $mid + 1;
			} else {
				$high = $mid - 1;
			}
		}
		return null;
	}

	/** @return array{0:int,1:int} id and line offset */
	private function entry( string $section, int $position ): array {
		if ( $this->entries === null ) {
			$handle = fopen( $this->path . '.index', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- seekable reads of a local file.
			if ( $handle === false ) {
				throw new MigrationError( 'index_failed', sprintf( 'The index %s.index cannot be read.', $this->path ) );
			}
			$this->entries = $handle;
		}
		fseek( $this->entries, $this->base + ( $this->sections[ $section ][0] + $position ) * self::ENTRY );
		$bytes = (string) fread( $this->entries, self::ENTRY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- seekable reads of a local file.
		$pair  = strlen( $bytes ) === self::ENTRY ? unpack( 'J2', $bytes ) : false;
		if ( $pair === false ) {
			throw new MigrationError( 'index_failed', sprintf( 'The index %s.index is damaged. Delete it to build it again.', $this->path ) );
		}
		return [ (int) $pair[1], (int) $pair[2] ];
	}

	/** @return array<string,mixed>|null */
	private function read_at( int $offset ): ?array {
		if ( $this->lines === null ) {
			$handle = fopen( $this->path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- seekable reads of a local file.
			if ( $handle === false ) {
				throw new MigrationError( 'file_missing', sprintf( 'The file %s cannot be read.', $this->path ) );
			}
			$this->lines = $handle;
		}
		fseek( $this->lines, $offset );
		$line = fgets( $this->lines );
		$row  = is_string( $line ) ? json_decode( $line, true ) : null;
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Section and numeric id of a line.
	 *
	 * @param array<array-key,mixed> $row
	 * @return array{0:string,1:int}
	 */
	private static function locate( array $row, int $number ): array {
		$type = $row['type'] ?? null;
		$id   = $row['id'] ?? null;
		if ( ! is_string( $type ) || ! is_string( $id ) || ! is_array( $row['data'] ?? null ) ) {
			throw self::invalid( $number, 'a line needs a type, an id and data' );
		}
		$section = in_array( $type, [ self::USER, self::MEDIA ], true ) ? $type : Job::stage_of( $type );
		if ( $section === null ) {
			throw self::invalid( $number, sprintf( 'unknown record type "%s"', $type ) );
		}
		$numeric = Ref::int_id( $id );
		if ( $numeric <= 0 ) {
			throw self::invalid( $number, sprintf( 'the id "%s" does not end in a number', $id ) );
		}
		return [ $section, $numeric ];
	}

	private static function sort( string $section, string $entries ): string {
		$pairs = [];
		foreach ( str_split( $entries, self::ENTRY ) as $entry ) {
			$pair                    = (array) unpack( 'J2', $entry );
			$pairs[ (int) $pair[1] ] = (int) $pair[2];
		}
		if ( count( $pairs ) * self::ENTRY !== strlen( $entries ) ) {
			throw new MigrationError( 'invalid_file', sprintf( 'An id appears twice in the %s section.', $section ) );
		}
		ksort( $pairs );
		$out = '';
		foreach ( $pairs as $id => $offset ) {
			$out .= pack( 'J2', $id, $offset );
		}
		return $out;
	}

	private static function invalid( int $number, string $reason ): MigrationError {
		return new MigrationError( 'invalid_file', sprintf( 'Line %1$d of the file is invalid: %2$s.', $number, $reason ) );
	}
}
