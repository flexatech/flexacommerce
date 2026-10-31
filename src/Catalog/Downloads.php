<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;

defined( 'ABSPATH' ) || exit;

/**
 * The files a downloadable variant sells (ADR-058), kept as JSON in
 * fxc_variants.downloads. Each file has a stable id that order permissions
 * point at, the name the customer sees and a reference: a URL or an
 * absolute path. A local file must sit under an allowed folder (the uploads
 * folder unless `flexacommerce.downloads.allowed_paths` adds more) and have
 * a file type WordPress allows; any other URL is remote and the download
 * link redirects to it. `limit` counts downloads per bought unit; it and
 * `expiry_days` are null for no limit.
 */
final class Downloads {
	public const MAX_FILES = 50;

	private const ID = '/^[A-Za-z0-9_-]{1,64}$/';

	/** @param list<array{id:string,name:string,file:string}> $files */
	public function __construct(
		public readonly array $files = [],
		public readonly ?int $limit = null,
		public readonly ?int $expiry_days = null,
	) {}

	/** Stored JSON, read leniently: entries without an id or a file are skipped. */
	public static function from_json( mixed $json ): self {
		if ( ! is_string( $json ) || $json === '' ) {
			return new self();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? self::from_array( $data ) : new self();
	}

	/** @param array<array-key,mixed> $data */
	public static function from_array( array $data ): self {
		$files = [];
		foreach ( is_array( $data['files'] ?? null ) ? $data['files'] : [] as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) || ! is_string( $entry['file'] ?? null ) || $entry['file'] === '' ) {
				continue;
			}
			$files[] = [
				'id'   => $entry['id'],
				'name' => is_string( $entry['name'] ?? null ) ? $entry['name'] : '',
				'file' => $entry['file'],
			];
		}
		return new self( $files, self::positive( $data['limit'] ?? null ), self::positive( $data['expiry_days'] ?? null ) );
	}

	/**
	 * The admin payload: `{files:[{id?,name,file}], limit, expiry_days}`.
	 * A file without an id gets a new one; a name defaults to the file name.
	 *
	 * @throws InvalidProduct
	 */
	public static function parse( mixed $value, string $path ): self {
		if ( $value === null || $value === [] ) {
			return new self();
		}
		if ( ! is_array( $value ) ) {
			throw new InvalidProduct( __( 'Downloads must be an object.', 'flexacommerce' ), $path );
		}
		$entries = is_array( $value['files'] ?? null ) ? array_values( $value['files'] ) : [];
		if ( count( $entries ) > self::MAX_FILES ) {
			throw new InvalidProduct( __( 'A product can have at most 50 downloadable files.', 'flexacommerce' ), $path . '.files' );
		}
		$files = [];
		$seen  = [];
		foreach ( $entries as $i => $entry ) {
			$at = $path . '.files.' . $i;
			if ( ! is_array( $entry ) ) {
				throw new InvalidProduct( __( 'Choose a file or enter its URL.', 'flexacommerce' ), $at . '.file' );
			}
			$file = is_scalar( $entry['file'] ?? null ) ? trim( (string) $entry['file'] ) : '';
			if ( $file === '' ) {
				throw new InvalidProduct( __( 'Choose a file or enter its URL.', 'flexacommerce' ), $at . '.file' );
			}
			$problem = self::problem( $file );
			if ( $problem !== null ) {
				throw new InvalidProduct( $problem, $at . '.file' );
			}
			$id = is_string( $entry['id'] ?? null ) && preg_match( self::ID, $entry['id'] ) === 1 ? $entry['id'] : wp_generate_uuid4();
			if ( isset( $seen[ $id ] ) ) {
				$id = wp_generate_uuid4();
			}
			$seen[ $id ] = true;
			$name        = is_scalar( $entry['name'] ?? null ) ? trim( sanitize_text_field( (string) $entry['name'] ) ) : '';
			$files[]     = [
				'id'   => $id,
				'name' => $name !== '' ? mb_substr( $name, 0, 200 ) : self::basename( $file ),
				'file' => $file,
			];
		}
		return new self( $files, self::limit( $value['limit'] ?? null, $path . '.limit' ), self::limit( $value['expiry_days'] ?? null, $path . '.expiry_days' ) );
	}

	public function is_empty(): bool {
		return $this->files === [];
	}

	/** @return array{id:string,name:string,file:string}|null */
	public function file( string $id ): ?array {
		foreach ( $this->files as $file ) {
			if ( $file['id'] === $id ) {
				return $file;
			}
		}
		return null;
	}

	/** @return list<string> */
	public function ids(): array {
		return array_column( $this->files, 'id' );
	}

	public function to_json(): ?string {
		if ( $this->files === [] && $this->limit === null && $this->expiry_days === null ) {
			return null;
		}
		return (string) wp_json_encode( $this->to_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/** @return array{files:list<array{id:string,name:string,file:string}>,limit:?int,expiry_days:?int} */
	public function to_array(): array {
		return [
			'files'       => $this->files,
			'limit'       => $this->limit,
			'expiry_days' => $this->expiry_days,
		];
	}

	/** Why a file reference cannot be sold, or null when it can. */
	public static function problem( string $file ): ?string {
		$source = self::source( $file );
		if ( $source['kind'] === 'remote' ) {
			return filter_var( $file, FILTER_VALIDATE_URL ) === false ? __( 'The file URL is not valid.', 'flexacommerce' ) : null;
		}
		return $source['kind'] === 'local' ? null : $source['error'];
	}

	/**
	 * Where a file reference leads: a readable local file under an allowed
	 * folder, a remote URL, or the reason it is refused.
	 *
	 * @return array{kind:'local',path:string}|array{kind:'remote',url:string}|array{kind:'invalid',error:string}
	 */
	public static function source( string $file ): array {
		$file = trim( $file );
		$path = null;
		if ( preg_match( '#^https?://#i', $file ) === 1 ) {
			$path = self::url_to_path( $file );
			if ( $path === null ) {
				return [
					'kind' => 'remote',
					'url'  => $file,
				];
			}
		} elseif ( str_starts_with( $file, '/' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', $file ) === 1 ) {
			$path = $file;
		} else {
			return [
				'kind'  => 'invalid',
				'error' => __( 'Enter a full URL starting with https:// or choose a file from the media library.', 'flexacommerce' ),
			];
		}
		$real = realpath( $path );
		if ( $real === false || ! is_file( $real ) || ! is_readable( $real ) ) {
			return [
				'kind'  => 'invalid',
				'error' => __( 'The file does not exist on this server.', 'flexacommerce' ),
			];
		}
		if ( ! self::is_allowed( $real ) ) {
			return [
				'kind'  => 'invalid',
				'error' => __( 'Files can only be sold from the uploads folder.', 'flexacommerce' ),
			];
		}
		$type = wp_check_filetype( $real );
		if ( $type['ext'] === false ) {
			return [
				'kind'  => 'invalid',
				'error' => __( 'WordPress does not allow this file type.', 'flexacommerce' ),
			];
		}
		return [
			'kind' => 'local',
			'path' => $real,
		];
	}

	/** @return list<string> folders local files may be served from, resolved, with a trailing separator */
	public static function allowed_paths(): array {
		$uploads = wp_get_upload_dir();
		/**
		 * Folders downloadable files may be served from. Add a folder outside
		 * the web root to keep files away from direct links.
		 *
		 * @param list<string> $paths
		 */
		$paths = (array) apply_filters( 'flexacommerce.downloads.allowed_paths', [ (string) $uploads['basedir'] ] );
		$out   = [];
		foreach ( $paths as $path ) {
			$real = $path !== '' ? realpath( $path ) : false;
			if ( $real !== false ) {
				$out[] = rtrim( $real, '/\\' ) . DIRECTORY_SEPARATOR;
			}
		}
		return $out;
	}

	public static function basename( string $file ): string {
		$path = (string) wp_parse_url( $file, PHP_URL_PATH );
		$name = rawurldecode( wp_basename( $path !== '' ? $path : $file ) );
		return $name !== '' ? $name : $file;
	}

	private static function is_allowed( string $real ): bool {
		foreach ( self::allowed_paths() as $root ) {
			if ( str_starts_with( $real, $root ) ) {
				return true;
			}
		}
		return false;
	}

	/** A URL on this site, as a path; null for another host. */
	private static function url_to_path( string $url ): ?string {
		$bare    = static fn ( string $value ): string => strtolower( (string) preg_replace( '#^https?:#i', '', $value ) );
		$address = strtok( $url, '?#' );
		$address = $address === false ? $url : $address;
		$uploads = wp_get_upload_dir();
		foreach ( [
			(string) $uploads['baseurl'] => (string) $uploads['basedir'],
			site_url()                   => ABSPATH,
		] as $base => $dir ) {
			$prefix = rtrim( $bare( $base ), '/' ) . '/';
			if ( $base !== '' && str_starts_with( strtolower( $bare( $address ) ), $prefix ) ) {
				return rtrim( $dir, '/\\' ) . '/' . rawurldecode( substr( (string) preg_replace( '#^https?:#i', '', $address ), strlen( $prefix ) ) );
			}
		}
		return null;
	}

	private static function positive( mixed $value ): ?int {
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	/** @throws InvalidProduct */
	private static function limit( mixed $value, string $path ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}
		if ( ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) || (int) $value > 100000 ) {
			throw new InvalidProduct( __( 'Enter a whole number, or leave it empty for no limit.', 'flexacommerce' ), $path );
		}
		return (int) $value > 0 ? (int) $value : null;
	}
}
