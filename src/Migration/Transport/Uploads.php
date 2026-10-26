<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Transport;

use Flexa\Commerce\Migration\MigrationError;

defined( 'ABSPATH' ) || exit;

/**
 * Export files sent from the Migration screen (doc 05 §4.6), stored in
 * `uploads/flexacommerce-imports`. A file arrives in chunks, so its size is
 * not bounded by `upload_max_filesize`: `begin` reserves a random token,
 * `append` adds one chunk at the offset the file has reached, `finish`
 * renames the part to `<token>.jsonl`. The folder refuses web requests where
 * the server reads `.htaccess`; elsewhere the 128-bit names keep it private.
 * An unfinished part is deleted after a day. Maintenance\Eraser deletes the
 * folder.
 */
final class Uploads {
	public const FOLDER = 'flexacommerce-imports';

	/** Largest chunk the screen sends, below the request size limit. */
	public const CHUNK = 8388608;

	private const STALE = DAY_IN_SECONDS;

	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( (string) $uploads['basedir'] ) . self::FOLDER;
	}

	/** Bytes per chunk for this server: under post_max_size, at most CHUNK. */
	public static function chunk(): int {
		return (int) max( 262144, min( self::CHUNK, wp_max_upload_size() - 65536 ) );
	}

	/**
	 * Reserves a token for a file of `$size` bytes.
	 *
	 * @return array{token:string,chunk:int}
	 */
	public function begin( string $name, int $size, int $user_id ): array {
		if ( $size <= 0 ) {
			throw new MigrationError( 'invalid_file', 'The file is empty.' );
		}
		$dir = self::prepare();
		self::clean( $dir );
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
		// The import also writes the index, about 16 bytes per line.
		if ( is_float( $free ) && $free < $size * 1.1 ) {
			throw new MigrationError( 'disk_full', sprintf( 'The server has %1$s free, the file needs %2$s.', size_format( (int) floor( $free ) ), size_format( $size ) ) );
		}
		$token = bin2hex( random_bytes( 16 ) );
		if ( file_put_contents( self::part( $token ), '' ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- chunked upload into our folder.
			throw new MigrationError( 'file_unwritable', sprintf( 'Could not write to %s.', $dir ) );
		}
		$meta = [
			'name' => sanitize_file_name( $name ),
			'size' => $size,
			'user' => $user_id,
		];
		file_put_contents( self::meta_path( $token ), (string) wp_json_encode( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- chunked upload into our folder.
		return [
			'token' => $token,
			'chunk' => self::chunk(),
		];
	}

	/**
	 * Adds a chunk that starts at `$offset`; returns the bytes received so far.
	 * A chunk at the wrong offset is refused with the size reached, so the
	 * screen can resend from there.
	 */
	public function append( string $token, int $offset, string $bytes, int $user_id ): int {
		$meta = $this->meta( $token, $user_id );
		$part = self::part( $token );
		clearstatcache( true, $part );
		$have = (int) filesize( $part );
		if ( $offset !== $have ) {
			throw new MigrationError( 'upload_offset', (string) $have );
		}
		if ( $have + strlen( $bytes ) > $meta['size'] ) {
			throw new MigrationError( 'invalid_file', 'The upload is larger than the file it announced.' );
		}
		$written = file_put_contents( $part, $bytes, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- chunked upload into our folder.
		if ( $written !== strlen( $bytes ) ) {
			throw new MigrationError( 'file_unwritable', 'Could not save the chunk. The disk may be full.' );
		}
		return $have + $written;
	}

	/**
	 * The finished file, renamed from its part.
	 *
	 * @return array{path:string,name:string}
	 */
	public function finish( string $token, int $user_id ): array {
		$meta = $this->meta( $token, $user_id );
		$part = self::part( $token );
		clearstatcache( true, $part );
		if ( (int) filesize( $part ) !== $meta['size'] ) {
			throw new MigrationError( 'upload_incomplete', 'The upload has not finished.' );
		}
		$path = self::dir() . '/' . $token . '.jsonl';
		if ( ! rename( $part, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- finished upload in our folder.
			throw new MigrationError( 'file_unwritable', 'Could not save the uploaded file.' );
		}
		wp_delete_file( self::meta_path( $token ) );
		return [
			'path' => $path,
			'name' => $meta['name'],
		];
	}

	/** Drops an unfinished upload. */
	public function abort( string $token, int $user_id ): void {
		$this->meta( $token, $user_id );
		wp_delete_file( self::part( $token ) );
		wp_delete_file( self::meta_path( $token ) );
	}

	/** Whether `$path` is a file this class stored, the only kind the screen may delete. */
	public static function owns( string $path ): bool {
		$dir  = realpath( self::dir() );
		$real = realpath( $path );
		return $dir !== false && $real !== false && dirname( $real ) === $dir && preg_match( '/^[a-f0-9]{32}\.jsonl$/', basename( $real ) ) === 1;
	}

	/** Deletes an uploaded file and its index. */
	public static function remove( string $path ): bool {
		if ( ! self::owns( $path ) ) {
			return false;
		}
		wp_delete_file( $path . '.index' );
		wp_delete_file( $path );
		return ! is_file( $path );
	}

	/** @return array{name:string,size:int,user:int} */
	private function meta( string $token, int $user_id ): array {
		if ( preg_match( '/^[a-f0-9]{32}$/', $token ) !== 1 || ! is_file( self::part( $token ) ) ) {
			throw new MigrationError( 'not_found', 'This upload does not exist or has expired.' );
		}
		$raw  = file_get_contents( self::meta_path( $token ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$meta = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $meta ) || (int) ( $meta['user'] ?? 0 ) !== $user_id ) {
			throw new MigrationError( 'not_found', 'This upload does not exist or has expired.' );
		}
		return [
			'name' => (string) ( $meta['name'] ?? '' ),
			'size' => (int) ( $meta['size'] ?? 0 ),
			'user' => $user_id,
		];
	}

	private static function part( string $token ): string {
		return self::dir() . '/' . $token . '.part';
	}

	private static function meta_path( string $token ): string {
		return self::dir() . '/' . $token . '.part.json';
	}

	/** The folder, with the files that keep web requests out. */
	private static function prepare(): string {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new MigrationError( 'file_unwritable', sprintf( 'Could not create %s.', $dir ) );
		}
		$guards = [
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		];
		foreach ( $guards as $name => $body ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- guard files of our folder.
			}
		}
		return $dir;
	}

	/** Deletes parts left by uploads that never finished. */
	private static function clean( string $dir ): void {
		foreach ( (array) glob( $dir . '/*.part*' ) as $file ) {
			if ( is_string( $file ) && (int) filemtime( $file ) < time() - self::STALE ) {
				wp_delete_file( $file );
			}
		}
	}
}
