<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront;

use Flexa\Commerce\Catalog\Downloads;
use Flexa\Commerce\Concerns\HasInstance;
use Flexa\Commerce\Downloads\DownloadDenied;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Serves `?fxc_download=<token>` (ADR-058). The token is the secret, so the
 * link works from an email without signing in unless a site asks for it
 * with `flexacommerce.downloads.require_login`. Local files go through PHP
 * in chunks, or to the web server with X-Sendfile and its relatives; remote
 * files always redirect. A request for the rest of a file (a range that
 * does not start at 0) is not counted as another download.
 */
final class DownloadHandler {
	use HasInstance;

	/** Literal in Plugin::boot(), which must not load this class on other requests. */
	public const PARAM = 'fxc_download';

	/** Uploads subfolder for files uploaded from the product editor. */
	public const FOLDER = 'flexacommerce_uploads';

	private const CHUNK = 1048576;

	/**
	 * `upload_dir` filter for uploads from the product editor's file picker:
	 * they go to a folder with a deny rule and no index (ADR-058).
	 *
	 * @param array<string,mixed> $dirs
	 * @return array<string,mixed>
	 */
	public static function upload_dir( array $dirs ): array {
		$sub            = '/' . self::FOLDER . (string) ( $dirs['subdir'] ?? '' );
		$dirs['path']   = (string) $dirs['basedir'] . $sub;
		$dirs['url']    = (string) $dirs['baseurl'] . $sub;
		$dirs['subdir'] = $sub;
		self::protect( (string) $dirs['basedir'] . '/' . self::FOLDER );
		return $dirs;
	}

	/** Writes the deny rule and an empty index once. */
	public static function protect( string $dir ): void {
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$files = [
			'.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'index.html' => '',
		];
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- two small files in the uploads folder.
			}
		}
	}

	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential; links come from emails.
		$token = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::PARAM ] ) ) : '';
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_RANGE'] ) ) : '';
		$plan  = $this->plan( $token, get_current_user_id(), $range );
		nocache_headers();
		if ( isset( $plan['error'] ) ) {
			wp_die( esc_html( $plan['error'] ), esc_html__( 'Download', 'flexacommerce' ), [ 'response' => (int) $plan['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an HTTP status code.
		}
		$this->deliver( $plan );
		exit;
	}

	/**
	 * What to send for a link, without sending it, so tests can check it.
	 *
	 * @return array{status:int,error:string}|array{method:string,url?:string,path?:string,name:string,range:?array{0:int,1:int}}
	 */
	public function plan( string $token, int $user_id, string $range = '' ): array {
		$service = Plugin::instance()->downloads();
		try {
			$permission = $service->resolve( $token, $user_id );
			$file       = $service->file( $permission );
			$source     = Downloads::source( (string) ( $file['file'] ?? '' ) );
			if ( $source['kind'] === 'invalid' ) {
				throw new DownloadDenied( __( 'This file is no longer available.', 'flexacommerce' ), 404 );
			}
			$size  = $source['kind'] === 'local' ? (int) filesize( $source['path'] ) : 0;
			$bytes = $source['kind'] === 'local' ? self::range( $range, $size ) : null;
			$service->record( $permission, $bytes === null || $bytes[0] === 0 );
		} catch ( DownloadDenied $e ) {
			return [
				'status' => $e->getCode() > 0 ? $e->getCode() : 403,
				'error'  => $e->getMessage(),
			];
		}
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( $source['kind'] === 'remote' ) {
			return [
				'method' => 'redirect',
				'url'    => $source['url'],
				'name'   => $name,
				'range'  => null,
			];
		}
		$method = Settings::string( 'download_method' );
		if ( $method === 'redirect' ) {
			// Files uploaded for sale sit in a folder the web server refuses, so a redirect cannot reach them.
			$url = str_contains( $source['path'], DIRECTORY_SEPARATOR . self::FOLDER . DIRECTORY_SEPARATOR ) ? null : self::path_to_url( $source['path'] );
			if ( $url !== null ) {
				return [
					'method' => 'redirect',
					'url'    => $url,
					'name'   => $name,
					'range'  => null,
				];
			}
			$method = 'force';
		}
		$ext = pathinfo( $source['path'], PATHINFO_EXTENSION );
		return [
			'method' => $method === 'xsendfile' ? 'xsendfile' : 'force',
			'path'   => $source['path'],
			'name'   => $name !== '' ? ( pathinfo( $name, PATHINFO_EXTENSION ) === '' && $ext !== '' ? $name . '.' . $ext : $name ) : wp_basename( $source['path'] ),
			'range'  => $bytes,
		];
	}

	/** @param array{method:string,url?:string,path?:string,name:string,range:?array{0:int,1:int}} $plan */
	public function deliver( array $plan ): void {
		if ( $plan['method'] === 'redirect' ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the merchant chose this file URL.
			wp_redirect( (string) ( $plan['url'] ?? '' ), 302 );
			return;
		}
		$path = (string) ( $plan['path'] ?? '' );
		$size = (int) filesize( $path );
		$type = wp_check_filetype( $path )['type'];
		if ( headers_sent() ) {
			return;
		}
		header( 'Content-Type: ' . ( is_string( $type ) && $type !== '' ? $type : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $plan['name'] ) . '"; filename*=UTF-8\'\'' . rawurlencode( $plan['name'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		if ( $plan['method'] === 'xsendfile' && $this->server_sends( $path ) ) {
			return;
		}
		header( 'Accept-Ranges: bytes' );
		[ $start, $end ] = $plan['range'] ?? [ 0, max( 0, $size - 1 ) ];
		if ( $plan['range'] !== null ) {
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}
		header( 'Content-Length: ' . ( $size > 0 ? $end - $start + 1 : 0 ) );
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- compression would break ranges; not every Apache allows it.
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed in chunks; WP_Filesystem reads whole files.
		if ( $handle === false || $size === 0 ) {
			return;
		}
		fseek( $handle, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $handle ) && connection_status() === CONNECTION_NORMAL ) {
			$chunk = fread( $handle, min( self::CHUNK, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see fopen.
			if ( $chunk === false ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file bytes.
			flush();
			$left -= strlen( $chunk );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen.
	}

	/**
	 * One `bytes=a-b` range of a file, or null for the whole file. Several
	 * ranges or an unreadable header also mean the whole file.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function range( string $header, int $size ): ?array {
		if ( $size <= 0 || preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $m ) !== 1 || ( $m[1] === '' && $m[2] === '' ) ) {
			return null;
		}
		if ( $m[1] === '' ) {
			$start = max( 0, $size - (int) $m[2] );
			$end   = $size - 1;
		} else {
			$start = (int) $m[1];
			$end   = $m[2] === '' ? $size - 1 : min( (int) $m[2], $size - 1 );
		}
		return $start <= $end && $start < $size ? [ $start, $end ] : null;
	}

	/** Hands the file to the web server; false when the server is not known to support it. */
	private function server_sends( string $path ): bool {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		if ( str_contains( $software, 'nginx' ) ) {
			$url = self::path_to_url( $path );
			if ( $url === null ) {
				return false;
			}
			header( 'X-Accel-Redirect: ' . (string) wp_parse_url( $url, PHP_URL_PATH ) );
			return true;
		}
		if ( str_contains( $software, 'litespeed' ) ) {
			$url = self::path_to_url( $path );
			if ( $url === null ) {
				return false;
			}
			header( 'X-LiteSpeed-Location: ' . (string) wp_parse_url( $url, PHP_URL_PATH ) );
			return true;
		}
		if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_xsendfile', (array) apache_get_modules(), true ) ) {
			header( 'X-Sendfile: ' . $path );
			return true;
		}
		return false;
	}

	/** The public URL of a file under the uploads folder, or null. */
	private static function path_to_url( string $path ): ?string {
		$uploads = wp_get_upload_dir();
		$base    = realpath( (string) $uploads['basedir'] );
		if ( $base === false || ! str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $base ) + 1 ) );
		return rtrim( (string) $uploads['baseurl'], '/' ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}
}
